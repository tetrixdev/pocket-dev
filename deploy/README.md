# PocketDev - Deployment Package

Pre-built Docker Compose configuration for production deployment.

## Quick Start

### With VPS Setup (Recommended)

If you have a fresh VPS, run vps-setup first:

```bash
# 1. Run vps-setup (installs Docker, proxy-nginx, Tailscale)
curl -fsSL https://raw.githubusercontent.com/tetrixdev/vps-setup/main/setup.sh | bash

# 2. Install PocketDev
curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/install.sh | bash
```

The installer will prompt for your domain and automatically:
- Configure proxy-nginx to route traffic
- Request an SSL certificate
- Set up the necessary network connections

### Non-Interactive Installation

```bash
PD_DOMAIN=pocketdev.example.com curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/install.sh | bash
```

### Standalone Mode (No proxy-nginx)

If you don't have proxy-nginx or want to manage the proxy yourself:

```bash
PD_SKIP_PROXY=true PD_NGINX_PORT=8080 curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/install.sh | bash
```

### Manual Setup

```bash
# Download deploy files
mkdir -p /opt/pocketdev && cd /opt/pocketdev
VERSION=$(curl -sf "https://api.github.com/repos/tetrixdev/pocket-dev/releases/latest" | grep '"tag_name"' | sed 's/.*"\([^"]*\)".*/\1/' | sed 's/^v//')
curl -fsSL "https://raw.githubusercontent.com/tetrixdev/pocket-dev/v${VERSION}/deploy/compose.yml" -o compose.yml
curl -fsSL "https://raw.githubusercontent.com/tetrixdev/pocket-dev/v${VERSION}/deploy/.env.example" -o .env.example

# Run setup
./setup.sh
```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `PD_DOMAIN` | Domain name for PocketDev | (prompted) |
| `PD_MODE` | `production` (Docker images) or `local` (git clone) | `production` |
| `PD_SKIP_PROXY` | Skip proxy-nginx integration | `false` |
| `PD_NGINX_PORT` | Port for standalone mode | `80` |

## Updates

```bash
cd /opt/pocketdev

# Update to latest
docker compose pull && docker compose up -d

# Deploy specific version
PD_IMAGE_TAG=v1.4.0 docker compose pull && docker compose up -d
```

## Backup

```bash
# Database backup
docker compose exec pocket-dev-postgres pg_dump -U pocket-dev pocket-dev > backup-$(date +%Y%m%d).sql

# Database restore
docker compose exec -i pocket-dev-postgres psql -U pocket-dev -d pocket-dev < backup.sql

# Workspace backup (user files)
docker run --rm -v pocket-dev-workspace:/data -v $(pwd):/backup alpine tar czf /backup/workspace-$(date +%Y%m%d).tar.gz -C /data .
```

## Troubleshooting

```bash
# Check service health
docker compose ps

# View logs
docker compose logs -f [service-name]

# Restart services
docker compose restart [service-name]

# Check proxy-nginx routing
docker exec proxy-nginx /scripts/domain.sh list
```

## Files

| File | Purpose |
|------|---------|
| `compose.yml` | Main Docker Compose configuration |
| `compose.override.yml` | Proxy-nginx integration (auto-generated) |
| `.env` | Environment configuration |
| `.env.example` | Template for .env |
