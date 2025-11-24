# Development Commands

All commands run from `/home/linux/projects/pocket-dev/` on the HOST.

## Docker Commands

### Start Containers

```bash
# First time or after Dockerfile changes
docker compose up -d --build

# Normal start
docker compose up -d
```

### Hard Reset

Use when Docker configuration changes:

```bash
# Stop containers using workspace volume (user containers)
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm

# Remove volumes and rebuild
docker compose down -v
docker compose up -d --build
```

**One-liner**:
```bash
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop && \
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm && \
docker compose down -v && docker compose up -d --build
```

### Single Service Rebuild

```bash
# Rebuild specific container
docker compose up -d --build pocket-dev-php

# Restart without rebuild
docker compose restart pocket-dev-proxy
```

### Frontend Rebuild

```bash
# Restart to trigger npm build in entrypoint
docker compose up -d --force-recreate
```

---

## Laravel Commands

All Laravel commands run inside PHP container:

### Artisan

```bash
# General artisan
docker compose exec pocket-dev-php php artisan <command>

# Common commands
docker compose exec pocket-dev-php php artisan migrate
docker compose exec pocket-dev-php php artisan make:controller YourController
docker compose exec pocket-dev-php php artisan route:list
docker compose exec pocket-dev-php php artisan tinker
docker compose exec pocket-dev-php php artisan optimize:clear
docker compose exec pocket-dev-php php artisan config:cache
docker compose exec pocket-dev-php php artisan view:clear
```

### Composer

```bash
docker compose exec pocket-dev-php composer install
docker compose exec pocket-dev-php composer require package/name
docker compose exec pocket-dev-php composer dump-autoload -o
```

### NPM

```bash
docker compose exec pocket-dev-php npm install
docker compose exec pocket-dev-php npm run build
docker compose exec pocket-dev-php npm run dev  # Vite dev server (single domain only)
```

---

## Database Commands

### PostgreSQL Access

```bash
# Interactive psql
docker compose exec pocket-dev-postgres psql -U pocket-dev -d pocket-dev

# Run query
docker compose exec pocket-dev-postgres psql -U pocket-dev -d pocket-dev -c "SELECT * FROM claude_sessions;"
```

### Migrations

```bash
docker compose exec pocket-dev-php php artisan migrate
docker compose exec pocket-dev-php php artisan migrate:rollback
docker compose exec pocket-dev-php php artisan migrate:fresh  # WARNING: Drops all tables
```

---

## Debugging Commands

### View Logs

```bash
# All containers
docker compose logs -f

# Specific container
docker compose logs -f pocket-dev-php
docker compose logs -f pocket-dev-proxy
docker compose logs -f pocket-dev-ttyd

# Laravel logs
docker compose exec pocket-dev-php tail -f storage/logs/laravel.log
```

### Container Access

```bash
# Shell access
docker exec -it pocket-dev-php bash
docker exec -it pocket-dev-ttyd bash

# As specific user
docker exec -it -u www-data pocket-dev-php bash
```

### Volume Inspection

```bash
# List volume contents
docker run --rm -v pocket-dev-workspace:/data alpine ls -la /data

# Inspect volume metadata
docker volume inspect pocket-dev-workspace
```

---

## Claude Code Testing

### Test CLI in PHP Container

```bash
# Direct CLI test
docker exec pocket-dev-php bash -c 'echo "What is 5+3?" | claude --print --output-format json'

# Check credentials
docker exec pocket-dev-php cat /var/www/.claude/.credentials.json

# Check CLI version
docker exec pocket-dev-php claude --version
```

### Copy Credentials Between Containers

If authenticated in TTYD but need PHP access:

```bash
docker cp pocket-dev-ttyd:/home/devuser/.claude pocket-dev-php:/var/www/.claude
docker exec pocket-dev-php chown -R www-data:www-data /var/www/.claude
```

---

## Testing API

### Authentication

```bash
# Check auth status
curl -u admin:your_password http://localhost/claude/auth/status

# Upload credentials
curl -u admin:your_password -X POST http://localhost/claude/auth/upload-json \
  -H "Content-Type: application/json" \
  -d '{"json":"{\"claudeAiOauth\":{\"accessToken\":\"...\",\"refreshToken\":\"...\",\"expiresAt\":...}}"}'
```

### Sessions

```bash
# Create session
curl -u admin:your_password -X POST http://localhost/api/claude/sessions \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","project_path":"/var/www"}'

# Stream query (SSE)
curl -u admin:your_password -N -X POST http://localhost/api/claude/sessions/1/stream \
  -H "Content-Type: application/json" \
  -d '{"prompt":"What is 5+3?"}'
```

---

## Production Deployment

### Build and Push Images

```bash
# Build production images
docker build -f docker-laravel/production/php/Dockerfile -t ghcr.io/tetrixdev/pocket-dev-php:latest .
docker build -f docker-proxy/shared/Dockerfile -t ghcr.io/tetrixdev/pocket-dev-proxy:latest .

# Push to registry
docker push ghcr.io/tetrixdev/pocket-dev-php:latest
docker push ghcr.io/tetrixdev/pocket-dev-proxy:latest
```

### Deploy

```bash
cd deploy/
cp .env.example .env
# Edit .env with production values

docker compose up -d
```
