# PocketDev Documentation

**Last Updated**: December 2025

PocketDev is a Docker-based development environment that provides a web interface for interacting with Claude Code CLI. Designed for mobile-first coding workflows.

---

## Quick Reference

| Task | Go To |
|------|-------|
| Understand the system | `architecture/system-overview.md` |
| Work on chat interface | `modules/chat/` |
| Work on config editor | `modules/config-editor.md` |
| Debug Claude CLI issues | `integrations/claude-cli.md` |
| Add voice features | `integrations/openai-whisper.md` |
| Database changes | `database/schema.md` |
| Docker/container changes | `architecture/system-overview.md` |

---

## Documentation

### 🏗️ Architecture (`architecture/`)

**TLDR:** Five Docker containers (proxy, php, nginx, postgres, ttyd) connected via bridge network. Proxy handles Basic Auth + IP whitelist. PHP container runs Laravel + Claude CLI + Node.js. TTYD provides web terminal with tmux. Two separate credential files exist (TTYD vs PHP container). Volumes share workspace and user data between containers.

**Read level:** 📖 Full read recommended - Understanding container relationships and credential separation is critical for debugging.

**Contents:**
- `system-overview.md` - Container architecture, networking, volumes, data flow
- `authentication.md` - Basic Auth, IP whitelist, Claude credential management
- `technology-stack.md` - Laravel, Alpine.js, Docker, key dependencies
- `multi-provider-implementation-plan.md` - **[WIP]** Implementation plan for multi-provider conversation architecture

### 📦 Modules (`modules/`)

**TLDR:** Two main modules: Chat (web interface for Claude conversations with SSE streaming, dual-container responsive pattern, voice input, cost tracking) and Config Editor (CRUD for CLAUDE.md, settings.json, agents, commands, hooks, skills). Chat is complex (~1500 line Blade file), Config Editor is straightforward Laravel CRUD.

**Read level:** ⚠️ TLDR insufficient - Chat module has significant complexity (dual-container pattern, streaming, session management) that requires full documentation.

**Contents:**
- `chat/` - Chat interface module (streaming, sessions, voice, cost tracking)
- `config-editor.md` - Configuration file editor

### 🔌 Integrations (`integrations/`)

**TLDR:** Two external integrations: Claude CLI (wrapped via `proc_open()`, handles streaming via `--output-format stream-json`, session management via UUIDs) and OpenAI Whisper (voice transcription via `gpt-4o-transcribe` model, API key stored encrypted in database).

**Read level:** ⚠️ TLDR insufficient - Claude CLI integration has complex streaming and session handling that must be understood for modifications.

**Contents:**
- `claude-cli.md` - Claude Code CLI integration details
- `openai-whisper.md` - Voice transcription integration

### 🗄️ Database (`database/`)

**TLDR:** Three custom tables: `claude_sessions` (metadata only - messages live in .jsonl files), `app_settings` (encrypted key-value store for API keys), `model_pricing` (token pricing for cost calculation). Plus standard Laravel tables (users, cache, jobs).

**Read level:** ✅ TLDR likely sufficient - Schema is simple, use TLDR to identify relevant tables.

**Contents:**
- `schema.md` - Current database schema (NOT migration history)

### ⚙️ Configuration (`configuration/`)

**TLDR:** Key config files: `config/claude.php` (model, timeout, permissions), `.env` (Docker settings, Basic Auth credentials), `nginx.conf.template` (proxy routing). Config editor allows runtime editing of Claude settings in TTYD container.

**Read level:** ✅ TLDR likely sufficient - Configuration is straightforward.

**Contents:**
- `README.md` - Configuration files overview

---

## Known Complexity Areas

These areas have accumulated complexity and are candidates for refactoring:

1. **Dual Container Pattern** (`modules/chat/`) - Desktop `#messages` + mobile `#messages-mobile` must be updated in parallel for every DOM change.

2. **Session ID Duality** - Database ID (integer) vs Claude session ID (UUID) tracked separately, creating confusion.

3. **Cost Calculation** - Duplicated in client JS (during streaming) and server-side (.jsonl files for historical).

4. **Credential Separation** - TTYD and PHP containers have separate credential files at different paths.

5. **chat.blade.php Size** - 1500+ lines mixing HTML, JS, CSS, Alpine.js state.

---

## Development Commands

```bash
# Start all containers
docker compose up -d --build

# Hard reset (after Docker changes)
docker compose down -v && docker compose up -d --build

# Frontend rebuild (after JS/CSS changes)
docker compose up -d --force-recreate

# Laravel commands
docker compose exec pocket-dev-php php artisan migrate
docker compose exec pocket-dev-php php artisan tinker

# View logs
docker compose logs -f pocket-dev-php
```

---

## Source File Reference

| Component | Primary Files |
|-----------|---------------|
| Claude CLI wrapper | `app/Services/ClaudeCodeService.php` |
| Main API | `app/Http/Controllers/Api/ClaudeController.php` |
| Chat interface | `resources/views/chat.blade.php` |
| Config editor | `app/Http/Controllers/ConfigController.php` |
| Docker setup | `compose.yml` |
| Proxy config | `docker-proxy/shared/` |
