# Docker Containers

Complete reference for all Docker containers in PocketDev.

## Container Summary

| Container | Base Image | Purpose | Ports |
|-----------|------------|---------|-------|
| pocket-dev-proxy | nginx:alpine | Reverse proxy, security | 80 (external) |
| pocket-dev-php | php:8.4-fpm | Laravel app, Claude CLI | 9000 (internal) |
| pocket-dev-nginx | nginx:1.29-alpine | Laravel web server | 80 (internal) |
| pocket-dev-postgres | postgres:17-alpine | Database | 5432 |
| pocket-dev-ttyd | ubuntu:24.04 | Web terminal (deprecated) | 7681 (internal) |

---

## pocket-dev-proxy

**Dockerfile**: `docker-proxy/shared/Dockerfile`

### Responsibilities
- HTTP/HTTPS reverse proxy
- Basic Authentication (htpasswd)
- Optional IP whitelist filtering
- SSL certificate support (certbot)
- WebSocket/SSE streaming support

### Key Configuration

**Environment Variables**:
```env
BASIC_AUTH_USER=admin           # Required
BASIC_AUTH_PASS=your_password   # Required, validated in production
DEPLOYMENT_MODE=local           # local or production
DOMAIN_NAME=localhost           # Server name
IP_WHITELIST=                   # Optional: comma-separated IPs
```

**Entrypoint Actions** (`docker-proxy/shared/entrypoint.sh`):
1. Validate credentials (fails in production with default password)
2. Generate htpasswd file
3. Configure IP whitelist if provided
4. Process nginx.conf.template with `envsubst`
5. Start nginx

### Routing

| Path | Upstream | Notes |
|------|----------|-------|
| `/` | pocket-dev-nginx:80 | Laravel app |
| `/terminal-ws/` | pocket-dev-ttyd:7681 | WebSocket terminal |
| `/health` | Direct response | Health check |

### SSE/Streaming Support
```nginx
proxy_buffering off;        # Critical for SSE
proxy_read_timeout 86400s;  # 24 hours for long streams
```

---

## pocket-dev-php

**Dockerfile**: `docker-laravel/local/php/Dockerfile`

### Responsibilities
- PHP-FPM application server
- Claude Code CLI execution
- Docker socket access (container management)
- Git operations
- Frontend asset building (npm/Vite)

### Installed Software
- PHP 8.4-FPM with extensions: pdo, pdo_pgsql, zip
- Node.js 22.x LTS
- Claude Code CLI (`@anthropic-ai/claude-code`)
- Docker CLI
- GitHub CLI
- Composer

### Key Configuration

**PHP Settings** (`docker-laravel/shared/php/local.ini`):
```ini
upload_max_filesize=40M
post_max_size=40M
memory_limit=256M
max_execution_time=120
```

**User Context**: Runs as `${USER_ID}:${GROUP_ID}` from .env (matches host user)

### Entrypoint Actions (`docker-laravel/local/php/entrypoint.sh`)

1. **Git Setup**: Configure credentials from `GIT_TOKEN`, `GIT_USER_NAME`, `GIT_USER_EMAIL`
2. **Permissions**: Fix storage/, bootstrap/cache/, proxy-config ownership
3. **Laravel Init**: Generate APP_KEY if missing, create storage symlink
4. **Frontend Build**: `npm install && npm run build`
5. **Backend Setup**: `composer install`, `migrate`, `optimize:clear`, `config:cache`
6. **Start**: `exec php-fpm`

### Volume Mounts (Development)
```yaml
- ./www:/var/www                    # Laravel source
- .env:/var/www/.env                # Environment
- /var/run/docker.sock:/var/run/docker.sock  # Docker access
- proxy-config-data:/etc/nginx-proxy-config  # Nginx config
- workspace-data:/workspace          # User projects
```

### Credentials Location
- **Path**: `/var/www/.claude/.credentials.json`
- **Owner**: `www-data:www-data`
- **Permissions**: 0600

---

## pocket-dev-nginx

**Image**: `nginx:1.29-alpine` (stock, no custom Dockerfile)

### Responsibilities
- Internal HTTP server for Laravel
- FastCGI proxy to PHP-FPM
- Static file serving
- SSE streaming support

### Configuration (`docker-laravel/shared/nginx/default.conf`)
```nginx
server {
    listen 80;
    root /var/www/public;

    location ~ \.php$ {
        fastcgi_pass pocket-dev-php:9000;
        fastcgi_buffering off;  # Critical for SSE
    }
}
```

---

## pocket-dev-postgres

**Image**: `postgres:17-alpine`

### Responsibilities
- Session metadata storage
- Model pricing data
- Encrypted application settings
- Standard Laravel tables (cache, jobs, sessions)

### Configuration
```env
POSTGRES_DB=pocket-dev
POSTGRES_USER=pocket-dev
POSTGRES_PASSWORD=${DB_PASSWORD:-laravel}
```

### Data Persistence
Volume: `pocket-dev-postgres` → `/var/lib/postgresql/data`

**Important**: Messages are NOT stored in database. Only metadata.

---

## pocket-dev-ttyd (Deprecated)

**Dockerfile**: `docker-ttyd/shared/Dockerfile`

> **Note**: This container is deprecated. Web chat UI replaces terminal interaction.

### Responsibilities
- Web terminal server (ttyd)
- Direct Claude CLI access
- tmux session management

### Installed Software
- Ubuntu 24.04 base
- ttyd 1.7.7
- Claude Code CLI
- Docker CLI, GitHub CLI
- tmux

### Entrypoint Actions
1. Configure git credentials
2. Set workspace permissions
3. Copy default configs (CLAUDE.md, settings.json, .tmux.conf, agents/)
4. Start ttyd with tmux: `ttyd --port 7681 tmux new-session -A -s main`

### Credentials Location
- **Path**: `/home/devuser/.claude/.credentials.json`
- **Owner**: `devuser:devuser`

**Note**: Credentials are container-specific. TTYD and PHP have different credential files.

---

## Networks

### pocket-dev (Internal)

All containers communicate via Docker DNS:
- `pocket-dev-nginx` → resolves to nginx container IP
- `pocket-dev-php:9000` → PHP-FPM socket

### pocket-dev-public (External)

Only proxy connects to this network. Reserved for:
- Future external upstreams
- Multi-host networking

---

## Volumes

### pocket-dev-workspace

**Purpose**: Shared workspace for user projects

**Mounts**:
- PHP: `/workspace`
- TTYD: `/workspace`

**Note**: User containers created inside TTYD can block volume removal. Stop them before `docker compose down -v`.

### pocket-dev-user

**Purpose**: Persistent user home directories

**Mounts**:
- PHP: `/home/appuser`
- TTYD: `/home/devuser`

**Contains**:
- `.claude/` (config, credentials, sessions)
- `.tmux.conf`
- `.git-credentials`

### pocket-dev-proxy-config

**Purpose**: Editable nginx configuration

**Mounts**:
- Proxy: `/etc/nginx-proxy-config`
- PHP: `/etc/nginx-proxy-config` (for config editor)
- TTYD: `/etc/nginx-proxy-config`

**Contains**: `nginx.conf.template`

---

## Build vs Runtime

### Build Time (Dockerfile)
- Install system packages
- Install PHP extensions
- Install Node.js, Claude CLI
- Copy default configurations
- Set up user/group permissions

### Runtime (Entrypoint)
- Configure git credentials
- Fix file permissions
- Run npm install/build
- Run composer install
- Run migrations
- Start service (php-fpm, nginx, ttyd)

**Key Insight**: Development mode runs npm/composer on every startup to catch code changes. Production images have dependencies pre-installed.

---

## Hard Reset Procedure

When Docker configuration changes require a full rebuild:

```bash
# From: /home/linux/projects/pocket-dev/

# Stop user containers that use workspace volume
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm

# Remove volumes and rebuild
docker compose down -v
docker compose up -d --build
```

**When Required**:
- Modified `docker-ttyd/shared/defaults/`
- Changed Dockerfiles or entrypoint scripts
- Updated nginx templates
- Need fresh volumes

**When NOT Required**:
- Laravel code changes (mounted volume)
- Frontend changes (but need `--force-recreate` for Vite rebuild)
