# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PocketDev is a **Docker-based Laravel development environment** with integrated Claude Code AI capabilities, designed for **mobile-first coding workflows**.

**For comprehensive documentation, see [`docs/README.md`](docs/README.md).**

## Quick Reference

### Container System

5 Docker containers:
- **pocket-dev-proxy**: Nginx reverse proxy (Basic Auth + IP whitelist)
- **pocket-dev-php**: Laravel app with PHP 8.4 + Claude Code CLI + Node.js 22
- **pocket-dev-nginx**: Laravel web server
- **pocket-dev-postgres**: PostgreSQL 17 database
- **pocket-dev-ttyd**: Web terminal (deprecated)

### Key Files

| Purpose | File |
|---------|------|
| Claude CLI wrapper | `www/app/Services/ClaudeCodeService.php` |
| API endpoints | `www/app/Http/Controllers/Api/ClaudeController.php` |
| Chat interface | `www/resources/views/chat.blade.php` |
| Claude config | `www/config/claude.php` |
| Proxy routing | `docker-proxy/shared/nginx.conf.template` |

### Development Commands

```bash
# Start containers
docker compose up -d --build

# Hard reset (for Docker changes)
docker compose down -v && docker compose up -d --build

# Frontend rebuild (after JS/CSS changes)
docker compose up -d --force-recreate

# Laravel commands
docker compose exec pocket-dev-php php artisan migrate
docker compose exec pocket-dev-php php artisan tinker

# View logs
docker compose logs -f pocket-dev-php
```

### Critical Pitfalls

1. **Route order matters**: Auth routes must come BEFORE wildcard routes
2. **Credentials are container-specific**: TTYD and PHP have separate credential files
3. **Dual-container DOM**: Always update BOTH `#messages` and `#messages-mobile`
4. **Microphone requires secure context**: Use `localhost`, not IP addresses
5. **Frontend rebuild**: Restart containers after JS/CSS changes

## Documentation Index

| Section | Path | Description |
|---------|------|-------------|
| **Overview** | [`docs/README.md`](docs/README.md) | Master index with TLDR navigation |
| **Architecture** | [`docs/architecture/`](docs/architecture/) | System design, containers, chat interface |
| **Modules** | [`docs/modules/`](docs/modules/) | Claude integration documentation |
| **Development** | [`docs/development/`](docs/development/) | Commands, pitfalls |
| **API** | [`docs/api/`](docs/api/) | Complete API reference |
| **Database** | [`docs/database/`](docs/database/) | Schema documentation |
| **Design Decisions** | [`docs/design-decisions/`](docs/design-decisions/) | Architectural choices and rationale |

## Key Design Decisions

- **Target**: Personal dev tool for mobile-first coding
- **Messages**: Stored in Claude's .jsonl files (future: database)
- **Streaming**: Server-Sent Events (SSE)
- **Responsive**: Dual-container pattern (desktop + mobile in DOM)
- **TTYD**: Deprecated, web chat UI is primary interface

## Git Workflow

1. Create feature branch
2. Make changes
3. Test thoroughly
4. Commit with descriptive messages
5. Push and create PR **only when explicitly requested**
