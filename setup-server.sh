#!/bin/bash
# =============================================================================
# PocketDev Setup - Server Installation
# =============================================================================
#
# One-liner setup for PocketDev on an EXISTING server.
# Run this directly ON your server (via SSH or console).
#
# USAGE:
#   curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/setup-server.sh | bash
#
# WITH DOMAIN PRE-SET:
#   PD_DOMAIN=pocketdev.example.com curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/setup-server.sh | bash
#
# WHAT IT DOES:
#   1. Configures DNS (TransIP automated or manual)
#   2. Runs vps-setup (Docker, proxy-nginx, Tailscale, SSH hardening)
#   3. Installs PocketDev with wildcard SSL certificate
#
# REQUIREMENTS:
#   - Fresh Ubuntu/Debian server
#   - Root access
#   - Tailscale account (free at tailscale.com)
#   - Domain name (TransIP strongly recommended for automatic SSL renewal)
#
# DON'T HAVE A SERVER YET? Use setup-cloud.sh instead:
#   curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/setup-cloud.sh | bash
#
# =============================================================================

set -euo pipefail

# -----------------------------------------------------------------------------
# Colors and logging
# -----------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} ${1:-}"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} ${1:-}"; }
log_error() { echo -e "${RED}[ERROR]${NC} ${1:-}"; }
log_step() { echo -e "\n${BLUE}==>${NC} ${BOLD}${1:-}${NC}"; }

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
# Branch overrides for testing (default to main)
VPS_SETUP_BRANCH="${VPS_SETUP_BRANCH:-main}"
POCKETDEV_BRANCH="${POCKETDEV_BRANCH:-main}"
VPS_SETUP_URL="https://raw.githubusercontent.com/tetrixdev/vps-setup/$VPS_SETUP_BRANCH"
POCKETDEV_URL="https://raw.githubusercontent.com/tetrixdev/pocket-dev/$POCKETDEV_BRANCH"

# Domain can be pre-set via environment variable
DOMAIN_NAME="${PD_DOMAIN:-}"

# TransIP credentials (can be pre-set by setup-cloud.sh)
TRANSIP_LOGIN=""
TRANSIP_PRIVATE_KEY=""
USE_TRANSIP=false

# Check if credentials were pre-transferred by setup-cloud.sh
if [ "${PD_USE_TRANSIP:-}" = "1" ] && [ -f /etc/pocketdev/transip_login ] && [ -f /etc/pocketdev/transip_key ]; then
    TRANSIP_LOGIN=$(cat /etc/pocketdev/transip_login)
    TRANSIP_PRIVATE_KEY=$(cat /etc/pocketdev/transip_key)
    USE_TRANSIP=true
    log_info "Using pre-configured TransIP credentials"
fi

# =============================================================================
# Pre-flight checks
# =============================================================================
log_step "Pre-flight Checks"

# Must run as root
if [ "$EUID" -ne 0 ]; then
    log_error "This script must be run as root"
    echo "Try: sudo bash or login as root"
    exit 1
fi

# Check for curl
if ! command -v curl &>/dev/null; then
    log_info "Installing curl..."
    apt-get update -qq && apt-get install -y -qq curl
fi

# Get server IP
SERVER_IP=$(curl -sf https://ipinfo.io/ip 2>/dev/null || curl -sf https://ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')
log_info "Server IP: $SERVER_IP"

# =============================================================================
# HEADER
# =============================================================================
echo ""
echo -e "${CYAN}"
echo "============================================================================="
echo "  PocketDev Server Setup"
echo "============================================================================="
echo -e "${NC}"
echo "This script will configure your server with:"
echo "  - Docker with log rotation"
echo "  - Tailscale (secure SSH access)"
echo "  - proxy-nginx (reverse proxy with SSL)"
echo "  - PocketDev (AI development environment)"
echo ""
echo "Time required: ~5-10 minutes"
echo ""

# =============================================================================
# STEP 1: Domain Configuration
# =============================================================================
log_step "Step 1/5: Domain Configuration"

if [ -z "$DOMAIN_NAME" ]; then
    echo ""
    echo "Enter the BASE domain for PocketDev (e.g., dev.example.com)"
    echo ""
    echo -e "${CYAN}How this works:${NC}"
    echo "  This domain becomes your private development zone:"
    echo ""
    echo "  • PocketDev: https://dev.example.com (always private)"
    echo "  • Your apps: *.dev.example.com (can be public OR private)"
    echo ""
    echo "  Deploy apps as subdomains without additional DNS/SSL setup:"
    echo "      app1.dev.example.com, staging.dev.example.com, api.dev.example.com"
    echo ""
    echo -e "${CYAN}Public vs Private access:${NC}"
    echo "  • ${BOLD}Private${NC} = Only accessible via Tailscale VPN (your devices only)"
    echo "  • ${BOLD}Public${NC}  = Accessible from anywhere on the internet"
    echo ""
    echo "  PocketDev itself is always private. Subdomains can be either."
    echo "  Configure per-app with: docker exec proxy-nginx /scripts/domain.sh"
    echo ""
    echo -e "${YELLOW}Note: Other domains (not subdomains of this one) can be added later${NC}"
    echo -e "${YELLOW}      but will be public-only (no Tailscale restriction possible).${NC}"
    echo ""

    read -p "Base domain: " DOMAIN_NAME < /dev/tty

    if [ -z "$DOMAIN_NAME" ]; then
        log_error "Domain name is required"
        exit 1
    fi
else
    log_info "Using domain from environment: $DOMAIN_NAME"
fi

# Extract root domain from full domain
ROOT_DOMAIN=$(echo "$DOMAIN_NAME" | rev | cut -d. -f1-2 | rev)
SUBDOMAIN=$(echo "$DOMAIN_NAME" | sed "s/\.$ROOT_DOMAIN$//")
if [ "$SUBDOMAIN" = "$DOMAIN_NAME" ]; then
    SUBDOMAIN="@"
fi
WILDCARD_SUBDOMAIN="*.$SUBDOMAIN"
if [ "$SUBDOMAIN" = "@" ]; then
    WILDCARD_SUBDOMAIN="*"
fi

# =============================================================================
# STEP 2: DNS Provider Selection
# =============================================================================
log_step "Step 2/5: DNS & SSL Configuration"

# Skip this section if TransIP is already configured
if [ "$USE_TRANSIP" = true ]; then
    log_info "TransIP credentials already configured"
else

echo ""
echo -e "${YELLOW}╔════════════════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${YELLOW}║  IMPORTANT: SSL Certificate Renewal                                        ║${NC}"
echo -e "${YELLOW}╠════════════════════════════════════════════════════════════════════════════╣${NC}"
echo -e "${YELLOW}║                                                                            ║${NC}"
echo -e "${YELLOW}║  SSL certificates expire every 60-90 days and must be renewed.            ║${NC}"
echo -e "${YELLOW}║                                                                            ║${NC}"
echo -e "${YELLOW}║  • With TransIP: Fully automatic renewal (recommended)                    ║${NC}"
echo -e "${YELLOW}║  • Without TransIP: You must manually update DNS TXT records              ║${NC}"
echo -e "${YELLOW}║    every ~60 days, or your SSL will expire and HTTPS will break.          ║${NC}"
echo -e "${YELLOW}║                                                                            ║${NC}"
echo -e "${YELLOW}╚════════════════════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo "How would you like to configure DNS and SSL?"
echo ""
echo -e "  ${BOLD}${GREEN}1${NC} - ${GREEN}TransIP (RECOMMENDED)${NC}"
echo "       • Requires domain registered/managed at TransIP"
echo "       • Automatic DNS record creation"
echo "       • Automatic wildcard SSL certificate"
echo "       • Automatic SSL renewal (zero maintenance)"
echo ""
echo -e "  ${BOLD}2${NC} - Manual DNS"
echo "       • You configure DNS records yourself"
echo "       • You must update TXT records every ~60 days for SSL renewal"
echo "       • Not recommended for production use"
echo ""

read -p "DNS method [1]: " DNS_METHOD < /dev/tty
DNS_METHOD=${DNS_METHOD:-1}

if [ "$DNS_METHOD" = "1" ]; then
    echo ""
    echo -e "${CYAN}TransIP API Setup${NC}"
    echo ""
    echo -e "${YELLOW}Requirement: Your domain must be registered or have DNS managed at TransIP.${NC}"
    echo -e "${YELLOW}             If your domain is elsewhere, choose option 2 (Manual DNS).${NC}"
    echo ""
    echo "You need TWO things from TransIP:"
    echo -e "  1. Your TransIP ${BOLD}login username${NC}"
    echo -e "  2. An ${BOLD}API private key${NC} (generate at TransIP control panel)"
    echo ""
    echo -e "Generate your API key at: ${CYAN}https://www.transip.nl/cp/account/api/${NC}"
    echo ""
    echo -e "${YELLOW}Steps: Enable 'Allow API access' → Generate new key pair → Copy PRIVATE KEY${NC}"
    echo -e "${YELLOW}       (The private key starts with -----BEGIN PRIVATE KEY-----)${NC}"
    echo ""

    read -p "TransIP login username: " TRANSIP_LOGIN < /dev/tty

    if [ -z "$TRANSIP_LOGIN" ]; then
        log_warn "No TransIP login provided, falling back to manual DNS"
        DNS_METHOD="2"
    else
        echo ""
        echo "Paste your TransIP private key below."
        echo "It should start with '-----BEGIN PRIVATE KEY-----'"
        echo "Press Enter twice when done:"
        echo ""

        TRANSIP_PRIVATE_KEY=""
        while IFS= read -r line < /dev/tty; do
            [ -z "$line" ] && break
            TRANSIP_PRIVATE_KEY="${TRANSIP_PRIVATE_KEY}${line}"$'\n'
        done

        if [ -z "$TRANSIP_PRIVATE_KEY" ] || [[ ! "$TRANSIP_PRIVATE_KEY" =~ "BEGIN PRIVATE KEY" ]]; then
            log_warn "Invalid or empty private key, falling back to manual DNS"
            DNS_METHOD="2"
        else
            USE_TRANSIP=true
            log_info "TransIP credentials saved"
        fi
    fi
fi

fi  # End of "if not already configured" block

# =============================================================================
# DNS Configuration (TransIP or Manual)
# =============================================================================
if [ "$USE_TRANSIP" = true ]; then
    log_info "TransIP will be configured after Docker/proxy-nginx is installed."
    log_info "DNS TXT records will be managed automatically during SSL certificate request."
else
    # Manual DNS
    echo ""
    echo -e "${YELLOW}Manual DNS Configuration Required${NC}"
    echo ""
    echo "Please add these DNS records with your provider:"
    echo ""
    echo -e "  ${BOLD}Record 1 (Base domain):${NC}"
    echo "    Domain: $ROOT_DOMAIN"
    echo "    Type:   A"
    echo "    Name:   $SUBDOMAIN"
    echo "    Value:  $SERVER_IP"
    echo "    TTL:    300"
    echo ""
    echo -e "  ${BOLD}Record 2 (Wildcard for subdomains):${NC}"
    echo "    Domain: $ROOT_DOMAIN"
    echo "    Type:   A"
    echo "    Name:   $WILDCARD_SUBDOMAIN"
    echo "    Value:  $SERVER_IP"
    echo "    TTL:    300"
    echo ""
    echo "Example for TransIP:"
    echo "  Domains → $ROOT_DOMAIN → DNS → Add two A records as shown above"
    echo ""
    read -p "Press Enter once BOTH DNS records are configured..." < /dev/tty
fi

# Check DNS propagation for base domain
log_info "Checking DNS propagation for $DOMAIN_NAME..."
DNS_OK=false
for i in {1..30}; do
    RESOLVED_IP=$(dig +short "$DOMAIN_NAME" 2>/dev/null | head -1)
    if [ "$RESOLVED_IP" = "$SERVER_IP" ]; then
        log_info "DNS is configured correctly!"
        DNS_OK=true
        break
    fi
    echo -n "."
    sleep 2
done
echo ""

if [ "$DNS_OK" = false ] && [ "$USE_TRANSIP" = false ]; then
    log_warn "DNS not yet propagated. SSL certificate request may fail."
    log_warn "Continuing anyway - you can request SSL later."
fi

# =============================================================================
# STEP 3: Run VPS Setup
# =============================================================================
log_step "Step 3/5: VPS Setup (Docker, proxy-nginx, Tailscale)"

echo ""
echo -e "${YELLOW}IMPORTANT: You will need to authenticate with Tailscale.${NC}"
echo "When prompted, open the URL in your browser to authenticate."
echo ""
read -p "Press Enter to continue..." < /dev/tty

# Download and run vps-setup
curl -fsSL "$VPS_SETUP_URL/setup.sh" | bash

# =============================================================================
# STEP 4: Configure TransIP credentials (if enabled)
# =============================================================================
if [ "$USE_TRANSIP" = true ]; then
    log_step "Step 4/5: Configuring TransIP in proxy-nginx"

    # Save the private key to a temp file for the setup script
    echo "$TRANSIP_PRIVATE_KEY" > /tmp/transip-key.pem
    chmod 600 /tmp/transip-key.pem

    # Copy key into proxy-nginx container and configure credentials
    log_info "Setting up TransIP credentials in proxy-nginx..."
    docker cp /tmp/transip-key.pem proxy-nginx:/tmp/transip-key.pem
    docker exec proxy-nginx /scripts/transip-setup.sh setup \
        --login="$TRANSIP_LOGIN" \
        --key-file=/tmp/transip-key.pem

    # Clean up temp file
    rm -f /tmp/transip-key.pem
    docker exec proxy-nginx rm -f /tmp/transip-key.pem

    log_info "TransIP credentials configured in proxy-nginx"
else
    log_step "Step 4/5: DNS Configuration"
    log_info "Skipping TransIP DNS setup (manual mode)"
fi

# =============================================================================
# STEP 5: Install PocketDev
# =============================================================================
log_step "Step 5/5: Installing PocketDev"

# Run PocketDev installer
PD_DOMAIN="$DOMAIN_NAME" curl -fsSL "$POCKETDEV_URL/install.sh" | bash

# =============================================================================
# SSL Certificate Request
# =============================================================================
log_step "SSL Certificate Setup"

if [ "$USE_TRANSIP" = true ]; then
    log_info "Requesting wildcard SSL certificate via DNS-01 challenge..."
    echo ""
    echo "This will:"
    echo "  1. Request wildcard SSL certificate from Let's Encrypt"
    echo "  2. Automatically create/remove DNS TXT records via TransIP"
    echo "  3. Set up automatic renewal (handled by proxy-nginx's certbot cron)"
    echo ""
    echo -e "${YELLOW}Note: DNS propagation may take up to 2 minutes...${NC}"
    echo ""

    # Request wildcard certificate using proxy-nginx's built-in TransIP plugin
    docker exec proxy-nginx /scripts/transip-setup.sh wildcard --domain="$DOMAIN_NAME" || {
        log_warn "Wildcard SSL request failed. You can retry manually:"
        echo "  docker exec proxy-nginx /scripts/transip-setup.sh wildcard --domain=$DOMAIN_NAME"
    }

    # Automatic renewal is handled by proxy-nginx's existing certbot cron job
    # (runs twice daily: 0 0,12 * * * certbot renew --quiet --deploy-hook 'nginx -s reload')
    log_info "Automatic SSL renewal is handled by proxy-nginx's built-in cron job"

elif [ "$DNS_OK" = true ]; then
    log_info "Requesting SSL certificate for $DOMAIN_NAME (HTTP-01 challenge)..."
    echo ""
    echo -e "${YELLOW}Note: This is a single-domain certificate.${NC}"
    echo "For additional subdomains, you'll need to request certificates manually."
    echo ""

    docker exec -it proxy-nginx certbot --nginx -d "$DOMAIN_NAME" --non-interactive --agree-tos --register-unsafely-without-email || {
        log_warn "Automatic SSL failed. You can request manually:"
        echo "  docker exec -it proxy-nginx certbot --nginx -d $DOMAIN_NAME"
    }

    echo ""
    echo -e "${YELLOW}╔════════════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${YELLOW}║  REMINDER: Manual SSL Renewal Required                                     ║${NC}"
    echo -e "${YELLOW}╠════════════════════════════════════════════════════════════════════════════╣${NC}"
    echo -e "${YELLOW}║                                                                            ║${NC}"
    echo -e "${YELLOW}║  Your SSL certificate will expire in ~90 days.                            ║${NC}"
    echo -e "${YELLOW}║  You chose manual DNS, so automatic renewal is NOT possible.              ║${NC}"
    echo -e "${YELLOW}║                                                                            ║${NC}"
    echo -e "${YELLOW}║  To renew, you must:                                                       ║${NC}"
    echo -e "${YELLOW}║  1. Add a TXT record when prompted by certbot                             ║${NC}"
    echo -e "${YELLOW}║  2. Run: docker exec -it proxy-nginx certbot renew                        ║${NC}"
    echo -e "${YELLOW}║                                                                            ║${NC}"
    echo -e "${YELLOW}║  Consider switching to TransIP for automatic renewal.                     ║${NC}"
    echo -e "${YELLOW}║                                                                            ║${NC}"
    echo -e "${YELLOW}╚════════════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
else
    log_warn "Skipping SSL - DNS not yet propagated"
    echo ""
    echo "Once DNS is configured, request SSL with:"
    echo "  docker exec -it proxy-nginx certbot --nginx -d $DOMAIN_NAME"
    echo ""
    echo -e "${YELLOW}Note: Without TransIP, you'll need to manually renew SSL every ~60 days.${NC}"
fi

# =============================================================================
# Complete
# =============================================================================
echo ""
echo -e "${GREEN}=============================================================================${NC}"
echo -e "${GREEN}  PocketDev Setup Complete!${NC}"
echo -e "${GREEN}=============================================================================${NC}"
echo ""
echo "Your PocketDev instance is ready at:"
echo ""
echo -e "  ${CYAN}https://$DOMAIN_NAME${NC}"
echo ""
if [ "$USE_TRANSIP" = true ]; then
    echo -e "${GREEN}✓ Wildcard SSL configured${NC} - All subdomains (*.$DOMAIN_NAME) are covered"
    echo -e "${GREEN}✓ Automatic renewal enabled${NC} - SSL will renew automatically"
fi
echo ""
echo "Server details:"
echo "  IP Address: $SERVER_IP"
echo "  Base domain: $DOMAIN_NAME"
echo "  Wildcard: *.$DOMAIN_NAME"
echo "  SSH: ssh admin@<tailscale-ip>"
echo ""
echo "Next steps:"
echo "  1. Open https://$DOMAIN_NAME in your browser"
echo "  2. Follow the setup wizard to configure AI providers"
echo "  3. Start building!"
echo ""
if [ "$USE_TRANSIP" = false ]; then
    echo -e "${YELLOW}Remember: SSL renewal requires manual DNS updates every ~60 days.${NC}"
    echo -e "${YELLOW}Consider switching to TransIP for automatic renewal.${NC}"
    echo ""
fi
echo -e "${GREEN}=============================================================================${NC}"
