#!/bin/bash
# =============================================================================
# PocketDev Installation Script
# =============================================================================
#
# Installs PocketDev on a server that has already run vps-setup.
#
# USAGE:
#   curl -fsSL https://pocketdev.io/install | bash
#
# ENVIRONMENT VARIABLES:
#   PD_DOMAIN          - Domain name for PocketDev (e.g., pocketdev.example.com)
#   PD_MODE            - "production" (Docker images) or "local" (git clone)
#   PD_SKIP_PROXY      - Set to "true" to skip proxy-nginx integration
#   PD_NGINX_PORT      - Port for standalone mode (default: 80)
#
# EXAMPLES:
#   # Interactive installation (prompts for domain)
#   curl -fsSL https://pocketdev.io/install | bash
#
#   # Non-interactive with domain
#   PD_DOMAIN=pocketdev.example.com curl -fsSL https://pocketdev.io/install | bash
#
#   # Standalone mode (no proxy-nginx integration)
#   PD_SKIP_PROXY=true PD_NGINX_PORT=8080 curl -fsSL https://pocketdev.io/install | bash
#
# =============================================================================

set -euo pipefail

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
POCKETDEV_DIR="${POCKETDEV_DIR:-/opt/pocketdev}"
REPO="tetrixdev/pocket-dev"
PD_MODE="${PD_MODE:-production}"
PD_DOMAIN="${PD_DOMAIN:-}"
PD_SKIP_PROXY="${PD_SKIP_PROXY:-false}"
PD_NGINX_PORT="${PD_NGINX_PORT:-80}"

# -----------------------------------------------------------------------------
# Colors and logging
# -----------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} ${1:-}"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} ${1:-}"; }
log_error() { echo -e "${RED}[ERROR]${NC} ${1:-}"; }
log_step() { echo -e "\n${BLUE}==>${NC} ${1:-}"; }

# -----------------------------------------------------------------------------
# Cross-platform helpers
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

# -----------------------------------------------------------------------------
# Pre-flight checks
# -----------------------------------------------------------------------------
log_step "Running pre-flight checks..."

# Check for Docker
if ! command -v docker &> /dev/null; then
    log_error "Docker is not installed."
    echo ""
    echo "Run vps-setup first to install Docker and proxy-nginx:"
    echo "  curl -fsSL https://raw.githubusercontent.com/tetrixdev/vps-setup/main/setup.sh | bash"
    exit 1
fi

# Check for proxy-nginx (unless skipping)
PROXY_AVAILABLE=false
if [ "$PD_SKIP_PROXY" != "true" ]; then
    if docker ps --format '{{.Names}}' | grep -q '^proxy-nginx$'; then
        PROXY_AVAILABLE=true
        log_info "proxy-nginx detected - will integrate with reverse proxy"
    else
        log_warn "proxy-nginx not found - running in standalone mode"
        log_warn "PocketDev will be accessible on port ${PD_NGINX_PORT}"
        PD_SKIP_PROXY="true"
    fi
else
    log_info "Skipping proxy-nginx integration (PD_SKIP_PROXY=true)"
fi

# Check for main-network (if using proxy-nginx)
if [ "$PROXY_AVAILABLE" = true ]; then
    if ! docker network ls --format '{{.Name}}' | grep -q '^main-network$'; then
        log_info "Creating main-network..."
        docker network create main-network
    fi
fi

# -----------------------------------------------------------------------------
# Prompt for domain (if using proxy-nginx and not set)
# -----------------------------------------------------------------------------
if [ "$PROXY_AVAILABLE" = true ] && [ -z "$PD_DOMAIN" ]; then
    echo ""
    echo "Enter the domain name for PocketDev (e.g., pocketdev.example.com)"
    echo "DNS must already point to this server's IP address."
    echo ""
    read -p "Domain: " PD_DOMAIN

    if [ -z "$PD_DOMAIN" ]; then
        log_error "Domain is required when using proxy-nginx"
        log_error "Set PD_DOMAIN environment variable or enter a domain when prompted"
        exit 1
    fi
fi

# -----------------------------------------------------------------------------
# Show what will happen
# -----------------------------------------------------------------------------
echo ""
echo "============================================================================="
echo "  PocketDev Installation"
echo "============================================================================="
echo ""
echo "  Mode:      $PD_MODE"
if [ "$PROXY_AVAILABLE" = true ]; then
    echo "  Domain:    $PD_DOMAIN"
    echo "  Proxy:     proxy-nginx (ports 80/443)"
else
    echo "  Port:      $PD_NGINX_PORT"
    echo "  Proxy:     Standalone (no proxy-nginx)"
fi
echo "  Directory: $POCKETDEV_DIR"
echo ""

# =============================================================================
# STEP 1: Download PocketDev
# =============================================================================
log_step "Step 1/4: Downloading PocketDev..."

mkdir -p "$POCKETDEV_DIR"
cd "$POCKETDEV_DIR"

if [ "$PD_MODE" = "local" ]; then
    # Local development mode - clone repository
    if [ ! -d ".git" ]; then
        log_info "Cloning repository for local development..."
        git clone https://github.com/$REPO.git .
    else
        log_info "Repository already exists, pulling latest..."
        git pull
    fi

    # Copy deploy files
    cp deploy/compose.yml .
    cp deploy/.env.example .
else
    # Production mode - download deploy files
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
fi

log_info "Downloaded PocketDev files"

# =============================================================================
# STEP 2: Configure .env
# =============================================================================
log_step "Step 2/4: Configuring environment..."

# Create .env from template (only if not exists)
if [ ! -f ".env" ]; then
    cp .env.example .env

    # Generate secrets
    APP_KEY="base64:$(openssl rand -base64 32)"
    sedi "s|PD_APP_KEY=|PD_APP_KEY=$APP_KEY|" .env
    log_info "Generated PD_APP_KEY"

    DB_PASSWORD=$(openssl rand -hex 16)
    sedi "s|PD_DB_PASSWORD=|PD_DB_PASSWORD=$DB_PASSWORD|" .env
    log_info "Generated PD_DB_PASSWORD"

    DB_READONLY_PASSWORD=$(openssl rand -hex 16)
    sedi "s|PD_DB_READONLY_PASSWORD=|PD_DB_READONLY_PASSWORD=$DB_READONLY_PASSWORD|" .env
    log_info "Generated PD_DB_READONLY_PASSWORD"

    DB_MEMORY_AI_PASSWORD=$(openssl rand -hex 16)
    sedi "s|PD_DB_MEMORY_AI_PASSWORD=|PD_DB_MEMORY_AI_PASSWORD=$DB_MEMORY_AI_PASSWORD|" .env
    log_info "Generated PD_DB_MEMORY_AI_PASSWORD"

    # Detect Docker GID
    if [ -S /var/run/docker.sock ]; then
        DOCKER_GID=$(get_gid /var/run/docker.sock)
        if [ -n "$DOCKER_GID" ]; then
            sedi "s|PD_DOCKER_GID=|PD_DOCKER_GID=$DOCKER_GID|" .env
            log_info "Detected PD_DOCKER_GID=$DOCKER_GID"
        fi
    fi

    # Detect user/group IDs
    USER_ID=$(id -u)
    GROUP_ID=$(id -g)
    sedi "s|PD_USER_ID=|PD_USER_ID=$USER_ID|" .env
    sedi "s|PD_GROUP_ID=|PD_GROUP_ID=$GROUP_ID|" .env
    log_info "Detected PD_USER_ID=$USER_ID, PD_GROUP_ID=$GROUP_ID"
else
    log_info ".env already exists, preserving existing configuration"
fi

# Configure based on proxy mode
if [ "$PROXY_AVAILABLE" = true ]; then
    # Using proxy-nginx - disable direct port exposure
    sedi "s|PD_NGINX_PORT=.*|PD_NGINX_PORT=|" .env
    sedi "s|PD_APP_URL=.*|PD_APP_URL=https://$PD_DOMAIN|" .env
    sedi "s|PD_FORCE_HTTPS=.*|PD_FORCE_HTTPS=true|" .env
    sedi "s|PD_DOMAIN_NAME=.*|PD_DOMAIN_NAME=$PD_DOMAIN|" .env
    sedi "s|PD_DEPLOYMENT_MODE=.*|PD_DEPLOYMENT_MODE=production|" .env
    log_info "Configured for proxy-nginx integration"
else
    # Standalone mode - expose port directly
    sedi "s|PD_NGINX_PORT=.*|PD_NGINX_PORT=$PD_NGINX_PORT|" .env
    if [ "$PD_NGINX_PORT" = "80" ]; then
        sedi "s|PD_APP_URL=.*|PD_APP_URL=http://localhost|" .env
    else
        sedi "s|PD_APP_URL=.*|PD_APP_URL=http://localhost:$PD_NGINX_PORT|" .env
    fi
    log_info "Configured for standalone mode on port $PD_NGINX_PORT"
fi

# =============================================================================
# STEP 3: Start PocketDev
# =============================================================================
log_step "Step 3/4: Starting PocketDev..."

# Modify compose.yml for proxy-nginx integration
if [ "$PROXY_AVAILABLE" = true ]; then
    # Remove port mapping from pocket-dev-proxy (we'll use proxy-nginx)
    # Add main-network to pocket-dev-nginx

    # Create a compose.override.yml for proxy-nginx integration
    cat > compose.override.yml << 'EOF'
# Override for proxy-nginx integration
# Generated by PocketDev installer

services:
  pocket-dev-proxy:
    # Don't expose ports - proxy-nginx handles external traffic
    ports: []

  pocket-dev-nginx:
    # Connect to main-network for proxy-nginx access
    networks:
      - pocket-dev
      - main-network

networks:
  main-network:
    external: true
EOF
    log_info "Created compose.override.yml for proxy-nginx integration"
fi

# Pull and start
log_info "Pulling Docker images (this may take a few minutes)..."
docker compose pull

log_info "Starting services..."
docker compose up -d

# Wait for services to be healthy
log_info "Waiting for services to start..."
sleep 10

# Check if services are running
if docker compose ps | grep -q "healthy"; then
    log_info "Services are running"
else
    log_warn "Services may still be starting. Check with: docker compose ps"
fi

# =============================================================================
# STEP 4: Configure proxy-nginx
# =============================================================================
if [ "$PROXY_AVAILABLE" = true ]; then
    log_step "Step 4/4: Configuring proxy-nginx..."

    # Add domain to proxy-nginx
    log_info "Adding domain $PD_DOMAIN to proxy-nginx..."
    docker exec proxy-nginx /scripts/domain.sh upsert \
        --domain="$PD_DOMAIN" \
        --upstream=pocket-dev-nginx \
        --max-body-size=2048M \
        --websocket-timeout=3600s \
        --comment="PocketDev"

    log_info "Domain configured in proxy-nginx"

    # Request SSL certificate
    echo ""
    log_info "Requesting SSL certificate for $PD_DOMAIN..."
    echo ""
    docker exec -it proxy-nginx certbot --nginx -d "$PD_DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email || {
        log_warn "Automatic SSL failed. You can request manually:"
        echo "  docker exec -it proxy-nginx certbot --nginx -d $PD_DOMAIN"
    }
else
    log_step "Step 4/4: Skipped (no proxy-nginx)"
fi

# =============================================================================
# Complete
# =============================================================================
echo ""
echo "============================================================================="
echo -e "${GREEN}PocketDev installed successfully!${NC}"
echo "============================================================================="
echo ""
echo "Installation:"
echo "  ✓ PocketDev services running"
if [ "$PROXY_AVAILABLE" = true ]; then
    echo "  ✓ Domain configured: $PD_DOMAIN"
    echo "  ✓ SSL certificate requested"
    echo ""
    echo "Access PocketDev at:"
    echo "  https://$PD_DOMAIN"
else
    echo "  ✓ Running in standalone mode"
    echo ""
    echo "Access PocketDev at:"
    echo "  http://localhost:$PD_NGINX_PORT"
fi
echo ""
echo "Useful commands:"
echo "  cd $POCKETDEV_DIR"
echo "  docker compose ps          # Check service status"
echo "  docker compose logs -f     # View logs"
echo "  docker compose pull && docker compose up -d  # Update"
echo ""
echo "============================================================================="
