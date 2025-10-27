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

- `app/Models/ClaudeSession.php` - Database model for session metadata
  - Stores only metadata: title, project_path, claude_session_id, turn_count, status
  - Messages stored in Claude's native .jsonl files (single source of truth)
  - Uses `incrementTurn()` to track conversation progress

- `app/Http/Controllers/Api/ClaudeController.php` - RESTful API
  - Main endpoints: status, list/create sessions, stream, list/load .jsonl sessions
  - Voice transcription endpoints: transcribe, openai-key management
  - Streaming passes through Claude CLI output without storing content
  - Returns Claude's JSON response structure with `result` field

- `app/Http/Controllers/ClaudeAuthController.php` - Authentication management
  - Upload credentials file or paste JSON
  - Shows auth status (subscription type, expiry, scopes)
  - Validates credential structure: `claudeAiOauth` with `accessToken`, `refreshToken`, `expiresAt`

- `app/Services/OpenAIService.php` - OpenAI Whisper integration for voice transcription
  - Transcribes audio files using gpt-4o-transcribe model
  - Falls back to database-stored API key if not in config

- `app/Services/AppSettingsService.php` - Encrypted settings management
  - Stores sensitive settings (like OpenAI API key) with Laravel encryption
  - Used by OpenAI service for API key retrieval

- `app/Models/AppSetting.php` - Database model for encrypted key-value settings
  - Automatically encrypts/decrypts values using Laravel's encrypted cast

- `resources/views/chat.blade.php` - Main chat interface (Blade template with Alpine.js)
  - Fully responsive: desktop sidebar layout, mobile full-page scroll with drawer
  - Voice recording with MediaRecorder API
  - Keyboard shortcuts: Ctrl+T (thinking toggle), Ctrl+Space (voice), Ctrl+? (help)
  - Real-time streaming with Server-Sent Events
  - Cost tracking with breakdown modals
  - Session management with URL-based persistence

**Configuration:**

- `config/claude.php` - Claude Code settings (model, tools, permissions, timeout)
- Migrations:
  - `2025_10_16_201350_create_claude_sessions_table.php` - Session metadata
  - `2025_10_26_214333_create_app_settings_table.php` - Encrypted settings storage

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
/api/claude/sessions - List sessions (metadata only)
/api/claude/sessions - Create session (metadata only)
/api/claude/sessions/{session}/stream - Streaming query (SSE)
/api/claude/claude-sessions - List Claude's native .jsonl sessions
/api/claude/claude-sessions/{sessionId} - Load messages from .jsonl file
/api/claude/transcribe - POST audio file for OpenAI Whisper transcription
/api/claude/openai-key/check - GET OpenAI API key configuration status
/api/claude/openai-key - POST to save OpenAI API key (encrypted)
/api/claude/openai-key - DELETE to remove OpenAI API key
/api/pricing/{model} - GET/POST model pricing configuration
```

## Chat Interface Architecture

The main chat interface (`resources/views/chat.blade.php`) uses a dual-container pattern for responsive design:

### Responsive Layout Strategy

- **Desktop** (≥768px): Sidebar layout with fixed header and scrollable message container
- **Mobile** (<768px): Full-page scroll with sticky header and fixed bottom panel

**Key insight**: Both layouts exist in the DOM simultaneously, controlled by CSS media queries. This means:
- JavaScript must update BOTH containers when adding/updating messages
- Auto-scroll behavior differs: mobile uses `window.scrollTo()`, desktop uses `container.scrollTop`
- Functions like `addMsg()`, `updateMsg()`, and cost updates must iterate through both containers

### Frontend State Management

Uses **Alpine.js 3.x** for reactive state in `appState()` function:

- **Voice recording state**: `isRecording`, `isProcessing`, `mediaRecorder`, `audioChunks`
- **Modal state**: `showOpenAiModal`, `showShortcutsModal`, `showMobileDrawer`
- **Configuration**: `openAiKeyConfigured`, `autoSendAfterTranscription`

### Streaming Architecture

Real-time streaming uses Server-Sent Events (SSE):

1. User sends message via `/api/claude/sessions/{id}/stream`
2. Backend streams Claude CLI output as SSE events
3. Frontend processes events in real-time:
   - `text_delta` → Updates assistant message incrementally
   - `thinking` → Creates/updates thinking blocks
   - `tool_use` → Creates tool blocks
   - `tool_result` → Updates tool blocks with results
   - `usage` → Calculates and displays cost

**Critical**: Streaming updates call `updateMsg()` hundreds of times per response, so it must be efficient and update both containers.

### Cost Tracking

Cost calculation happens **server-side** in `.jsonl` files to ensure consistency:

- Backend calculates cost using model pricing from database
- Frontend displays cost from `.jsonl` metadata
- Per-message breakdown modal shows token/cost details
- Session total displayed in sidebar (desktop) and drawer (mobile)

## Development Commands

Run these from `/home/linux/projects/pocket-dev/` on the HOST.

### Working on PocketDev Infrastructure

**Hard Reset** (after changing Docker images, entrypoints, or defaults):
```bash
# Run from: /home/linux/projects/pocket-dev/

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
- Modified `/home/linux/projects/pocket-dev/docker-ttyd/shared/defaults/` (copied to image on build)
- Changed Dockerfiles or entrypoint scripts
- Updated nginx templates in `docker-proxy/shared/`
- Need fresh volumes for testing

**When NOT needed:**
- Laravel code changes (www/ is a mounted volume, changes are instant)
- Git operations or README updates
- Frontend/backend code (no rebuild needed)

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

### Credentials Location (Inside Containers)
- **TTYD container**: `/home/devuser/.claude/.credentials.json` (devuser:devuser)
- **PHP container**: `/var/www/.claude/.credentials.json` (www-data:www-data)

These paths are NOT the same. Authentication in one container does not automatically work in the other.

### Configuration Files (HOST Paths)
- `/home/linux/projects/pocket-dev/www/config/claude.php` - Claude Code service configuration
- `/home/linux/projects/pocket-dev/.env` - Environment variables (not in repo, copy from `.env.example`)
- `/home/linux/projects/pocket-dev/docker-proxy/shared/nginx.conf.template` - Proxy configuration template

### Default Files (Copied to Images on Build)
- `/home/linux/projects/pocket-dev/docker-ttyd/shared/defaults/` - Files copied to TTYD container
- Changes here require full rebuild

## Common Pitfalls

1. **Route order matters**: Auth routes must come BEFORE wildcard routes like `/claude/{sessionId?}` or they'll never match

2. **Credentials are container-specific**: User authenticating via `/terminal` (TTYD) doesn't automatically authenticate PHP container

3. **File permissions**: PHP runs as `www-data`, not root. Files in `/var/www/.claude/` must be owned by `www-data:www-data`

4. **Claude CLI flags**: Use `--print --output-format json`, NOT `--json` (removed in v2.0+)

5. **Volume persistence**: User containers created inside TTYD will block volume removal. Must stop them first before `docker compose down -v`

6. **Response structure**: Claude returns `{"type":"result","subtype":"success","is_error":false,"result":"[actual message]",...}` - extract the `result` field

7. **Mobile vs Desktop**: When modifying chat interface, always update BOTH desktop and mobile containers:
   - Use `containers.forEach()` pattern like `updateMsg()` and `addMsg()` functions
   - Desktop container: `#messages`, Mobile container: `#messages-mobile`
   - Mobile uses full-page scroll (window.scrollTo), desktop uses container scroll (scrollTop)

8. **Microphone access**: Browser microphone API requires secure context (HTTPS or localhost). IP addresses won't work for voice recording.

## Testing Authentication Flow

```bash
# 1. Check auth status
curl -u admin:damage1993 http://192.168.1.175/claude/auth/status

# 2. Upload credentials via API
curl -u admin:damage1993 -X POST http://192.168.1.175/claude/auth/upload-json \
  -H "Content-Type: application/json" \
  -d '{"json":"{\"claudeAiOauth\":{\"accessToken\":\"...\",\"refreshToken\":\"...\",\"expiresAt\":...}}"}'

# 3. Test streaming query
curl -u admin:damage1993 -X POST http://192.168.1.175/api/claude/sessions \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","project_path":"/var/www"}'

# Use /stream endpoint (returns Server-Sent Events)
curl -u admin:damage1993 -N -X POST http://192.168.1.175/api/claude/sessions/1/stream \
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
