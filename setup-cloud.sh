#!/bin/bash
# =============================================================================
# PocketDev Setup - Full Cloud Installation
# =============================================================================
#
# One-liner setup for a complete PocketDev instance with VPS, Tailscale, and SSL.
# Run this from your LOCAL machine (Mac/Linux terminal or Windows WSL).
#
# USAGE:
#   curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/setup-cloud.sh | bash
#
# WHAT IT DOES:
#   1. Creates a Hetzner Cloud server
#   2. Configures DNS (TransIP or manual)
#   3. Runs vps-setup (Docker, proxy-nginx, Tailscale, SSH hardening)
#   4. Installs PocketDev with SSL
#
# REQUIREMENTS:
#   - Hetzner Cloud account with API token
#   - Tailscale account (free at tailscale.com)
#   - Domain name (optional TransIP account for DNS automation)
#
# ALREADY HAVE A SERVER? Use setup-server.sh instead:
#   curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/setup-server.sh | bash
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
HETZNER_API="https://api.hetzner.cloud/v1"

# Branch overrides for testing (default to main)
VPS_SETUP_BRANCH="${VPS_SETUP_BRANCH:-main}"
POCKETDEV_BRANCH="${POCKETDEV_BRANCH:-main}"
VPS_SETUP_URL="https://raw.githubusercontent.com/tetrixdev/vps-setup/$VPS_SETUP_BRANCH"
POCKETDEV_URL="https://raw.githubusercontent.com/tetrixdev/pocket-dev/$POCKETDEV_BRANCH"

# =============================================================================
# Dependency Check
# =============================================================================
check_dependencies() {
    local missing=()

    # Required: curl, dig
    command -v curl &>/dev/null || missing+=("curl")
    command -v dig &>/dev/null || missing+=("dig (dnsutils)")

    # Optional but needed for automated setup: sshpass
    if ! command -v sshpass &>/dev/null; then
        echo ""
        log_warn "sshpass is not installed"
        echo ""
        echo "sshpass is needed for automated SSH login with the root password."
        echo "Install it with:"
        echo ""
        echo "  macOS:   brew install hudochenkov/sshpass/sshpass"
        echo "  Ubuntu:  sudo apt install sshpass"
        echo "  Fedora:  sudo dnf install sshpass"
        echo ""
        echo "Alternatively, you can complete the final steps manually."
        echo ""
        read -p "Continue without sshpass? [y/N]: " CONTINUE_NO_SSHPASS
        case "$CONTINUE_NO_SSHPASS" in
            [Yy]*) MANUAL_SSH=true ;;
            *)
                echo "Please install sshpass and try again."
                exit 1
                ;;
        esac
    fi

    if [ ${#missing[@]} -gt 0 ]; then
        log_error "Missing required dependencies: ${missing[*]}"
        exit 1
    fi
}

MANUAL_SSH=false
check_dependencies

# =============================================================================
# HEADER
# =============================================================================
clear
echo -e "${CYAN}"
echo "============================================================================="
echo "  PocketDev Setup"
echo "============================================================================="
echo -e "${NC}"
echo "This script will create a fully configured PocketDev instance with:"
echo "  - Hetzner Cloud server"
echo "  - Tailscale (secure SSH access)"
echo "  - proxy-nginx (reverse proxy with SSL)"
echo "  - PocketDev (AI development environment)"
echo ""
echo "Time required: ~10-15 minutes"
echo ""
echo -e "${YELLOW}Already have a server?${NC} Press Ctrl+C and run setup-server.sh instead."
echo ""

# =============================================================================
# STEP 1: Hetzner API Token
# =============================================================================
log_step "Step 1/6: Hetzner Cloud Setup"

echo ""
echo "You'll need a Hetzner Cloud API token with read/write permissions."
echo ""
echo "Create one at: ${CYAN}https://console.hetzner.cloud/projects → Select project → Security → API tokens${NC}"
echo ""

read -p "Enter your Hetzner API token: " HETZNER_TOKEN

if [ -z "$HETZNER_TOKEN" ]; then
    log_error "API token is required"
    exit 1
fi

# Verify token
log_info "Verifying API token..."
VERIFY_RESPONSE=$(curl -sf -H "Authorization: Bearer $HETZNER_TOKEN" "$HETZNER_API/servers" 2>&1) || {
    log_error "Invalid API token or network error"
    exit 1
}

log_info "API token verified!"

# =============================================================================
# STEP 2: Server Configuration
# =============================================================================
log_step "Step 2/6: Server Configuration"

echo ""
echo "Select server size:"
echo ""
echo "  ${BOLD}xs${NC} - Extremely Small (1 vCPU, 2GB)  - ~4 EUR/mo  - For 1-2 small apps"
echo "  ${BOLD}s${NC}  - Small            (2 vCPU, 4GB)  - ~8 EUR/mo  - For 3-5 apps"
echo "  ${BOLD}m${NC}  - Medium           (4 vCPU, 8GB)  - ~15 EUR/mo - For 5-10 apps"
echo "  ${BOLD}l${NC}  - Large            (8 vCPU, 16GB) - ~29 EUR/mo - For 10-20 apps"
echo "  ${BOLD}xl${NC} - Extremely Large  (16 vCPU, 32GB)- ~57 EUR/mo - For 20+ apps"
echo ""

read -p "Server size [s]: " SERVER_SIZE
SERVER_SIZE=${SERVER_SIZE:-s}

# Validate and get server type
case "$SERVER_SIZE" in
    xs) SERVER_TYPE="cpx11" ;;
    s)  SERVER_TYPE="cpx21" ;;
    m)  SERVER_TYPE="cpx31" ;;
    l)  SERVER_TYPE="cpx41" ;;
    xl) SERVER_TYPE="cpx51" ;;
    *)
        log_warn "Invalid size '$SERVER_SIZE', using 's' (small)"
        SERVER_SIZE="s"
        SERVER_TYPE="cpx21"
        ;;
esac

log_info "Selected: $SERVER_SIZE ($SERVER_TYPE)"

# Location selection
echo ""
echo "Select server location:"
echo ""
echo "  ${BOLD}fsn1${NC} - Falkenstein, Germany"
echo "  ${BOLD}nbg1${NC} - Nuremberg, Germany"
echo "  ${BOLD}hel1${NC} - Helsinki, Finland"
echo "  ${BOLD}ash${NC}  - Ashburn, USA (East Coast)"
echo "  ${BOLD}hil${NC}  - Hillsboro, USA (West Coast)"
echo ""

read -p "Location [fsn1]: " LOCATION
LOCATION=${LOCATION:-fsn1}

# Validate location
case "$LOCATION" in
    fsn1|nbg1|hel1|ash|hil) ;;
    *)
        log_warn "Invalid location '$LOCATION', using 'fsn1'"
        LOCATION="fsn1"
        ;;
esac

log_info "Selected location: $LOCATION"

# Server name
echo ""
read -p "Server name [pocketdev]: " SERVER_NAME
SERVER_NAME=${SERVER_NAME:-pocketdev}
SERVER_NAME=$(echo "$SERVER_NAME" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9-]/-/g')

log_info "Server name: $SERVER_NAME"

# Backups
echo ""
echo -e "${YELLOW}Recommended:${NC} Enable automatic backups (+20% of server cost)"
echo "Backups protect against data loss and allow easy recovery."
echo ""
read -p "Enable backups? [Y/n]: " ENABLE_BACKUPS
case "$ENABLE_BACKUPS" in
    [Nn]*)
        BACKUPS_ENABLED=false
        log_info "Backups: disabled"
        ;;
    *)
        BACKUPS_ENABLED=true
        log_info "Backups: enabled"
        ;;
esac

# =============================================================================
# STEP 3: Domain Configuration
# =============================================================================
log_step "Step 3/6: Domain Configuration"

echo ""
echo "Enter the domain name for PocketDev (e.g., pocketdev.example.com)"
echo "This domain must be pointed to your server's IP after creation."
echo ""

read -p "Domain name: " DOMAIN_NAME

if [ -z "$DOMAIN_NAME" ]; then
    log_error "Domain name is required"
    exit 1
fi

# DNS automation option
echo ""
echo "How would you like to configure DNS?"
echo ""
echo "  ${BOLD}1${NC} - TransIP (automatic DNS configuration)"
echo "  ${BOLD}2${NC} - Manual (I'll configure DNS myself)"
echo ""

read -p "DNS method [2]: " DNS_METHOD
DNS_METHOD=${DNS_METHOD:-2}

TRANSIP_TOKEN=""
if [ "$DNS_METHOD" = "1" ]; then
    echo ""
    echo "TransIP API setup:"
    echo "Create an API token at: ${CYAN}https://www.transip.nl/cp/account/api/${NC}"
    echo ""
    read -p "Enter TransIP API token: " TRANSIP_TOKEN

    if [ -z "$TRANSIP_TOKEN" ]; then
        log_warn "No TransIP token provided, falling back to manual DNS"
        DNS_METHOD="2"
    else
        log_info "TransIP token saved (will configure after server creation)"
    fi
fi

# =============================================================================
# STEP 4: Create Server
# =============================================================================
log_step "Step 4/6: Creating Hetzner Server"

echo ""
log_info "Creating server '$SERVER_NAME' ($SERVER_TYPE in $LOCATION)..."

# Build server create request
CREATE_REQUEST=$(cat <<EOF
{
  "name": "$SERVER_NAME",
  "server_type": "$SERVER_TYPE",
  "location": "$LOCATION",
  "image": "ubuntu-24.04",
  "start_after_create": true,
  "automount": false,
  "backups": $BACKUPS_ENABLED
}
EOF
)

# Create server
CREATE_RESPONSE=$(curl -sf -X POST \
    -H "Authorization: Bearer $HETZNER_TOKEN" \
    -H "Content-Type: application/json" \
    -d "$CREATE_REQUEST" \
    "$HETZNER_API/servers") || {
    log_error "Failed to create server"
    echo "$CREATE_RESPONSE"
    exit 1
}

# Extract server details
SERVER_ID=$(echo "$CREATE_RESPONSE" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
SERVER_IP=$(echo "$CREATE_RESPONSE" | grep -o '"ipv4_address":"[^"]*"' | head -1 | cut -d'"' -f4)
ROOT_PASSWORD=$(echo "$CREATE_RESPONSE" | grep -o '"root_password":"[^"]*"' | cut -d'"' -f4)

if [ -z "$SERVER_ID" ] || [ -z "$SERVER_IP" ]; then
    log_error "Failed to extract server details from response"
    echo "$CREATE_RESPONSE"
    exit 1
fi

log_info "Server created!"
echo ""
echo "  Server ID: $SERVER_ID"
echo "  IP Address: $SERVER_IP"
echo "  Root Password: $ROOT_PASSWORD"
echo ""

# Save credentials
CREDENTIALS_FILE="$HOME/.pocketdev-setup-$SERVER_NAME.txt"
cat > "$CREDENTIALS_FILE" <<EOF
PocketDev Server Setup Credentials
===================================
Server Name: $SERVER_NAME
Server ID: $SERVER_ID
IP Address: $SERVER_IP
Root Password: $ROOT_PASSWORD
Domain: $DOMAIN_NAME

Created: $(date)
EOF
chmod 600 "$CREDENTIALS_FILE"
log_info "Credentials saved to: $CREDENTIALS_FILE"

# =============================================================================
# STEP 5: Configure DNS
# =============================================================================
log_step "Step 5/6: DNS Configuration"

# Extract root domain from full domain
ROOT_DOMAIN=$(echo "$DOMAIN_NAME" | rev | cut -d. -f1-2 | rev)
SUBDOMAIN=$(echo "$DOMAIN_NAME" | sed "s/\.$ROOT_DOMAIN$//")
if [ "$SUBDOMAIN" = "$DOMAIN_NAME" ]; then
    SUBDOMAIN="@"
fi

if [ "$DNS_METHOD" = "1" ] && [ -n "$TRANSIP_TOKEN" ]; then
    log_info "Configuring DNS via TransIP..."

    # TransIP API requires JWT auth - for simplicity, we'll provide instructions
    # Full TransIP integration would require the private key dance
    log_warn "TransIP auto-configuration requires additional setup"
    echo ""
    echo "Please add this DNS record manually in TransIP:"
    echo ""
    echo "  Domain: $ROOT_DOMAIN"
    echo "  Type:   A"
    echo "  Name:   $SUBDOMAIN"
    echo "  Value:  $SERVER_IP"
    echo "  TTL:    300"
    echo ""
    read -p "Press Enter once DNS is configured..."
else
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
    read -p "Press Enter once DNS is configured..."
fi

# Wait for DNS propagation (simple check)
log_info "Checking DNS propagation..."
for i in {1..30}; do
    RESOLVED_IP=$(dig +short "$DOMAIN_NAME" 2>/dev/null | head -1)
    if [ "$RESOLVED_IP" = "$SERVER_IP" ]; then
        log_info "DNS is configured correctly!"
        break
    fi
    if [ $i -eq 30 ]; then
        log_warn "DNS not yet propagated. SSL certificate request may fail."
        log_warn "You can request SSL later with: docker exec -it proxy-nginx certbot --nginx -d $DOMAIN_NAME"
    fi
    echo -n "."
    sleep 2
done
echo ""

# =============================================================================
# STEP 6: Setup Server
# =============================================================================
log_step "Step 6/6: Server Setup"

# Wait for server to be ready
log_info "Waiting for server to be ready..."
sleep 30

if [ "$MANUAL_SSH" = true ]; then
    # Manual SSH instructions
    echo ""
    echo -e "${CYAN}=============================================================================${NC}"
    echo -e "${CYAN}  Manual Setup Required${NC}"
    echo -e "${CYAN}=============================================================================${NC}"
    echo ""
    echo "Please complete setup by running these commands:"
    echo ""
    echo -e "${BOLD}1. Connect to your server:${NC}"
    echo "   ssh root@$SERVER_IP"
    echo "   Password: $ROOT_PASSWORD"
    echo ""
    echo -e "${BOLD}2. Run the server setup:${NC}"
    echo "   PD_DOMAIN=$DOMAIN_NAME curl -fsSL $POCKETDEV_URL/setup-server.sh | bash"
    echo ""
    echo -e "   ${YELLOW}Note: You'll need to authenticate with Tailscale when prompted.${NC}"
    echo ""
    echo "Credentials saved to: $CREDENTIALS_FILE"
    echo ""
    exit 0
fi

# Automated setup with sshpass
log_info "Connecting to server..."
for i in {1..30}; do
    if sshpass -p "$ROOT_PASSWORD" ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 root@"$SERVER_IP" "echo connected" &>/dev/null; then
        log_info "SSH connection established!"
        break
    fi
    if [ $i -eq 30 ]; then
        log_error "Could not connect to server after 5 minutes"
        echo ""
        echo "You can manually complete setup by running:"
        echo "  ssh root@$SERVER_IP"
        echo "  # Password: $ROOT_PASSWORD"
        echo "  PD_DOMAIN=$DOMAIN_NAME curl -fsSL $POCKETDEV_URL/setup-server.sh | bash"
        exit 1
    fi
    echo -n "."
    sleep 10
done
echo ""

# Run server setup script (vps-setup + PocketDev)
log_info "Running server setup (this will take ~5-10 minutes)..."
echo ""
echo -e "${YELLOW}IMPORTANT: You will need to authenticate with Tailscale.${NC}"
echo "A browser will NOT open automatically. Look for the auth URL in the output."
echo ""

sshpass -p "$ROOT_PASSWORD" ssh -t -o StrictHostKeyChecking=no root@"$SERVER_IP" \
    "PD_DOMAIN=$DOMAIN_NAME curl -fsSL $POCKETDEV_URL/setup-server.sh | bash"

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
echo "Server details:"
echo "  IP Address: $SERVER_IP"
echo "  SSH: ssh admin@<tailscale-ip>"
echo ""
echo "Credentials saved to: $CREDENTIALS_FILE"
echo ""
echo "Next steps:"
echo "  1. Open https://$DOMAIN_NAME in your browser"
echo "  2. Follow the setup wizard to configure AI providers"
echo "  3. Start building!"
echo ""
echo -e "${GREEN}=============================================================================${NC}"
