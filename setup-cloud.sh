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

    # Check for Git Bash early (unsupported)
    if [[ "$(uname -s)" == MINGW* ]] || [[ "$(uname -s)" == MSYS* ]]; then
        echo -e "${RED}Git Bash detected - this environment is not supported.${NC}"
        echo ""
        echo -e "${YELLOW}Windows users: Please use WSL (Windows Subsystem for Linux) instead.${NC}"
        echo ""
        echo "To set up WSL:"
        echo "  1. Open PowerShell as Administrator"
        echo "  2. Run: wsl --install -d Ubuntu-24.04"
        echo "  3. Restart your computer"
        echo "  4. Open 'Ubuntu' from Start menu and create a user"
        echo "  5. Run this script again inside Ubuntu/WSL"
        echo ""
        exit 1
    fi

    if [ ${#missing[@]} -gt 0 ]; then
        log_error "Missing required dependencies: ${missing[*]}"
        exit 1
    fi
}

# -----------------------------------------------------------------------------
# Install sshpass if needed (called as Step 2)
# -----------------------------------------------------------------------------
install_sshpass_if_needed() {
    # If already installed, skip this step entirely
    if command -v sshpass &>/dev/null; then
        return 0
    fi

    log_step "Step 2/8: Installing sshpass"
    echo ""
    echo "sshpass enables automated SSH login with the server's root password."
    echo "Without it, you'll need to manually enter the password during setup."
    echo ""

    # Detect platform
    PLATFORM="unknown"
    CAN_AUTO_INSTALL=false

    if [[ "$(uname -s)" == "Darwin" ]]; then
        PLATFORM="macos"
        if command -v brew &>/dev/null; then
            CAN_AUTO_INSTALL=true
        fi
    elif [[ "$(uname -s)" == "Linux" ]]; then
        if [[ "$(uname -r)" == *microsoft* ]] || [[ "$(uname -r)" == *Microsoft* ]]; then
            PLATFORM="wsl"
        elif [ -f /etc/os-release ]; then
            . /etc/os-release
            case "$ID" in
                ubuntu|debian) PLATFORM="debian" ;;
                fedora|rhel|centos) PLATFORM="fedora" ;;
            esac
        fi
        if [ "$PLATFORM" != "unknown" ] && command -v sudo &>/dev/null; then
            CAN_AUTO_INSTALL=true
        fi
    fi

    if [ "$CAN_AUTO_INSTALL" = true ]; then
        echo -e "Detected platform: ${CYAN}$PLATFORM${NC}"
        echo ""
        read -p "Install sshpass automatically? [Y/n]: " AUTO_INSTALL < /dev/tty
        AUTO_INSTALL=${AUTO_INSTALL:-Y}

        if [[ "$AUTO_INSTALL" =~ ^[Yy] ]]; then
            log_info "Installing sshpass..."
            case "$PLATFORM" in
                macos)
                    brew install hudochenkov/sshpass/sshpass
                    ;;
                debian|wsl)
                    sudo apt-get update -qq && sudo apt-get install -y -qq sshpass
                    ;;
                fedora)
                    sudo dnf install -y -q sshpass
                    ;;
            esac

            if command -v sshpass &>/dev/null; then
                log_info "sshpass installed successfully!"
            else
                log_error "Failed to install sshpass"
                MANUAL_SSH=true
            fi
        else
            MANUAL_SSH=true
        fi
    elif [ "$PLATFORM" = "macos" ]; then
        echo "Homebrew is required to install sshpass on macOS."
        echo ""
        echo "Install Homebrew first:"
        echo '  /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"'
        echo ""
        echo "Then run this script again, or continue without sshpass."
        echo ""
        read -p "Continue without sshpass? [y/N]: " CONTINUE_NO_SSHPASS < /dev/tty
        case "$CONTINUE_NO_SSHPASS" in
            [Yy]*) MANUAL_SSH=true ;;
            *) exit 1 ;;
        esac
    else
        echo "Could not detect your platform for automatic installation."
        echo ""
        echo "Install sshpass manually:"
        echo ""
        echo "  macOS:       brew install hudochenkov/sshpass/sshpass"
        echo "  Ubuntu/WSL:  sudo apt install sshpass"
        echo "  Fedora:      sudo dnf install sshpass"
        echo ""
        read -p "Continue without sshpass? [y/N]: " CONTINUE_NO_SSHPASS < /dev/tty
        case "$CONTINUE_NO_SSHPASS" in
            [Yy]*) MANUAL_SSH=true ;;
            *) exit 1 ;;
        esac
    fi
}

MANUAL_SSH=false
check_dependencies

# =============================================================================
# STEP 1: Welcome & Platform Check
# =============================================================================
clear
echo -e "${CYAN}"
echo "============================================================================="
echo "  PocketDev Cloud Setup"
echo "============================================================================="
echo -e "${NC}"
log_step "Step 1/8: Welcome"
echo ""
echo "This script creates a fully configured PocketDev instance on a fresh"
echo "Hetzner Cloud server. It handles everything automatically:"
echo ""
echo "  • Creates a Hetzner Cloud VPS"
echo "  • Configures DNS (automatic with TransIP, or manual)"
echo "  • Installs Docker, proxy-nginx, Tailscale, SSH hardening"
echo "  • Deploys PocketDev with wildcard SSL"
echo ""
echo "Time required: ~10-15 minutes"
echo ""
echo -e "${CYAN}Supported platforms:${NC}"
echo "  • macOS (Terminal)"
echo "  • Linux (Ubuntu, Debian, Fedora)"
echo "  • Windows (WSL only)"
echo ""
echo -e "${YELLOW}Windows users:${NC} You must run this from inside WSL, not from CMD or PowerShell."
echo "  If you're in CMD/PowerShell, type 'wsl' first to enter WSL, then run this script."
echo "  Or open Windows Terminal and select 'Ubuntu' from the dropdown."
echo ""
echo -e "${YELLOW}Already have a server?${NC} Press Ctrl+C and run setup-server.sh instead."
echo ""
read -p "Press Y to proceed (if you cannot respond, you're likely in CMD on Windows): " PROCEED < /dev/tty
if [[ ! "$PROCEED" =~ ^[Yy]$ ]]; then
    echo ""
    log_error "Setup cancelled."
    exit 1
fi
echo ""

# =============================================================================
# STEP 2: Install sshpass (if needed)
# =============================================================================
install_sshpass_if_needed

# =============================================================================
# STEP 3: Hetzner API Token
# =============================================================================
log_step "Step 3/8: Hetzner Cloud Setup"

echo ""
echo "You'll need a Hetzner Cloud API token with read/write permissions."
echo ""
echo -e "Create one at: ${CYAN}https://console.hetzner.cloud/projects → Select project → Security → API tokens${NC}"
echo ""

read -p "Enter your Hetzner API token: " HETZNER_TOKEN < /dev/tty

if [ -z "$HETZNER_TOKEN" ]; then
    log_error "API token is required"
    exit 1
fi

# Verify token (read access)
log_info "Verifying API token..."
VERIFY_RESPONSE=$(curl -sf -H "Authorization: Bearer $HETZNER_TOKEN" "$HETZNER_API/servers" 2>&1) || {
    log_error "Invalid API token or network error"
    exit 1
}

# Verify write permissions by trying to create a test SSH key
log_info "Checking write permissions..."
TEST_KEY_NAME="pocketdev-permission-test-$(date +%s)"
WRITE_TEST=$(curl -s -w "\n%{http_code}" -X POST \
    -H "Authorization: Bearer $HETZNER_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"name\":\"$TEST_KEY_NAME\",\"public_key\":\"ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITest pocketdev-test\"}" \
    "$HETZNER_API/ssh_keys")

WRITE_HTTP_CODE=$(echo "$WRITE_TEST" | tail -n1)
WRITE_BODY=$(echo "$WRITE_TEST" | sed '$d')

if [ "$WRITE_HTTP_CODE" = "201" ]; then
    # Success - delete the test key
    TEST_KEY_ID=$(echo "$WRITE_BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
    if [ -n "$TEST_KEY_ID" ]; then
        curl -sf -X DELETE -H "Authorization: Bearer $HETZNER_TOKEN" "$HETZNER_API/ssh_keys/$TEST_KEY_ID" >/dev/null 2>&1
    fi
    log_info "API token verified with read/write access!"
elif [ "$WRITE_HTTP_CODE" = "401" ] || [ "$WRITE_HTTP_CODE" = "403" ]; then
    echo ""
    log_error "API token has read-only permissions"
    echo ""
    echo -e "This script needs a token with ${BOLD}Read & Write${NC} access to create servers."
    echo ""
    echo "To create a new token with write access:"
    echo "  1. Go to https://console.hetzner.cloud/projects"
    echo "  2. Select your project → Security → API tokens"
    echo "  3. Generate API token → Select 'Read & Write'"
    echo ""
    exit 1
else
    # Other response (e.g., 422 validation error) means we have write access
    log_info "API token verified with read/write access!"
fi

# =============================================================================
# STEP 4: Server Configuration
# =============================================================================
log_step "Step 4/8: Server Configuration"

echo ""
echo "Select server size:"
echo ""
echo -e "  ${BOLD}xs${NC} - Extremely Small (1 vCPU, 2GB)  - ~4 EUR/mo  - For 1-2 small apps"
echo -e "  ${BOLD}s${NC}  - Small            (2 vCPU, 4GB)  - ~8 EUR/mo  - For 3-5 apps"
echo -e "  ${BOLD}m${NC}  - Medium           (4 vCPU, 8GB)  - ~15 EUR/mo - For 5-10 apps"
echo -e "  ${BOLD}l${NC}  - Large            (8 vCPU, 16GB) - ~29 EUR/mo - For 10-20 apps"
echo -e "  ${BOLD}xl${NC} - Extremely Large  (16 vCPU, 32GB)- ~57 EUR/mo - For 20+ apps"
echo ""

read -p "Server size [s]: " SERVER_SIZE < /dev/tty
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
echo -e "  ${BOLD}fsn1${NC} - Falkenstein, Germany"
echo -e "  ${BOLD}nbg1${NC} - Nuremberg, Germany"
echo -e "  ${BOLD}hel1${NC} - Helsinki, Finland"
echo -e "  ${BOLD}ash${NC}  - Ashburn, USA (East Coast)"
echo -e "  ${BOLD}hil${NC}  - Hillsboro, USA (West Coast)"
echo ""

read -p "Location [fsn1]: " LOCATION < /dev/tty
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
read -p "Server name [pocketdev]: " SERVER_NAME < /dev/tty
SERVER_NAME=${SERVER_NAME:-pocketdev}
SERVER_NAME=$(echo "$SERVER_NAME" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9-]/-/g')

log_info "Server name: $SERVER_NAME"

# Backups
echo ""
echo -e "${YELLOW}Recommended:${NC} Enable automatic backups (+20% of server cost)"
echo "Backups protect against data loss and allow easy recovery."
echo ""
read -p "Enable backups? [Y/n]: " ENABLE_BACKUPS < /dev/tty
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
# STEP 5: Domain Configuration
# =============================================================================
log_step "Step 5/8: Domain Configuration"

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
echo -e "  • ${BOLD}Private${NC} = Only accessible via Tailscale VPN (your devices only)"
echo -e "  • ${BOLD}Public${NC}  = Accessible from anywhere on the internet"
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

# DNS automation option
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

TRANSIP_LOGIN=""
TRANSIP_PRIVATE_KEY=""
USE_TRANSIP=false

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
            log_info "TransIP credentials saved (will configure after server creation)"
        fi
    fi
fi

# =============================================================================
# STEP 6: Create Server
# =============================================================================
log_step "Step 6/8: Creating Hetzner Server"

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
CREATE_RESULT=$(curl -s -w "\n%{http_code}" -X POST \
    -H "Authorization: Bearer $HETZNER_TOKEN" \
    -H "Content-Type: application/json" \
    -d "$CREATE_REQUEST" \
    "$HETZNER_API/servers")

CREATE_HTTP_CODE=$(echo "$CREATE_RESULT" | tail -n1)
CREATE_RESPONSE=$(echo "$CREATE_RESULT" | sed '$d')

if [ "$CREATE_HTTP_CODE" != "201" ]; then
    log_error "Failed to create server (HTTP $CREATE_HTTP_CODE)"
    echo ""
    # Try to extract error message from response
    ERROR_MSG=$(echo "$CREATE_RESPONSE" | grep -o '"message":"[^"]*"' | cut -d'"' -f4)
    if [ -n "$ERROR_MSG" ]; then
        echo "  Error: $ERROR_MSG"
    else
        echo "  Response: $CREATE_RESPONSE"
    fi
    echo ""
    exit 1
fi

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
# STEP 7: Configure DNS
# =============================================================================
log_step "Step 7/8: DNS Configuration"

if [ "$USE_TRANSIP" = true ]; then
    log_info "TransIP credentials will be passed to server setup."
    log_info "DNS will be configured automatically during SSL certificate request."

    # We'll pass credentials to the server via environment variables
    # For now, just note that DNS will be auto-configured
else
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

# Wait for DNS propagation (simple check) - only for manual DNS
if [ "$USE_TRANSIP" = false ]; then
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
        log_warn "Continuing anyway - you can request SSL later."
    fi
fi

# =============================================================================
# STEP 8: Setup Server
# =============================================================================
log_step "Step 8/8: Server Setup"

# Wait for server to be ready
log_info "Waiting for server to be ready..."
sleep 30

# Prepare TransIP credentials for transfer if needed
TRANSIP_ENV_COMMANDS=""
if [ "$USE_TRANSIP" = true ]; then
    # Escape the private key for passing via SSH
    TRANSIP_KEY_ESCAPED=$(echo "$TRANSIP_PRIVATE_KEY" | base64 -w0)
    TRANSIP_ENV_COMMANDS="mkdir -p /etc/pocketdev && echo '$TRANSIP_LOGIN' > /etc/pocketdev/transip_login && echo '$TRANSIP_KEY_ESCAPED' | base64 -d > /etc/pocketdev/transip_key && chmod 600 /etc/pocketdev/transip_*"
fi

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
    if [ "$USE_TRANSIP" = true ]; then
        echo -e "${BOLD}2. Run the server setup (with TransIP):${NC}"
        echo "   # TransIP credentials will need to be entered again during setup"
        echo "   PD_DOMAIN=$DOMAIN_NAME curl -fsSL $POCKETDEV_URL/setup-server.sh | bash"
    else
        echo -e "${BOLD}2. Run the server setup:${NC}"
        echo "   PD_DOMAIN=$DOMAIN_NAME curl -fsSL $POCKETDEV_URL/setup-server.sh | bash"
    fi
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

# Transfer TransIP credentials if needed
if [ "$USE_TRANSIP" = true ]; then
    log_info "Transferring TransIP credentials to server..."
    sshpass -p "$ROOT_PASSWORD" ssh -o StrictHostKeyChecking=no root@"$SERVER_IP" "$TRANSIP_ENV_COMMANDS"
fi

# Run server setup script (vps-setup + PocketDev)
log_info "Running server setup (this will take ~5-10 minutes)..."
echo ""
echo -e "${YELLOW}IMPORTANT: You will need to authenticate with Tailscale.${NC}"
echo "A browser will NOT open automatically. Look for the auth URL in the output."
echo ""

# Set environment variables for setup-server.sh
ENV_VARS="PD_DOMAIN=$DOMAIN_NAME"
if [ "$USE_TRANSIP" = true ]; then
    ENV_VARS="$ENV_VARS PD_USE_TRANSIP=1"
fi

sshpass -p "$ROOT_PASSWORD" ssh -t -o StrictHostKeyChecking=no root@"$SERVER_IP" \
    "$ENV_VARS curl -fsSL $POCKETDEV_URL/setup-server.sh | bash"

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
echo "Credentials saved to: $CREDENTIALS_FILE"
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
