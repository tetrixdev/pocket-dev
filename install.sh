#!/bin/bash
# =============================================================================
# PocketDev Installation Script
# =============================================================================
#
# Installs PocketDev on a server. Run vps-setup first for a properly configured
# server with Docker and proxy-nginx.
#
# USAGE:
#   ./install.sh                           # Interactive mode (recommended)
#   ./install.sh --domain=DOMAIN           # With domain
#   ./install.sh --local                   # Local mode (no domain/SSL)
#
# OPTIONS:
#   --domain=DOMAIN         Domain for PocketDev (e.g., pocketdev.example.com)
#   --restriction=MODE      Access restriction: tailscale, whitelist, none
#   --ips=IPS               IP whitelist (comma-separated, requires --restriction=whitelist)
#   --local                 Local mode - skip domain/SSL setup
#   --port=PORT             Port for local mode (default: 80)
#   --name=NAME             Project name for containers/volumes (default: pocket-dev)
#   --skip-dns-check        Skip DNS verification
#   -h, --help              Show this help message
#
# EXAMPLES:
#   # Interactive installation
#   curl -fsSL https://pocketdev.io/install | bash
#
#   # With domain and Tailscale restriction
#   ./install.sh --domain=pocketdev.example.com --restriction=tailscale
#
#   # With domain and IP whitelist
#   ./install.sh --domain=pocketdev.example.com --restriction=whitelist --ips="1.2.3.4"
#
#   # Local development (no domain/SSL)
#   ./install.sh --local --port=8080
#
# =============================================================================

set -euo pipefail

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
POCKETDEV_DIR="${POCKETDEV_DIR:-/docker-apps/pocket-dev}"
REPO="tetrixdev/pocket-dev"

# CLI arguments (empty = interactive)
ARG_DOMAIN=""
ARG_RESTRICTION=""
ARG_IPS=""
ARG_LOCAL=false
ARG_PORT="80"
ARG_SKIP_DNS_CHECK=false
ARG_NAME="pocket-dev"

# Runtime state
PROXY_AVAILABLE=false
SERVER_IP=""

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
# Helper functions
# -----------------------------------------------------------------------------
sedi() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "$@"
    else
        sed -i "$@"
    fi
}

get_gid() {
    if stat -c '%g' "$1" >/dev/null 2>&1; then
        stat -c '%g' "$1"
    else
        stat -f '%g' "$1" 2>/dev/null
    fi
}

get_server_ip() {
    # Try to get public IP
    curl -sf --max-time 5 https://api.ipify.org 2>/dev/null || \
    curl -sf --max-time 5 https://ifconfig.me 2>/dev/null || \
    curl -sf --max-time 5 https://icanhazip.com 2>/dev/null || \
    echo ""
}

check_dns() {
    local domain="$1"
    local expected_ip="$2"

    # Resolve domain
    local resolved_ip
    resolved_ip=$(dig +short "$domain" 2>/dev/null | head -1)

    if [ -z "$resolved_ip" ]; then
        return 1  # DNS not resolving
    fi

    if [ "$resolved_ip" = "$expected_ip" ]; then
        return 0  # Matches
    else
        echo "$resolved_ip"  # Return the actual IP for error message
        return 2  # Wrong IP
    fi
}

# -----------------------------------------------------------------------------
# Parse arguments
# -----------------------------------------------------------------------------
show_help() {
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  --domain=DOMAIN         Domain for PocketDev (e.g., pocketdev.example.com)"
    echo "  --restriction=MODE      Access restriction: tailscale, whitelist, none"
    echo "  --ips=IPS               IP whitelist (comma-separated, with --restriction=whitelist)"
    echo "  --local                 Local mode - skip domain/SSL setup"
    echo "  --port=PORT             Port for local mode (default: 80)"
    echo "  --name=NAME             Project name for containers/volumes (default: pocket-dev)"
    echo "  --skip-dns-check        Skip DNS verification"
    echo "  -h, --help              Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0                                                    # Interactive"
    echo "  $0 --domain=pocketdev.example.com --restriction=tailscale"
    echo "  $0 --domain=pocketdev.example.com --restriction=whitelist --ips=\"1.2.3.4\""
    echo "  $0 --local --port=8080"
    echo "  $0 --domain=pd2.example.com --name=pocket-dev-2      # Multiple instances"
}

while [[ $# -gt 0 ]]; do
    case $1 in
        --domain=*)
            ARG_DOMAIN="${1#*=}"
            shift
            ;;
        --restriction=*)
            ARG_RESTRICTION="${1#*=}"
            if [[ ! "$ARG_RESTRICTION" =~ ^(tailscale|whitelist|none)$ ]]; then
                log_error "Invalid restriction: $ARG_RESTRICTION (must be: tailscale, whitelist, none)"
                exit 1
            fi
            shift
            ;;
        --ips=*)
            ARG_IPS="${1#*=}"
            shift
            ;;
        --local)
            ARG_LOCAL=true
            shift
            ;;
        --port=*)
            ARG_PORT="${1#*=}"
            shift
            ;;
        --skip-dns-check)
            ARG_SKIP_DNS_CHECK=true
            shift
            ;;
        --name=*)
            ARG_NAME="${1#*=}"
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            echo "Run '$0 --help' for usage"
            exit 1
            ;;
    esac
done

# Validate argument combinations
if [ "$ARG_RESTRICTION" = "whitelist" ] && [ -z "$ARG_IPS" ]; then
    log_error "--restriction=whitelist requires --ips=IPS"
    exit 1
fi

# Validate port number
if ! [[ "$ARG_PORT" =~ ^[0-9]+$ ]] || [ "$ARG_PORT" -lt 1 ] || [ "$ARG_PORT" -gt 65535 ]; then
    log_error "Invalid port: $ARG_PORT (must be 1-65535)"
    exit 1
fi

# Validate project name (lowercase letters and dashes only, must contain at least one dash)
if ! [[ "$ARG_NAME" =~ ^[a-z][a-z-]*-[a-z][a-z-]*$ ]]; then
    log_error "Invalid project name: $ARG_NAME"
    echo "  Project name must:"
    echo "    - Use only lowercase letters (a-z) and dashes (-)"
    echo "    - Start with a letter"
    echo "    - Contain at least one dash (e.g., 'pocket-dev', 'my-project')"
    exit 1
fi

# =============================================================================
# STEP 1: Pre-flight Checks
# =============================================================================
log_step "Step 1/5: Pre-flight checks..."

# Must run as root (or with sudo)
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root: sudo $0"
    exit 1
fi

# Check for Docker
if ! command -v docker &> /dev/null; then
    log_error "Docker is not installed."
    echo ""
    echo "Run vps-setup first to install Docker and proxy-nginx:"
    echo "  curl -fsSL https://raw.githubusercontent.com/tetrixdev/vps-setup/main/setup.sh | bash"
    exit 1
fi
log_info "Docker found"

# Install required dependencies (dig for DNS checks, openssl for secrets)
if ! command -v dig &> /dev/null || ! command -v openssl &> /dev/null; then
    log_info "Installing required dependencies..."
    apt-get update -qq
    apt-get install -y -qq dnsutils openssl 2>/dev/null || {
        log_error "Failed to install dependencies (dnsutils, openssl)"
        exit 1
    }
fi

# Check for proxy-nginx
if docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^proxy-nginx$'; then
    PROXY_AVAILABLE=true
    log_info "proxy-nginx detected"
else
    PROXY_AVAILABLE=false
    echo ""
    echo "============================================================================="
    echo -e "${YELLOW}WARNING: proxy-nginx not found${NC}"
    echo "============================================================================="
    echo ""
    echo "PocketDev is a powerful tool that should be properly secured."
    echo ""
    echo "If this is a LOCAL development environment (not reachable from the internet),"
    echo "you can proceed without proxy-nginx."
    echo ""
    echo "If this is a REMOTE server accessible from the internet, we strongly"
    echo "recommend setting up a fresh server with vps-setup first:"
    echo ""
    echo "  curl -fsSL https://raw.githubusercontent.com/tetrixdev/vps-setup/main/setup.sh | bash"
    echo ""
    echo "This will install Docker, proxy-nginx, and configure proper security."
    echo "============================================================================="
    echo ""

    if [ "$ARG_LOCAL" != true ]; then
        while true; do
            read -rp "Continue without proxy-nginx? (yes/no): " confirm < /dev/tty
            case $confirm in
                yes)
                    ARG_LOCAL=true
                    break
                    ;;
                no)
                    echo "Aborting. Please run vps-setup first."
                    exit 1
                    ;;
                *)
                    echo "Please type 'yes' or 'no'"
                    ;;
            esac
        done
    fi
fi

# Create main-network if using proxy-nginx
if [ "$PROXY_AVAILABLE" = true ]; then
    if ! docker network ls --format '{{.Name}}' | grep -q '^main-network$'; then
        log_info "Creating main-network..."
        docker network create main-network
    fi
fi

# Get server IP for DNS verification
SERVER_IP=$(get_server_ip)
if [ -n "$SERVER_IP" ]; then
    log_info "Server IP: $SERVER_IP"
else
    log_warn "Could not detect server IP (DNS verification will be skipped)"
    ARG_SKIP_DNS_CHECK=true
fi

# =============================================================================
# STEP 2: Domain Configuration
# =============================================================================
log_step "Step 2/5: Domain configuration..."

DOMAIN=""
SKIP_DOMAIN=false

if [ "$ARG_LOCAL" = true ]; then
    SKIP_DOMAIN=true
    log_info "Local mode - skipping domain configuration"
elif [ -n "$ARG_DOMAIN" ]; then
    DOMAIN="$ARG_DOMAIN"
    log_info "Domain: $DOMAIN"
else
    # Interactive domain selection
    echo ""
    echo "PocketDev needs a domain name for SSL certificates and secure access."
    echo ""
    echo "Before continuing, ensure you have:"
    echo "  1. A domain/subdomain pointing to this server (e.g., pocketdev.example.com)"
    echo "  2. Optionally, a wildcard for future apps (e.g., *.pocketdev.example.com)"
    echo ""
    echo "Both should be A records pointing to: ${SERVER_IP:-<your server IP>}"
    echo ""

    while true; do
        read -rp "Enter domain for PocketDev (or 'skip' for local mode): " input_domain < /dev/tty

        if [ "$input_domain" = "skip" ]; then
            SKIP_DOMAIN=true
            ARG_LOCAL=true
            log_info "Skipping domain configuration (local mode)"
            break
        elif [ -n "$input_domain" ]; then
            DOMAIN="$input_domain"
            break
        else
            echo "Please enter a domain or 'skip'"
        fi
    done
fi

# DNS Verification
if [ "$SKIP_DOMAIN" = false ] && [ -n "$DOMAIN" ] && [ "$ARG_SKIP_DNS_CHECK" = false ]; then
    echo ""
    log_info "Verifying DNS for $DOMAIN..."

    # Primary domain DNS check with retry loop
    while true; do
        dns_status=0
        dns_result=$(check_dns "$DOMAIN" "$SERVER_IP" 2>&1) || dns_status=$?

        if [ "$dns_status" -eq 0 ]; then
            log_info "DNS verified: $DOMAIN -> $SERVER_IP"
            break
        elif [ "$dns_status" -eq 1 ]; then
            echo ""
            log_warn "DNS not resolving for $DOMAIN"
            echo ""
            echo "Please create an A record:"
            echo "  $DOMAIN -> $SERVER_IP"
        else
            echo ""
            log_warn "DNS mismatch for $DOMAIN"
            echo "  Expected: $SERVER_IP"
            echo "  Got:      $dns_result"
        fi

        echo ""
        echo "Options:"
        echo "  [R] Check again (default)"
        echo "  [C] Continue anyway"
        echo "  [A] Abort"
        echo ""
        read -rp "Choice [R/c/a]: " choice < /dev/tty
        case "${choice:-r}" in
            r|R|"")
                echo ""
                log_info "Checking DNS again..."
                ;;
            c|C)
                log_warn "Continuing without DNS verification"
                break
                ;;
            a|A)
                echo "Aborting."
                exit 1
                ;;
            *)
                echo "Invalid choice. Please enter R, C, or A."
                ;;
        esac
    done

    # Check wildcard DNS with retry loop
    echo ""
    log_info "Checking wildcard DNS (*.$DOMAIN)..."

    while true; do
        WILDCARD_DOMAIN="wildcard-test-$(date +%s).$DOMAIN"
        wildcard_status=0
        wildcard_result=$(check_dns "$WILDCARD_DOMAIN" "$SERVER_IP" 2>&1) || wildcard_status=$?

        if [ "$wildcard_status" -eq 0 ]; then
            log_info "Wildcard DNS verified: *.$DOMAIN -> $SERVER_IP"
            break
        else
            echo ""
            log_warn "Wildcard DNS not configured for *.$DOMAIN"
            echo ""
            echo "For deploying multiple apps, we recommend adding a wildcard A record:"
            echo "  *.$DOMAIN -> $SERVER_IP"
            echo ""
            echo "Options:"
            echo "  [R] Check again (default)"
            echo "  [C] Continue without wildcard (can add later)"
            echo "  [A] Abort"
            echo ""
            read -rp "Choice [R/c/a]: " choice < /dev/tty
            case "${choice:-r}" in
                r|R|"")
                    echo ""
                    log_info "Checking wildcard DNS again..."
                    ;;
                c|C)
                    log_info "Skipping wildcard DNS (can be added later)"
                    break
                    ;;
                a|A)
                    echo "Aborting."
                    exit 1
                    ;;
                *)
                    echo "Invalid choice. Please enter R, C, or A."
                    ;;
            esac
        fi
    done
fi

# =============================================================================
# STEP 3: Access Restriction
# =============================================================================
log_step "Step 3/5: Access restriction..."

RESTRICTION=""
WHITELIST_IPS=""

if [ "$SKIP_DOMAIN" = true ]; then
    log_info "Local mode - skipping access restriction (no proxy-nginx)"
elif [ "$PROXY_AVAILABLE" = false ]; then
    log_warn "No proxy-nginx - access restriction not available"
elif [ -n "$ARG_RESTRICTION" ]; then
    RESTRICTION="$ARG_RESTRICTION"
    WHITELIST_IPS="$ARG_IPS"
    log_info "Access restriction: $RESTRICTION"
else
    # Interactive restriction selection
    echo ""
    echo "============================================================================="
    echo -e "${BLUE}PocketDev Access Restriction${NC}"
    echo "============================================================================="
    echo ""
    echo "How should access to PocketDev be restricted?"
    echo ""
    echo -e "  ${GREEN}1) Tailscale only (recommended)${NC}"
    echo "     - Only accessible from your Tailscale network (100.64.0.0/10)"
    echo "     - Requires Tailscale to be connected on your device"
    echo "     - Most secure option"
    echo ""
    echo -e "  ${YELLOW}2) IP whitelist${NC}"
    echo "     - Only accessible from specific IP addresses"
    echo "     - Good for static office IPs"
    echo ""
    echo -e "  ${RED}3) No restriction (public)${NC}"
    echo "     - Anyone can access PocketDev"
    echo "     - NOT recommended unless you have other security measures"
    echo ""

    while true; do
        read -rp "Enter choice [1-3]: " choice < /dev/tty
        case $choice in
            1)
                RESTRICTION="tailscale"
                log_info "Selected: Tailscale only"
                break
                ;;
            2)
                RESTRICTION="whitelist"
                echo ""
                echo "Enter IP addresses or CIDR ranges to allow."
                echo "Separate multiple entries with commas."
                echo "Example: 1.2.3.4,10.0.0.0/8"
                echo ""
                while true; do
                    read -rp "IP whitelist: " WHITELIST_IPS < /dev/tty
                    if [ -n "$WHITELIST_IPS" ]; then
                        break
                    fi
                    echo "Please enter at least one IP address."
                done
                log_info "Selected: IP whitelist ($WHITELIST_IPS)"
                break
                ;;
            3)
                RESTRICTION="none"
                echo ""
                echo -e "${RED}WARNING: You are choosing to make PocketDev publicly accessible.${NC}"
                echo ""
                echo "This means ANYONE on the internet can access your PocketDev instance."
                echo "You are responsible for:"
                echo "  - Securing your PocketDev instance"
                echo "  - Setting up authentication"
                echo "  - Monitoring for unauthorized access"
                echo ""
                read -rp "Type 'I ACCEPT' to continue: " confirm < /dev/tty
                if [ "$confirm" = "I ACCEPT" ]; then
                    log_warn "Selected: No restriction (public access)"
                    break
                else
                    echo "Please choose a different option."
                fi
                ;;
            *)
                echo "Invalid choice. Please enter 1, 2, or 3."
                ;;
        esac
    done
fi

# =============================================================================
# STEP 4: Install PocketDev
# =============================================================================
log_step "Step 4/5: Installing PocketDev..."

mkdir -p "$POCKETDEV_DIR"
cd "$POCKETDEV_DIR"

# Download deploy files
VERSION=$(curl -sf "https://api.github.com/repos/$REPO/releases/latest" | grep '"tag_name"' | head -1 | sed 's/.*"\([^"]*\)".*/\1/' | sed 's/^v//')

if [ -z "$VERSION" ]; then
    log_warn "Could not fetch latest version, using main branch"
    VERSION="main"
    DOWNLOAD_URL="https://raw.githubusercontent.com/$REPO/main/deploy"
else
    log_info "Installing PocketDev v${VERSION}..."
    DOWNLOAD_URL="https://raw.githubusercontent.com/$REPO/v${VERSION}/deploy"
fi

# Download deploy files
for file in compose.yml .env.example; do
    curl -fsSL "$DOWNLOAD_URL/$file" -o "$file"
done
log_info "Downloaded PocketDev files"

# Configure .env
if [ ! -f ".env" ]; then
    cp .env.example .env

    # Generate secrets
    APP_KEY="base64:$(openssl rand -base64 32)"
    sedi "s|PD_APP_KEY=|PD_APP_KEY=$APP_KEY|" .env

    DB_PASSWORD=$(openssl rand -hex 16)
    sedi "s|PD_DB_PASSWORD=|PD_DB_PASSWORD=$DB_PASSWORD|" .env

    DB_READONLY_PASSWORD=$(openssl rand -hex 16)
    sedi "s|PD_DB_READONLY_PASSWORD=|PD_DB_READONLY_PASSWORD=$DB_READONLY_PASSWORD|" .env

    DB_MEMORY_AI_PASSWORD=$(openssl rand -hex 16)
    sedi "s|PD_DB_MEMORY_AI_PASSWORD=|PD_DB_MEMORY_AI_PASSWORD=$DB_MEMORY_AI_PASSWORD|" .env

    # Detect Docker GID
    if [ -S /var/run/docker.sock ]; then
        DOCKER_GID=$(get_gid /var/run/docker.sock)
        if [ -n "$DOCKER_GID" ]; then
            sedi "s|PD_DOCKER_GID=|PD_DOCKER_GID=$DOCKER_GID|" .env
        fi
    fi

    # Detect user/group IDs
    USER_ID=$(id -u)
    GROUP_ID=$(id -g)
    sedi "s|PD_USER_ID=.*|PD_USER_ID=$USER_ID|" .env
    sedi "s|PD_GROUP_ID=.*|PD_GROUP_ID=$GROUP_ID|" .env

    # Set project name for container/volume naming
    sedi "s|PD_PROJECT_NAME=.*|PD_PROJECT_NAME=$ARG_NAME|" .env

    log_info "Generated .env with secrets"
else
    log_info ".env already exists, preserving configuration"
fi

# Configure based on mode
if [ "$SKIP_DOMAIN" = true ]; then
    # Local/standalone mode
    sedi "s|PD_NGINX_PORT=.*|PD_NGINX_PORT=$ARG_PORT|" .env
    if [ "$ARG_PORT" = "80" ]; then
        sedi "s|PD_APP_URL=.*|PD_APP_URL=http://localhost|" .env
    else
        sedi "s|PD_APP_URL=.*|PD_APP_URL=http://localhost:$ARG_PORT|" .env
    fi
    log_info "Configured for local mode on port $ARG_PORT"
else
    # Production mode with proxy-nginx
    sedi "s|PD_NGINX_PORT=.*|PD_NGINX_PORT=|" .env
    sedi "s|PD_APP_URL=.*|PD_APP_URL=https://$DOMAIN|" .env
    sedi "s|PD_FORCE_HTTPS=.*|PD_FORCE_HTTPS=true|" .env
    sedi "s|PD_DOMAIN_NAME=.*|PD_DOMAIN_NAME=$DOMAIN|" .env
    sedi "s|PD_DEPLOYMENT_MODE=.*|PD_DEPLOYMENT_MODE=production|" .env
    log_info "Configured for production with domain $DOMAIN"
fi

# Create compose.override.yml for proxy-nginx integration
if [ "$PROXY_AVAILABLE" = true ] && [ "$SKIP_DOMAIN" = false ]; then
    cat > compose.override.yml << 'EOF'
# Override for proxy-nginx integration
# Generated by PocketDev installer

services:
  pocket-dev-nginx:
    networks:
      - pocket-dev
      - main-network

networks:
  main-network:
    external: true
EOF
    log_info "Created compose.override.yml for proxy-nginx"
fi

# Pull and start
log_info "Pulling Docker images (this may take a few minutes)..."
docker compose pull

log_info "Starting services..."
docker compose up -d

# Wait for services
log_info "Waiting for services to start..."
sleep 10

if docker compose ps | grep -q "healthy\|running"; then
    log_info "PocketDev services are running"
else
    log_warn "Services may still be starting. Check with: docker compose ps"
fi

# =============================================================================
# STEP 5: Domain & SSL Setup
# =============================================================================
log_step "Step 5/5: Domain & SSL setup..."

if [ "$SKIP_DOMAIN" = true ]; then
    log_info "Skipped (local mode)"
elif [ "$PROXY_AVAILABLE" = false ]; then
    log_warn "Skipped (no proxy-nginx)"
else
    # Build domain.sh command
    DOMAIN_CMD="docker exec proxy-nginx /scripts/domain.sh upsert"
    DOMAIN_CMD="$DOMAIN_CMD --domain=\"$DOMAIN\""
    DOMAIN_CMD="$DOMAIN_CMD --upstream=pocket-dev-nginx"
    DOMAIN_CMD="$DOMAIN_CMD --max-body-size=2048M"
    DOMAIN_CMD="$DOMAIN_CMD --websocket-timeout=3600s"
    DOMAIN_CMD="$DOMAIN_CMD --comment=\"PocketDev\""

    # Add whitelist if configured
    case "$RESTRICTION" in
        tailscale)
            DOMAIN_CMD="$DOMAIN_CMD --whitelist=\"100.64.0.0/10\""
            log_info "Restricting access to Tailscale network"
            ;;
        whitelist)
            DOMAIN_CMD="$DOMAIN_CMD --whitelist=\"$WHITELIST_IPS\""
            log_info "Restricting access to: $WHITELIST_IPS"
            ;;
        none)
            log_warn "No access restriction - PocketDev will be public"
            ;;
    esac

    # Execute domain.sh
    log_info "Configuring domain in proxy-nginx..."
    eval "$DOMAIN_CMD"

    # Request SSL certificate
    SSL_READY=false
    echo ""
    log_info "Requesting SSL certificate for $DOMAIN..."
    if docker exec proxy-nginx certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email 2>/dev/null; then
        log_info "SSL certificate obtained"
        SSL_READY=true
    else
        log_warn "Automatic SSL failed. Request manually with:"
        echo "  docker exec -it proxy-nginx certbot --nginx -d $DOMAIN"
    fi
fi

# =============================================================================
# Complete
# =============================================================================
echo ""
echo "============================================================================="
echo -e "${GREEN}PocketDev installed successfully!${NC}"
echo "============================================================================="
echo ""
echo "Installed:"
echo "  ✓ PocketDev services running"

if [ "$SKIP_DOMAIN" = true ]; then
    echo "  ✓ Local mode (port $ARG_PORT)"
    echo ""
    echo "Access PocketDev at:"
    echo "  http://localhost:$ARG_PORT"
else
    echo "  ✓ Domain: $DOMAIN"
    case "$RESTRICTION" in
        tailscale)
            echo "  ✓ Access: Tailscale only (100.64.0.0/10)"
            ;;
        whitelist)
            echo "  ✓ Access: IP whitelist ($WHITELIST_IPS)"
            ;;
        none)
            echo "  ⚠ Access: Public (no restriction)"
            ;;
    esac
    if [ "${SSL_READY:-false}" = true ]; then
        echo "  ✓ SSL certificate"
        echo ""
        echo "Access PocketDev at:"
        echo "  https://$DOMAIN"
    else
        echo "  ⚠ SSL certificate not configured"
        echo ""
        echo "Access PocketDev at:"
        echo "  http://$DOMAIN"
        echo ""
        echo "To enable HTTPS, run:"
        echo "  docker exec -it proxy-nginx certbot --nginx -d $DOMAIN"
    fi
fi

echo ""
echo "Useful commands:"
echo "  cd $POCKETDEV_DIR"
echo "  docker compose ps              # Check service status"
echo "  docker compose logs -f         # View logs"
echo "  docker compose pull && docker compose up -d  # Update"
echo ""
echo "============================================================================="
echo ""
