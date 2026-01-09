#!/bin/bash
set -e

echo "========================================"
echo "  PocketDev Setup"
echo "========================================"
echo ""

# Cross-platform sed -i (works on both GNU and BSD/macOS)
sedi() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "$@"
    else
        sed -i "$@"
    fi
}

# Cross-platform stat for group ID
get_gid() {
    if stat -c '%g' "$1" >/dev/null 2>&1; then
        stat -c '%g' "$1"
    else
        stat -f '%g' "$1" 2>/dev/null
    fi
}

# Check if .env already exists
if [ -f ".env" ]; then
    read -p ".env file already exists. Overwrite? [y/N]: " overwrite
    if [[ ! "$overwrite" =~ ^[Yy]$ ]]; then
        echo "Setup cancelled."
        exit 0
    fi
fi

# Copy template
cp .env.example .env
echo "Created .env from template"

# Generate APP_KEY (use | delimiter since base64 can contain /)
APP_KEY="base64:$(openssl rand -base64 32)"
sedi "s|APP_KEY=|APP_KEY=$APP_KEY|" .env
echo "Generated APP_KEY"

# Generate DB_PASSWORD
DB_PASSWORD=$(openssl rand -hex 16)
sedi "s|DB_PASSWORD=|DB_PASSWORD=$DB_PASSWORD|" .env
echo "Generated DB_PASSWORD"

# Generate DB_READONLY_PASSWORD (for read-only database user)
DB_READONLY_PASSWORD=$(openssl rand -hex 16)
sedi "s|DB_READONLY_PASSWORD=|DB_READONLY_PASSWORD=$DB_READONLY_PASSWORD|" .env
echo "Generated DB_READONLY_PASSWORD"

# Generate DB_MEMORY_AI_PASSWORD (for memory schema DDL/DML user)
DB_MEMORY_AI_PASSWORD=$(openssl rand -hex 16)
sedi "s|DB_MEMORY_AI_PASSWORD=|DB_MEMORY_AI_PASSWORD=$DB_MEMORY_AI_PASSWORD|" .env
echo "Generated DB_MEMORY_AI_PASSWORD"

# Detect DOCKER_GID
if [ -S /var/run/docker.sock ]; then
    DOCKER_GID=$(get_gid /var/run/docker.sock)
    if [ -n "$DOCKER_GID" ]; then
        sedi "s|DOCKER_GID=|DOCKER_GID=$DOCKER_GID|" .env
        echo "Detected DOCKER_GID=$DOCKER_GID"
    fi
else
    echo "Warning: Docker socket not found - DOCKER_GID not set"
fi

# Ask for NGINX_PORT
echo ""
read -p "HTTP port for PocketDev [80]: " NGINX_PORT
NGINX_PORT=${NGINX_PORT:-80}

# Validate port
if ! [[ "$NGINX_PORT" =~ ^[0-9]+$ ]] || [ "$NGINX_PORT" -lt 1 ] || [ "$NGINX_PORT" -gt 65535 ]; then
    echo "Invalid port number. Using default 80."
    NGINX_PORT=80
fi

sedi "s|NGINX_PORT=80|NGINX_PORT=$NGINX_PORT|" .env
echo "Set NGINX_PORT=$NGINX_PORT"

# Set APP_URL based on port (include port only if not 80)
if [ "$NGINX_PORT" = "80" ]; then
    APP_URL="http://localhost"
else
    APP_URL="http://localhost:$NGINX_PORT"
fi
sedi "s|APP_URL=http://localhost|APP_URL=$APP_URL|" .env
echo "Set APP_URL=$APP_URL"

echo ""
echo "========================================"
echo "  Setup Complete!"
echo "========================================"
echo ""
echo "Start PocketDev with:"
echo "  docker compose up -d"
echo ""
echo "Then open: http://localhost:$NGINX_PORT"
echo ""

# Ask if user wants to start now
read -p "Start PocketDev now? [Y/n]: " start_now
if [[ ! "$start_now" =~ ^[Nn]$ ]]; then
    echo ""
    echo "Starting PocketDev..."
    docker compose up -d
    echo ""
    echo "PocketDev is starting! Open http://localhost:$NGINX_PORT"
    echo "First startup may take a few minutes to pull images."
fi

# Clean up setup script (no longer needed)
rm -f setup.sh
