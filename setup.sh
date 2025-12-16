#!/bin/bash
set -e

echo "========================================"
echo "  PocketDev Setup (Development)"
echo "========================================"
echo ""

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

# Detect USER_ID and GROUP_ID
USER_ID=$(id -u)
GROUP_ID=$(id -g)
sed -i "s/USER_ID=1000/USER_ID=$USER_ID/" .env
sed -i "s/GROUP_ID=1000/GROUP_ID=$GROUP_ID/" .env
echo "Detected USER_ID=$USER_ID, GROUP_ID=$GROUP_ID"

# Detect DOCKER_GID
if [ -S /var/run/docker.sock ]; then
    DOCKER_GID=$(stat -c '%g' /var/run/docker.sock 2>/dev/null)
    if [ -n "$DOCKER_GID" ]; then
        sed -i "s/# DOCKER_GID=/DOCKER_GID=$DOCKER_GID/" .env
        echo "Detected DOCKER_GID=$DOCKER_GID"
    fi
else
    echo "Warning: Docker socket not found - DOCKER_GID not set"
fi

# Generate APP_KEY
APP_KEY="base64:$(openssl rand -base64 32)"
sed -i "s/APP_KEY=/APP_KEY=$APP_KEY/" .env
echo "Generated APP_KEY"

# Generate DB_PASSWORD
DB_PASSWORD=$(openssl rand -hex 16)
sed -i "s/DB_PASSWORD=/DB_PASSWORD=$DB_PASSWORD/" .env
echo "Generated DB_PASSWORD"

# Ask for NGINX_PORT
echo ""
read -p "HTTP port for PocketDev [80]: " NGINX_PORT
NGINX_PORT=${NGINX_PORT:-80}
sed -i "s/NGINX_PORT=80/NGINX_PORT=$NGINX_PORT/" .env
echo "Set NGINX_PORT=$NGINX_PORT"

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
    echo "First startup may take a few minutes to build containers."
fi
