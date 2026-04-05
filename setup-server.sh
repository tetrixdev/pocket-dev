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
#   1. Configures DNS (TransIP or manual)
#   2. Runs vps-setup (Docker, proxy-nginx, Tailscale, SSH hardening)
#   3. Installs PocketDev with SSL
#
# REQUIREMENTS:
#   - Fresh Ubuntu/Debian server
#   - Root access
#   - Tailscale account (free at tailscale.com)
#   - Domain name pointed to this server
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
log_step "Step 1/4: Domain Configuration"

if [ -z "$DOMAIN_NAME" ]; then
    echo ""
    echo "Enter the domain name for PocketDev (e.g., pocketdev.example.com)"
    echo "This domain must be pointed to this server's IP: $SERVER_IP"
    echo ""

    read -p "Domain name: " DOMAIN_NAME < /dev/tty

    if [ -z "$DOMAIN_NAME" ]; then
        log_error "Domain name is required"
        exit 1
    fi
else
    log_info "Using domain from environment: $DOMAIN_NAME"
fi

# DNS automation option
echo ""
echo "How would you like to configure DNS?"
echo ""
echo -e "  ${BOLD}1${NC} - TransIP (automatic DNS configuration)"
echo -e "  ${BOLD}2${NC} - Manual (I'll configure DNS myself)"
echo ""

read -p "DNS method [2]: " DNS_METHOD < /dev/tty
DNS_METHOD=${DNS_METHOD:-2}

# Extract root domain from full domain
ROOT_DOMAIN=$(echo "$DOMAIN_NAME" | rev | cut -d. -f1-2 | rev)
SUBDOMAIN=$(echo "$DOMAIN_NAME" | sed "s/\.$ROOT_DOMAIN$//")
if [ "$SUBDOMAIN" = "$DOMAIN_NAME" ]; then
    SUBDOMAIN="@"
fi

if [ "$DNS_METHOD" = "1" ]; then
    echo ""
    echo "TransIP API setup:"
    echo -e "Create an API token at: ${CYAN}https://www.transip.nl/cp/account/api/${NC}"
    echo ""
    read -p "Enter TransIP API token: " TRANSIP_TOKEN < /dev/tty

    if [ -z "$TRANSIP_TOKEN" ]; then
        log_warn "No TransIP token provided, falling back to manual DNS"
        DNS_METHOD="2"
    else
        log_info "TransIP auto-configuration not yet implemented"
        log_warn "Please add this DNS record manually in TransIP:"
        echo ""
        echo "  Domain: $ROOT_DOMAIN"
        echo "  Type:   A"
        echo "  Name:   $SUBDOMAIN"
        echo "  Value:  $SERVER_IP"
        echo "  TTL:    300"
        echo ""
        read -p "Press Enter once DNS is configured..." < /dev/tty
    fi
fi

if [ "$DNS_METHOD" = "2" ]; then
    echo ""
    echo "Please configure DNS with your provider:"
    echo ""
    echo "  Domain: $ROOT_DOMAIN"
    echo "  Type:   A"
    echo "  Name:   $SUBDOMAIN"
    echo "  Value:  $SERVER_IP"
    echo "  TTL:    300"
    echo ""
    echo "Example for common providers:"
    echo "  - Cloudflare: DNS → Add record → A → $SUBDOMAIN → $SERVER_IP"
    echo "  - TransIP: Domains → $ROOT_DOMAIN → DNS → Add A record"
    echo "  - GoDaddy: DNS → Add → A → $SUBDOMAIN → $SERVER_IP"
    echo ""
    read -p "Press Enter once DNS is configured..." < /dev/tty
fi

# Check DNS propagation
log_info "Checking DNS propagation..."
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

if [ "$DNS_OK" = false ]; then
    log_warn "DNS not yet propagated. SSL certificate request may fail."
    log_warn "You can request SSL later with: docker exec -it proxy-nginx certbot --nginx -d $DOMAIN_NAME"
fi

# =============================================================================
# STEP 2: Run VPS Setup
# =============================================================================
log_step "Step 2/4: VPS Setup (Docker, proxy-nginx, Tailscale)"

echo ""
echo -e "${YELLOW}IMPORTANT: You will need to authenticate with Tailscale.${NC}"
echo "When prompted, open the URL in your browser to authenticate."
echo ""
read -p "Press Enter to continue..." < /dev/tty

# Download and run vps-setup
curl -fsSL "$VPS_SETUP_URL/setup.sh" | bash

# =============================================================================
# STEP 3: Install PocketDev
# =============================================================================
log_step "Step 3/4: Installing PocketDev"

# Run PocketDev installer
PD_DOMAIN="$DOMAIN_NAME" curl -fsSL "$POCKETDEV_URL/install.sh" | bash

# =============================================================================
# STEP 4: Request SSL Certificate
# =============================================================================
log_step "Step 4/4: SSL Certificate"

if [ "$DNS_OK" = true ]; then
    log_info "Requesting SSL certificate for $DOMAIN_NAME..."
    docker exec -it proxy-nginx certbot --nginx -d "$DOMAIN_NAME" --non-interactive --agree-tos --register-unsafely-without-email || {
        log_warn "Automatic SSL failed. You can request manually:"
        echo "  docker exec -it proxy-nginx certbot --nginx -d $DOMAIN_NAME"
    }
else
    log_warn "Skipping SSL - DNS not yet propagated"
    echo ""
    echo "Once DNS is configured, request SSL with:"
    echo "  docker exec -it proxy-nginx certbot --nginx -d $DOMAIN_NAME"
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
if [ "$DNS_OK" = true ]; then
    echo -e "  ${CYAN}https://$DOMAIN_NAME${NC}"
else
    echo -e "  ${CYAN}http://$DOMAIN_NAME${NC}  (SSL pending DNS propagation)"
fi
echo ""
echo "Server details:"
echo "  IP Address: $SERVER_IP"
echo "  SSH: ssh admin@<tailscale-ip>"
echo ""
echo "Next steps:"
echo "  1. Open the URL above in your browser"
echo "  2. Follow the setup wizard to configure AI providers"
echo "  3. Start building!"
echo ""
echo -e "${GREEN}=============================================================================${NC}"
