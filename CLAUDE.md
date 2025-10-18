# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PocketDev is a **Docker-based Laravel development environment** with integrated Claude Code AI capabilities. The project serves two audiences:

1. **End Users**: Developers who use PocketDev as their development environment
2. **Contributors**: Developers working on PocketDev itself (you are in this context)

This file focuses on **contributing to PocketDev** - modifying the platform's infrastructure, not using it.

## Architecture

### Multi-Container System

Five interconnected Docker containers:

- **pocket-dev-proxy**: Nginx reverse proxy (security layer: Basic Auth + IP whitelist + SSL)
- **pocket-dev-php**: Laravel app with PHP 8.4-FPM + Claude Code CLI + Node.js 22
- **pocket-dev-nginx**: Laravel web server (internal)
- **pocket-dev-postgres**: PostgreSQL 17 database
- **pocket-dev-ttyd**: Web terminal with development tools

### Claude Code Integration Architecture

**Two execution contexts for Claude Code:**

1. **TTYD Container** (`/terminal-ws/`): Web terminal where users run `claude` CLI directly
2. **PHP Container** (Laravel web app): Backend service that executes Claude CLI via PHP

**Key Insight**: Credentials are container-specific. If a user authenticates in TTYD, the PHP container needs credentials copied:
```bash
docker cp pocket-dev-ttyd:/home/devuser/.claude pocket-dev-php:/var/www/.claude
docker exec pocket-dev-php chown -R www-data:www-data /var/www/.claude
```

### Laravel Application Structure

**Claude Code Integration Components:**

- `app/Services/ClaudeCodeService.php` - Core service that wraps Claude CLI
  - Uses `proc_open()` to execute `claude --print --output-format json`
  - Handles both sync and streaming responses
  - Credentials path: `/var/www/.claude/.credentials.json` (www-data user)

- `app/Models/ClaudeSession.php` - Database model for chat sessions
  - Stores conversation history as JSON in `messages` column
  - Tracks turn count, project path, model used

- `app/Http/Controllers/Api/ClaudeController.php` - RESTful API
  - 7 endpoints: list/create/show/delete sessions, query, stream, status
  - Returns Claude's JSON response structure with `result` field

- `app/Http/Controllers/ClaudeAuthController.php` - Authentication management
  - Upload credentials file or paste JSON
  - Shows auth status (subscription type, expiry, scopes)
  - Validates credential structure: `claudeAiOauth` with `accessToken`, `refreshToken`, `expiresAt`

- `app/Livewire/ClaudeChat.php` - Livewire component (alternative to vanilla JS)
- `public/chat.html` - Vanilla JavaScript chat interface (currently used)

**Configuration:**

- `config/claude.php` - Claude Code settings (model, tools, permissions, timeout)
- Migration: `2025_10_16_201350_create_claude_sessions_table.php`

**Routes:**

Web routes (in order - placement matters):
```
/claude/auth - Authentication management page
/claude/auth/status - GET auth status (JSON)
/claude/auth/upload - POST credentials file
/claude/auth/upload-json - POST credentials JSON
/claude/auth/logout - DELETE credentials
/ - Claude chat (Livewire, redirects to /claude/auth if not authenticated)
/claude/{sessionId?} - Session-specific chat
/terminal - TTYD web terminal
/config - Config file editor
```

API routes (no auth required from internal):
```
/api/claude/status - CLI availability check
/api/claude/sessions - CRUD operations
/api/claude/sessions/{session}/query - Synchronous query
/api/claude/sessions/{session}/stream - Streaming query (SSE)
```

## Development Commands

### Working on PocketDev Infrastructure

**Hard Reset** (after changing Docker images, entrypoints, or defaults):
```bash
# Quick one-liner
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop && \
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm && \
docker compose down -v && docker compose up -d --build

# Or step-by-step
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm
docker compose down -v
docker compose up -d --build
```

**When hard reset is required:**
- Modified `docker-ttyd/shared/defaults/` (agent instructions copied to image)
- Changed Dockerfile or entrypoint scripts
- Updated nginx templates in `docker-proxy/shared/`
- Need fresh volumes for testing

**When NOT needed:**
- Laravel code changes in `/www` (mounted volume)
- Git operations or README updates

**Single service rebuild:**
```bash
docker compose up -d --build pocket-dev-php    # Rebuild just PHP container
docker compose restart pocket-dev-proxy         # Restart without rebuild
```

### Laravel Development

**All Laravel commands must run in PHP container:**
```bash
# Artisan commands
docker compose exec pocket-dev-php php artisan migrate
docker compose exec pocket-dev-php php artisan make:controller YourController
docker compose exec pocket-dev-php php artisan route:list
docker compose exec pocket-dev-php php artisan tinker

# Composer
docker compose exec pocket-dev-php composer install
docker compose exec pocket-dev-php composer require package/name

# NPM
docker compose exec pocket-dev-php npm install
docker compose exec pocket-dev-php npm run dev
```

**Testing Claude Code integration:**
```bash
# Test CLI directly in PHP container
docker exec pocket-dev-php bash -c 'echo "What is 5+3?" | claude --print --output-format json'

# Check credentials
docker exec pocket-dev-php cat /var/www/.claude/.credentials.json

# View Laravel logs
docker compose logs -f pocket-dev-php
docker compose exec pocket-dev-php tail -f storage/logs/laravel.log
```

### Database Operations

```bash
# Access PostgreSQL
docker compose exec pocket-dev-postgres psql -U pocket-dev -d pocket-dev

# Run migrations
docker compose exec pocket-dev-php php artisan migrate

# Seed database
docker compose exec pocket-dev-php php artisan db:seed
```

### Debugging

```bash
# View all logs
docker compose logs -f

# Service-specific logs
docker compose logs -f pocket-dev-proxy
docker compose logs -f pocket-dev-ttyd
docker compose logs -f pocket-dev-php

# Container shell access
docker exec -it pocket-dev-php bash
docker exec -it pocket-dev-ttyd bash

# Check volumes
docker volume inspect pocket-dev-workspace
docker run --rm -v pocket-dev-workspace:/data alpine ls -la /data
```

## Critical File Paths

### Credentials Location
- **TTYD**: `/home/devuser/.claude/.credentials.json` (devuser:devuser)
- **PHP**: `/var/www/.claude/.credentials.json` (www-data:www-data)

These paths are NOT the same. Authentication in one container does not automatically work in the other.

### Configuration Files
- `www/config/claude.php` - Claude Code service configuration
- `.env` - Environment variables (not in repo, copy from `.env.example`)
- `docker-proxy/shared/nginx.conf.template` - Proxy configuration template

### Default Files (Copied to Images)
- `docker-ttyd/shared/defaults/` - Files copied to TTYD container on build
- Changes here require full rebuild

## Common Pitfalls

1. **Route order matters**: Auth routes must come BEFORE wildcard routes like `/claude/{sessionId?}` or they'll never match

2. **Credentials are container-specific**: User authenticating via `/terminal` (TTYD) doesn't automatically authenticate PHP container

3. **File permissions**: PHP runs as `www-data`, not root. Files in `/var/www/.claude/` must be owned by `www-data:www-data`

4. **Claude CLI flags**: Use `--print --output-format json`, NOT `--json` (removed in v2.0+)

5. **Volume persistence**: User containers created inside TTYD will block volume removal. Must stop them first before `docker compose down -v`

6. **Response structure**: Claude returns `{"type":"result","subtype":"success","is_error":false,"result":"[actual message]",...}` - extract the `result` field

## Testing Authentication Flow

```bash
# 1. Check auth status
curl -u admin:damage1993 http://192.168.1.175/claude/auth/status

# 2. Upload credentials via API
curl -u admin:damage1993 -X POST http://192.168.1.175/claude/auth/upload-json \
  -H "Content-Type: application/json" \
  -d '{"json":"{\"claudeAiOauth\":{\"accessToken\":\"...\",\"refreshToken\":\"...\",\"expiresAt\":...}}"}'

# 3. Test query
curl -u admin:damage1993 -X POST http://192.168.1.175/api/claude/sessions \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","project_path":"/var/www"}'

curl -u admin:damage1993 -X POST http://192.168.1.175/api/claude/sessions/1/query \
  -H "Content-Type: application/json" \
  -d '{"prompt":"What is 5+3?"}'
```

## Production vs Development

**Development mode** (`compose.yml`):
- Local images built from `docker-*/local/` directories
- Mounts source code as volumes
- Includes TTYD terminal

**Production mode** (`deploy/compose.yml`):
- Uses pre-built images from GitHub Container Registry
- No source code mounts
- Optimized for deployment

## Git Workflow

**Making changes:**
1. Create feature branch
2. Make changes (remember hard reset for Docker changes)
3. Test thoroughly
4. Commit with descriptive messages
5. Push and create PR (only when explicitly requested)

**Do not create PRs automatically** - only when user explicitly asks.

## Security Notes

- Basic Auth is required (enforced by proxy)
- IP whitelist is optional but recommended for production
- Claude credentials contain OAuth tokens - never commit to git
- `.env` file must never be committed (use `.env.example` template)
