# PocketDev Documentation

**PocketDev** is a Docker-based development environment with an integrated Claude Code web interface, designed for **mobile-first coding workflows**. It enables developers to interact with Claude Code AI from any device - particularly phones - while maintaining the full power of a development environment.

## Vision & Purpose

- **Primary Use Case**: Personal development tool for coding on mobile devices
- **Core Workflow**: Voice transcription + Claude Code AI for hands-free coding
- **Target Audience**: Individual developers who want powerful AI-assisted coding from anywhere
- **Philosophy**: Simple, functional, mobile-optimized - not rebuilding what already works well

## Quick Start

```bash
# Clone and configure
git clone <repo>
cd pocket-dev
cp .env.example .env
# Edit .env with your credentials

# Start containers
docker compose up -d --build

# Access
# Web UI: http://localhost
# Terminal: http://localhost/terminal (deprecated)
```

---

## Documentation Index

### Architecture (`architecture/`)

**TLDR:** Multi-container Docker system with 5 containers: proxy (security), PHP (Laravel + Claude CLI), nginx (web server), PostgreSQL (database), and TTYD (terminal, deprecated). Implements dual-container responsive pattern for desktop/mobile. Streaming via SSE. Messages stored in Claude's native .jsonl files.

**Read level:** **TLDR insufficient** - Understanding container interactions and responsive patterns is critical for any development work.

| Document | Description |
|----------|-------------|
| [README.md](architecture/README.md) | Architecture overview and design principles |
| [docker-containers.md](architecture/docker-containers.md) | Container definitions, networks, volumes, entrypoints |
| [chat-interface.md](architecture/chat-interface.md) | Frontend architecture, dual-container pattern, streaming |

---

### Modules (`modules/`)

**TLDR:** Single main module - Claude Integration. Handles CLI wrapper service, streaming, session management, cost tracking, and voice transcription. Key files: `ClaudeCodeService.php`, `ClaudeController.php`, `chat.blade.php`.

**Read level:** **TLDR insufficient** - Module internals are essential for feature development.

| Document | Description |
|----------|-------------|
| [README.md](modules/README.md) | Module overview |
| [claude-integration.md](modules/claude-integration.md) | Complete Claude Code integration documentation |

---

### API Reference (`api/`)

**TLDR:** RESTful API with SSE streaming. Key endpoints: `/api/claude/sessions/{id}/stream` (SSE streaming), `/api/claude/claude-sessions/{id}` (load .jsonl history), `/api/claude/transcribe` (voice). Web routes for auth (`/claude/auth/*`) and config (`/config/*`).

**Read level:** **Full read recommended** - API details matter for frontend/backend integration.

| Document | Description |
|----------|-------------|
| [README.md](api/README.md) | Complete API reference with request/response examples |

---

### Database (`database/`)

**TLDR:** PostgreSQL 17 with 3 custom tables: `claude_sessions` (metadata only, not messages), `model_pricing` (cost calculation), `app_settings` (encrypted key-value store). Messages stored in Claude's .jsonl files, not database.

**Read level:** **TLDR likely sufficient** - Schema is straightforward, check full docs for specific fields.

| Document | Description |
|----------|-------------|
| [README.md](database/README.md) | Schema documentation, models, relationships |

---

### Development (`development/`)

**TLDR:** Docker commands for hard reset, frontend rebuild (Vite), Laravel commands in containers. Critical pitfalls: route order matters, credentials are container-specific, dual-container DOM updates, microphone requires localhost.

**Read level:** **Full read recommended** - Pitfalls section prevents common mistakes.

| Document | Description |
|----------|-------------|
| [README.md](development/README.md) | Development workflow overview |
| [commands.md](development/commands.md) | Docker, Laravel, debugging commands |
| [pitfalls.md](development/pitfalls.md) | Common mistakes and how to avoid them |

---

### Design Decisions (`design-decisions/`)

**TLDR:** Key decisions: mobile-first workflow, multi-container Docker (best practice), .jsonl storage now but database planned for future, GitHub-centric approach acceptable, Config UI priority varies by frequency of use.

**Read level:** **Full read recommended** - Understanding "why" prevents wrong architectural choices.

| Document | Description |
|----------|-------------|
| [README.md](design-decisions/README.md) | Design philosophy and key decisions |
| [configuration-ui.md](design-decisions/configuration-ui.md) | Analysis of file browser vs list+modal UI approaches |

---

## Key Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| **Target Audience** | Personal dev tool | Mobile-first coding workflow, maximum simplicity |
| **Docker Architecture** | Multi-container | Docker best practice - separation of concerns, easier updates |
| **TTYD Terminal** | Deprecated | Web chat UI replaces terminal interaction |
| **Message Storage** | .jsonl files (now) | Simplicity, single source of truth with Claude CLI |
| **Message Storage** | Database (future) | Store native JSON, transform for display - better UI control |
| **UI Direction** | Mobile + Developer | Voice transcription, GitHub-centric, practical phone usage |
| **Config UI** | Priority varies | Important for frequently-changed settings (skills/agents) |

---

## Technology Stack

| Layer | Technology | Notes |
|-------|------------|-------|
| **Proxy** | Nginx Alpine | Basic Auth + IP whitelist + SSL |
| **Backend** | PHP 8.4 + Laravel 11 | With Claude Code CLI |
| **Frontend** | Blade + Alpine.js 3.x | Tailwind CSS, marked.js, highlight.js |
| **Database** | PostgreSQL 17 | Metadata only, messages in .jsonl |
| **Container** | Docker Compose | 5 containers, 2 networks, 4 volumes |
| **Voice** | OpenAI Whisper | gpt-4o-transcribe model |

---

## Related Files

| File | Purpose |
|------|---------|
| `CLAUDE.md` (root) | Claude Code context for AI assistants |
| `SLASH_COMMAND_ISSUE.md` | Known issue with slash commands + thinking mode |
| `docker-laravel/production/README.md` | Production deployment guide |

---

## Future Roadmap

Based on design decisions:

1. **Database Migration**: Store messages in native JSON format in database, transform for display
2. **Config UI Enhancement**: Better UI for frequently-changed settings (agents, skills)
3. **TTYD Deprecation**: Remove terminal container, rely fully on web chat UI
4. **GitHub Integration**: Lean into GitHub-centric workflows
