# CLAUDE.md

PocketDev is a **Docker-based Laravel development environment** with integrated Claude Code AI capabilities.

## Documentation

**Read `docs/README.md` first** - it has TLDR navigation to help you find relevant sections quickly. The docs/ folder is the single source of truth for architecture, implementation details, and design decisions.

## Quick Reference

### Container Command Prefix
```bash
docker compose exec pocket-dev-php <command>

# Examples:
docker compose exec pocket-dev-php php artisan migrate
docker compose exec pocket-dev-php composer install
```

### Hard Reset (Docker rebuild)
Use when changing Dockerfiles, entrypoints, or files in `docker-*/shared/defaults/`:
```bash
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop && \
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm && \
docker compose down -v && docker compose up -d --build
```

### Frontend Rebuild (Vite)
Use after changing JS, CSS, or Alpine.js directives in Blade templates:
```bash
docker compose up -d --force-recreate
```

## Critical Pitfalls

1. **Dual-container DOM pattern**: Chat interface has BOTH `#messages` (desktop) and `#messages-mobile` containers. JavaScript must update both.

2. **Credentials are container-specific**: TTYD uses `/home/devuser/.claude/`, PHP uses `/var/www/.claude/`. Authentication in one doesn't work in the other.

3. **File permissions**: PHP runs as `www-data`. Files in `/var/www/.claude/` must be owned by `www-data:www-data`.

4. **Route order matters**: Specific routes (like `/claude/auth`) must come BEFORE wildcard routes (`/claude/{sessionId?}`).

5. **Claude CLI flags**: Use `--print --output-format json`, NOT `--json`.

## PHP Style Preferences

- Use public properties for simple values (booleans, strings, arrays) instead of getter methods
- Reserve methods for computed values, logic, or actions (like `execute()`)

## Git Workflow

- Create feature branches for changes
- **Do not create PRs automatically** - only when explicitly requested
- Never commit `.env` or credential files
