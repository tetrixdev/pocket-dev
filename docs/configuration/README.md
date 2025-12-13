# Configuration

Configuration files and environment variables for PocketDev.

## Environment Variables

### Required

| Variable | Description | Example |
|----------|-------------|---------|
| `BASIC_AUTH_USER` | Basic auth username | `admin` |
| `BASIC_AUTH_PASS` | Basic auth password | `secretpassword` |
| `DB_PASSWORD` | PostgreSQL password | `laravel` |

### Optional

| Variable | Default | Description |
|----------|---------|-------------|
| `NGINX_PORT` | `80` | External port for proxy |
| `VITE_PORT` | `5173` | Vite dev server port |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DEPLOYMENT_MODE` | `local` | `local` or `production` |
| `DOMAIN_NAME` | `localhost` | Server domain |
| `IP_WHITELIST` | (empty) | Comma-separated allowed IPs |

### Git Integration

| Variable | Description |
|----------|-------------|
| `GIT_TOKEN` | GitHub personal access token |
| `GIT_USER_NAME` | Git commit author name |
| `GIT_USER_EMAIL` | Git commit author email |

### Claude Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `CLAUDE_BINARY_PATH` | `claude` | Path to Claude CLI |
| `CLAUDE_TIMEOUT` | `300` | Command timeout (seconds) |
| `CLAUDE_MODEL` | `claude-sonnet-4-5-20250929` | Default model |
| `CLAUDE_MAX_TURNS` | `100` | Max conversation turns |

## Configuration Files

### Laravel (`config/claude.php`)

Claude Code service configuration:

```php
return [
    'binary_path' => env('CLAUDE_BINARY_PATH', 'claude'),
    'timeout' => env('CLAUDE_TIMEOUT', 300),
    'model' => env('CLAUDE_MODEL', 'claude-sonnet-4-5-20250929'),
    'max_turns' => env('CLAUDE_MAX_TURNS', 100),
];
```

### Nginx Proxy (`docker-proxy/shared/nginx.conf.template`)

Reverse proxy configuration with:
- Basic auth enforcement
- IP whitelist support
- SSE streaming (`proxy_buffering off`)

**Editable at runtime:** `/etc/nginx-proxy-config/nginx.conf.template`

### Docker Compose (`compose.yml`)

Container orchestration with:
- Service definitions
- Volume mounts
- Network configuration
- Health checks
- Environment variable mapping

## Claude Configuration (PHP Container)

Files in `/home/appuser/.claude/`:

### CLAUDE.md

Project instructions for Claude CLI. Editable via `/config/claude`.

### settings.json

Claude settings including:
- Default model
- Allowed tools
- Hooks configuration

Editable via `/config/settings` (raw JSON) or `/config/hooks` (hooks only).

### agents/

Custom agent definitions (`.md` files). Managed via `/config/agents`.

### commands/

Custom slash commands (`.md` files). Managed via `/config/commands`.

### skills/

Custom skills (`.md` files). Managed via `/config/skills`.

## .env Example

```env
# Required
BASIC_AUTH_USER=admin
BASIC_AUTH_PASS=your_secure_password
DB_PASSWORD=your_db_password

# Optional - Ports
NGINX_PORT=80
VITE_PORT=5173
DB_PORT=5432

# Optional - User mapping (for file permissions)
USER_ID=1000
GROUP_ID=1000

# Optional - Deployment
DEPLOYMENT_MODE=local
DOMAIN_NAME=localhost

# Optional - IP whitelist (comma-separated)
# IP_WHITELIST=192.168.1.100,10.0.0.50

# Optional - Git
GIT_TOKEN=ghp_xxxxxxxxxxxxx
GIT_USER_NAME=Your Name
GIT_USER_EMAIL=you@example.com

# Optional - Claude
CLAUDE_MODEL=claude-sonnet-4-5-20250929
CLAUDE_TIMEOUT=300

# Laravel
APP_NAME=PocketDev
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=pgsql
DB_HOST=pocket-dev-postgres
DB_PORT=5432
DB_DATABASE=pocket-dev
DB_USERNAME=pocket-dev
```

## Sensitive Files

**Never commit:**
- `.env` (use `.env.example` as template)
- `.credentials.json` (Claude OAuth tokens)
- Any API keys

**Git ignore:**
```
.env
*.credentials.json
```
