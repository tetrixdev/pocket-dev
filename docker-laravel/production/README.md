# Production Deployment Guide

This folder contains everything needed to deploy **pocket-dev** to production.

## Quick Deployment

1. **Copy this folder to your production server**:
   ```bash
   scp -r docker/production/ user@server:/path/to/deployment/
   cd /path/to/deployment/production/
   ```

2. **Configure environment**:
   ```bash
   # Copy and edit environment file
   cp .env.example .env
   
   # Update these required values:
   # - DB_PASSWORD (change from CHANGE_THIS_PASSWORD)
   # - APP_URL (set your domain)
   nano .env
   ```

3. **Deploy with pre-built images**:
   ```bash
   # Deploy latest version
   docker compose up -d
   
   # Deploy specific version
   IMAGE_TAG=v1.0.0 docker compose up -d
   ```

## What's Included

- `compose.yml` - Production Docker Compose configuration using pre-built images
- `.env.example` - Production environment template
- `README.md` - This deployment guide

## Pre-built Images

Your CI/CD pipeline builds these images automatically when you create GitHub releases:

- `ghcr.io/tetrixdev/pocket-dev-php:latest`
- `ghcr.io/tetrixdev/pocket-dev-nginx:latest`

## Environment Variables

Key production environment variables in `.env`:

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database (update these!)
DB_PASSWORD=CHANGE_THIS_PASSWORD

# Ports (optional)
NGINX_PORT=80
DB_PORT=5432
```

## Deployment Commands

```bash
# Start services
docker compose up -d

# View logs
docker compose logs -f

# Stop services
docker compose down

# Update to latest version
docker compose pull && docker compose up -d

# Deploy specific version
IMAGE_TAG=v1.2.0 docker compose up -d
```

## Health Checks

All services include health checks. Monitor with:

```bash
# Check service health
docker compose ps

# View container logs
docker compose logs pocket-dev-php
docker compose logs pocket-dev-nginx
docker compose logs pocket-dev-postgres
```

## Database Management

```bash
# Run migrations
docker compose exec pocket-dev-php php artisan migrate --force

# Clear caches
docker compose exec pocket-dev-php php artisan optimize:clear

# Access database
docker compose exec pocket-dev-postgres psql -U pocket-dev -d pocket-dev
```

## SSL/HTTPS Setup

This setup runs on HTTP (port 80). For HTTPS in production:

1. **Use a reverse proxy** (nginx, Caddy, Traefik) in front of this stack
2. **Or modify compose.yml** to include SSL certificates

Example with Caddy:
```bash
# Install Caddy on host
# Create Caddyfile:
your-domain.com {
    reverse_proxy localhost:80
}
```

## Troubleshooting

**Container won't start:**
```bash
docker compose logs service-name
```

**Database connection issues:**
- Check `DB_*` variables in `.env`
- Ensure PostgreSQL container is healthy: `docker compose ps`

**Image pull failures:**
- Ensure you have access to `ghcr.io/tetrixdev/pocket-dev-*`
- Login if needed: `docker login ghcr.io`

## Security Notes

- Change `DB_PASSWORD` from the default
- Use strong passwords in production
- Keep images updated via your CI/CD pipeline
- Monitor container security updates

## Support

- Check application logs: `docker compose logs pocket-dev-php`
- Review Laravel logs: `docker compose exec pocket-dev-php tail -f storage/logs/laravel.log`
- Database logs: `docker compose logs pocket-dev-postgres`