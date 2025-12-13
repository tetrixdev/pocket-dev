# PocketDev Documentation

**Last Updated**: December 2025

PocketDev is a Docker-based development environment that provides a multi-provider AI chat interface (Anthropic/OpenAI) and web terminal access. Designed for mobile-first coding workflows.

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

**TLDR:** Six Docker containers (proxy, php, nginx, postgres, redis, queue) connected via bridge network. Proxy handles Basic Auth + IP whitelist. PHP container runs Laravel + Claude CLI + Node.js. Redis provides caching and queue backend. Volumes share workspace and user data.

**Read level:** 📖 Full read recommended - Understanding container relationships is helpful for debugging.

**Contents:**
- `system-overview.md` - Container architecture, networking, volumes, data flow
- `authentication.md` - Basic Auth, IP whitelist, Claude credential management
- `technology-stack.md` - Laravel, Alpine.js, Docker, key dependencies

### 📦 Modules (`modules/`)

**TLDR:** Two main modules: Chat (web interface for Claude conversations with SSE streaming, responsive design, voice input, cost tracking) and Config Editor (CRUD for CLAUDE.md, settings.json, agents, commands, hooks, skills). Chat is complex (~1500 line Blade file), Config Editor is straightforward Laravel CRUD.

**Read level:** ⚠️ TLDR insufficient - Chat module has significant complexity (streaming, session management) that requires full documentation.

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

**TLDR:** Three custom tables: `conversations` (multi-provider conversations with messages stored in DB), `app_settings` (encrypted key-value store for API keys), `ai_models` (model configuration and pricing). Plus standard Laravel tables (users, cache, jobs).

**Read level:** ✅ TLDR likely sufficient - Schema is simple, use TLDR to identify relevant tables.

**Contents:**
- `schema.md` - Current database schema (NOT migration history)

### ⚙️ Configuration (`configuration/`)

**TLDR:** Key config files: `config/ai.php` (providers, models, thinking settings), `.env` (Docker settings, Basic Auth credentials, API keys), `nginx.conf.template` (proxy routing). Config editor allows runtime editing of Claude settings in PHP container.

**Read level:** ✅ TLDR likely sufficient - Configuration is straightforward.

**Contents:**
- `README.md` - Configuration files overview

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
| Provider factory | `app/Services/ProviderFactory.php` |
| Anthropic provider | `app/Services/Providers/AnthropicProvider.php` |
| OpenAI provider | `app/Services/Providers/OpenAIProvider.php` |
| Conversation API | `app/Http/Controllers/Api/ConversationController.php` |
| Chat interface | `resources/views/chat.blade.php` |
| Config editor | `app/Http/Controllers/ConfigController.php` |
| Docker setup | `compose.yml` |
| Proxy config | `docker-proxy/shared/` |
