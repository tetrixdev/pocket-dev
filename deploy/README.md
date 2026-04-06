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
curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/install.sh | bash -s -- --domain=pocketdev.example.com --restriction=tailscale
```

### Local Mode (No proxy-nginx)

If you don't have proxy-nginx or want to run PocketDev locally:

```bash
curl -fsSL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/install.sh | bash -s -- --local --port=8080
```

### Manual Setup

```bash
# Download deploy files
mkdir -p /docker-apps/pocket-dev && cd /docker-apps/pocket-dev
VERSION=$(curl -sf "https://api.github.com/repos/tetrixdev/pocket-dev/releases/latest" | grep '"tag_name"' | sed 's/.*"\([^"]*\)".*/\1/' | sed 's/^v//')
curl -fsSL "https://raw.githubusercontent.com/tetrixdev/pocket-dev/v${VERSION}/deploy/compose.yml" -o compose.yml
curl -fsSL "https://raw.githubusercontent.com/tetrixdev/pocket-dev/v${VERSION}/deploy/.env.example" -o .env.example

# Run setup
./setup.sh
```

## Installation Options

| Option | Description | Default |
|--------|-------------|---------|
| `--domain=DOMAIN` | Domain for PocketDev | (prompted) |
| `--restriction=MODE` | Access restriction: `tailscale`, `whitelist`, `none` | (prompted) |
| `--ips=IPS` | IP whitelist (comma-separated) | - |
| `--local` | Local mode - skip domain/SSL setup | `false` |
| `--port=PORT` | Port for local mode | `80` |
| `--skip-dns-check` | Skip DNS verification | `false` |

## Updates

```bash
cd /docker-apps/pocket-dev

# Update to latest
docker compose pull && docker compose up -d

# Deploy specific version
export PD_IMAGE_TAG=v1.4.0
docker compose pull && docker compose up -d
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
