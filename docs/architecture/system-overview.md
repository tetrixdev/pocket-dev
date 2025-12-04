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
│  │   │              - Routes: / → Laravel, /terminal-ws/ → TTYD │    │   │
│  │   └─────────────────────┬───────────────┬────────────────────┘    │   │
│  │                         │               │                         │   │
│  │           ┌─────────────▼───────┐   ┌───▼───────────────┐        │   │
│  │           │  pocket-dev-nginx   │   │  pocket-dev-ttyd  │        │   │
│  │           │  (Internal Server)  │   │  (Web Terminal)   │        │   │
│  │           └─────────┬───────────┘   │  - Claude CLI     │        │   │
│  │                     │               │  - tmux sessions  │        │   │
│  │           ┌─────────▼───────────┐   │  - Port 7681      │        │   │
│  │           │  pocket-dev-php     │   └───────────────────┘        │   │
│  │           │  - PHP 8.4-FPM      │                                 │   │
│  │           │  - Laravel App      │                                 │   │
│  │           │  - Claude CLI       │                                 │   │
│  │           │  - Node.js 22       │                                 │   │
│  │           │  - Vite (Port 5173) │                                 │   │
│  │           └─────────┬───────────┘                                 │   │
│  │                     │                                             │   │
│  │           ┌─────────▼───────────┐                                 │   │
│  │           │ pocket-dev-postgres │                                 │   │
│  │           │   PostgreSQL 17     │                                 │   │
│  │           │   (Port 5432)       │                                 │   │
│  │           └─────────────────────┘                                 │   │
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
- Route `/terminal-ws/` to TTYD (with WebSocket support)
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
1. Configure git credentials (if `GIT_TOKEN`, `GIT_USER_NAME`, `GIT_USER_EMAIL` provided)
2. Set storage permissions
3. Generate Laravel app key (if missing)
4. Create storage symlink
5. Run `npm install` and `npm run build`
6. Run `composer install`
7. Run migrations
8. Start PHP-FPM

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

### pocket-dev-ttyd

**Purpose:** Web terminal for direct CLI access.

**Source files:**
- `docker-ttyd/shared/Dockerfile`
- `docker-ttyd/shared/entrypoint.sh`
- `docker-ttyd/shared/defaults/` (default config files)

**Installed software:**
- Ubuntu 24.04 base
- Claude Code CLI
- Node.js 22
- Docker CLI
- GitHub CLI
- tmux
- TTYD (web terminal server)

**Entrypoint actions:**
1. Configure git credentials
2. Initialize default Claude config files (CLAUDE.md, settings.json, agents)
3. Start TTYD with tmux (`ttyd tmux -u new-session -A -s main`)

**User context:** Runs as `devuser` (mapped to host user via `USER_ID`/`GROUP_ID`)

**Health check:** `curl -f http://localhost:7681/`

## Volumes

| Volume | Purpose | Mounted In |
|--------|---------|------------|
| `workspace-data` | Shared workspace for projects | PHP (`/workspace`), TTYD (`/workspace`) |
| `user-data` | User home directories | PHP (`/home/appuser`), TTYD (`/home/devuser`) |
| `proxy-config-data` | Editable nginx config | Proxy, PHP |
| `postgres-data` | Database persistence | Postgres |

**Note:** `workspace-data` and `user-data` are shared between PHP and TTYD containers, enabling both to access the same projects and Claude configurations.

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
    │ For /api/claude/* → ClaudeController
    ▼
ClaudeCodeService
    │ proc_open('claude --print ...')
    ▼
Claude CLI
    │ Reads/writes ~/.claude/projects/*/
    │ Returns JSON/streaming output
    ▼
Response (SSE or JSON)
```

### Request Flow (Web Terminal)

```
Browser (Port 80)
    │
    ▼
pocket-dev-proxy
    │ Basic Auth check
    │ Route: /terminal-ws/* → pocket-dev-ttyd
    │ WebSocket upgrade
    ▼
pocket-dev-ttyd (TTYD)
    │ tmux session
    ▼
User shell (bash)
    │ Can run: claude, git, docker, etc.
```

## File Locations

### Credentials (Container-Specific)

| Container | Path | User |
|-----------|------|------|
| TTYD | `/home/devuser/.claude/.credentials.json` | devuser |
| PHP | `/var/www/.claude/.credentials.json` | www-data |

**Critical:** These are separate files. Authentication in one container does NOT authenticate the other.

### Claude Session Files

| Container | Path |
|-----------|------|
| TTYD | `/home/devuser/.claude/projects/*/` |
| PHP | `/var/www/.claude/projects/*/` |

Sessions are stored as `.jsonl` files in project-specific directories.

### Configuration Files

| File | Host Path | Container Path |
|------|-----------|----------------|
| Laravel .env | `./` | `/var/www/.env` |
| Claude config | N/A | `/home/devuser/.claude/` (TTYD) |
| Nginx template | `docker-proxy/shared/nginx.conf.template` | `/etc/nginx-proxy-config/nginx.conf.template` |

## Startup Order

Docker Compose enforces this startup order via `depends_on`:

1. **pocket-dev-postgres** (database ready first)
2. **pocket-dev-php** (depends on postgres health)
3. **pocket-dev-nginx** (depends on php health)
4. **pocket-dev-ttyd** (independent, starts in parallel)
5. **pocket-dev-proxy** (depends on nginx and ttyd)
