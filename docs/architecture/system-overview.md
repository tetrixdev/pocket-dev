# System Overview

## Container Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              HOST MACHINE                                │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                    Docker Compose Network                         │   │
│  │                                                                   │   │
│  │   ┌─────────────────────────────────────────────────────────┐    │   │
│  │   │              pocket-dev-proxy (Port 80)                  │    │   │
│  │   │              Nginx Reverse Proxy                         │    │   │
│  │   │              - Basic Auth (required)                     │    │   │
│  │   │              - IP Whitelist (optional)                   │    │   │
│  │   │              - Routes: / → Laravel                       │    │   │
│  │   └─────────────────────┬────────────────────────────────────┘    │   │
│  │                         │                                         │   │
│  │           ┌─────────────▼───────────┐                            │   │
│  │           │  pocket-dev-nginx       │                            │   │
│  │           │  (Internal Server)      │                            │   │
│  │           └─────────┬───────────────┘                            │   │
│  │                     │                                             │   │
│  │           ┌─────────▼───────────┐   ┌───────────────────┐        │   │
│  │           │  pocket-dev-php     │   │  pocket-dev-queue │        │   │
│  │           │  - PHP 8.4-FPM      │   │  (Queue Worker)   │        │   │
│  │           │  - Laravel App      │   └─────────┬─────────┘        │   │
│  │           │  - Claude CLI       │             │                   │   │
│  │           │  - Node.js 22       │             │                   │   │
│  │           │  - Vite (Port 5173) │             │                   │   │
│  │           └─────────┬───────────┘             │                   │   │
│  │                     │                         │                   │   │
│  │           ┌─────────▼───────────┐   ┌────────▼──────────┐        │   │
│  │           │ pocket-dev-postgres │   │  pocket-dev-redis │        │   │
│  │           │   PostgreSQL 17     │   │     Redis 7       │        │   │
│  │           │   (Port 5432)       │   │                   │        │   │
│  │           └─────────────────────┘   └───────────────────┘        │   │
│  └───────────────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────────────┘
```

## Container Details

### pocket-dev-proxy

**Purpose:** Security gateway for all incoming traffic.

**Source files:**
- `docker-proxy/shared/Dockerfile`
- `docker-proxy/shared/entrypoint.sh`
- `docker-proxy/shared/nginx.conf.template`

**Responsibilities:**
- Basic Auth via htpasswd (required, env: `BASIC_AUTH_USER`, `BASIC_AUTH_PASS`)
- IP whitelist (optional, env: `IP_WHITELIST`)
- Route `/` to Laravel (pocket-dev-nginx)
- SSE streaming support (`proxy_buffering off`)
- Maintenance page on 502/503

**Health check:** `curl -f http://localhost:80/health`

### pocket-dev-php

**Purpose:** Laravel application server with Claude CLI.

**Source files:**
- `docker-laravel/local/php/Dockerfile`
- `docker-laravel/local/php/entrypoint.sh`

**Installed software:**
- PHP 8.4-FPM
- Node.js 22
- Claude Code CLI (`@anthropic-ai/claude-code`)
- GitHub CLI
- Docker CLI (socket mounted)
- Composer

**Entrypoint actions:**
1. Set storage permissions
2. Generate Laravel app key (if missing)
3. Create storage symlink
4. Run `npm install` and `npm run build`
5. Run `composer install`
6. Run migrations
7. Start PHP-FPM

**User context:** Runs as host user (via `USER_ID`/`GROUP_ID` env vars)

**Health check:** `php-fpm -t`

### pocket-dev-nginx

**Purpose:** Internal web server for Laravel.

**Source files:**
- `docker-laravel/shared/nginx/default.conf`

Stock nginx:alpine image with custom config. Only accessible via proxy, not directly exposed.

**Health check:** `curl -f http://localhost:80/`

### pocket-dev-postgres

**Purpose:** PostgreSQL database.

Stock postgres:17-alpine image with:
- Database: `pocket-dev`
- User: `pocket-dev`
- Password: from `DB_PASSWORD` env var

**Health check:** `pg_isready -U pocket-dev -d pocket-dev`

### pocket-dev-redis

**Purpose:** Caching and queue backend.

Stock redis:7-alpine image with append-only mode enabled.

**Health check:** `redis-cli ping`

### pocket-dev-queue

**Purpose:** Laravel queue worker for background jobs.

Uses the same image as pocket-dev-php but runs `php artisan queue:work` instead of PHP-FPM.

## Volumes

| Volume | Purpose | Mounted In |
|--------|---------|------------|
| `workspace-data` | Shared workspace for projects | PHP (`/workspace`) |
| `user-data` | User home directory | PHP (`/home/appuser`) |
| `proxy-config-data` | Editable nginx config | Proxy, PHP |
| `postgres-data` | Database persistence | Postgres |
| `redis-data` | Redis persistence | Redis |

## Networks

| Network | Purpose | Containers |
|---------|---------|------------|
| `pocket-dev` | Internal communication | All containers |
| `pocket-dev-public` | External access | Proxy only |

## Data Flow

### Request Flow (Web Chat)

```
Browser (Port 80)
    │
    ▼
pocket-dev-proxy
    │ Basic Auth check
    │ IP whitelist check (if configured)
    │ Route: / → pocket-dev-nginx
    ▼
pocket-dev-nginx
    │ Static files or PHP
    ▼
pocket-dev-php (PHP-FPM)
    │ Laravel handles request
    │ For /api/conversations/* → ConversationController
    ▼
Provider (Anthropic/OpenAI)
    │ Streams response via SSE
    ▼
Response (SSE or JSON)
```

## File Locations

### Credentials

| Path | User |
|------|------|
| `/var/www/.claude/.credentials.json` | www-data |

### Claude Session Files

| Path |
|------|
| `/var/www/.claude/projects/*/` |

Sessions are stored as `.jsonl` files in project-specific directories.

### Configuration Files

| File | Host Path | Container Path |
|------|-----------|----------------|
| Laravel .env | `./` | `/var/www/.env` |
| Claude config | N/A | `/home/appuser/.claude/` |
| Nginx template | `docker-proxy/shared/nginx.conf.template` | `/etc/nginx-proxy-config/nginx.conf.template` |

## Startup Order

Docker Compose enforces this startup order via `depends_on`:

1. **pocket-dev-postgres** (database ready first)
2. **pocket-dev-redis** (cache ready)
3. **pocket-dev-php** (depends on postgres and redis health)
4. **pocket-dev-nginx** (depends on php health)
5. **pocket-dev-proxy** (depends on nginx)
6. **pocket-dev-queue** (depends on postgres and redis)
