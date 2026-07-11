# Architecture Overview

PocketDev is a multi-container Docker application that wraps Claude Code CLI with a web interface.

## Contents

- `system-overview.md` - Container architecture, networking, volumes, data flow diagrams
- `authentication.md` - Basic Auth, IP whitelist, Claude credential management
- `technology-stack.md` - Laravel, Alpine.js, Docker, and key dependencies
- `file-permissions.md` - Cross-process file permissions (PHP-FPM vs queue workers)

## Quick Summary

| Container | Purpose | Port |
|-----------|---------|------|
| pocket-dev-php | Laravel app + Claude CLI + Node.js | 5173 (Vite) |
| pocket-dev-nginx | Web server (exposed via proxy-nginx on server) | 80 (local) |
| pocket-dev-postgres | PostgreSQL 17 database | 5432 |
| pocket-dev-redis | Redis for caching and queues | - |
| pocket-dev-queue | Laravel queue worker | - |

## Key Architectural Decisions

**Why 5 containers instead of 1?**
- Separation of concerns (app, web server, database, cache, queue)
- Independent scaling and restart
- On servers, proxy-nginx handles SSL/auth externally

**Why store messages in .jsonl files instead of database?**
- Claude CLI manages its own session files
- Single source of truth (CLI files)
- Avoids sync complexity between database and files

See individual files for detailed documentation.
