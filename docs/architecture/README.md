# Architecture Overview

PocketDev is a multi-container Docker application that wraps Claude Code CLI with a web interface.

## Contents

- `system-overview.md` - Container architecture, networking, volumes, data flow diagrams
- `authentication.md` - Basic Auth, IP whitelist, Claude credential management
- `technology-stack.md` - Laravel, Alpine.js, Docker, and key dependencies

## Quick Summary

| Container | Purpose | Port |
|-----------|---------|------|
| pocket-dev-proxy | Security layer (Basic Auth + IP whitelist) | 80 |
| pocket-dev-php | Laravel app + Claude CLI + Node.js | 5173 (Vite) |
| pocket-dev-nginx | Internal Laravel web server | - |
| pocket-dev-postgres | PostgreSQL 17 database | 5432 |
| pocket-dev-ttyd | Web terminal with tmux | 7681 |

## Key Architectural Decisions

**Why 5 containers instead of 1?**
- Separation of concerns (security, app, database, terminal)
- Independent scaling and restart
- Security isolation (proxy handles auth, internal services don't need to)

**Why separate TTYD container?**
- Direct CLI access without web UI
- Different user context (devuser vs www-data)
- Persistent tmux sessions

**Why store messages in .jsonl files instead of database?**
- Claude CLI manages its own session files
- Single source of truth (CLI files)
- Avoids sync complexity between database and files

See individual files for detailed documentation.
