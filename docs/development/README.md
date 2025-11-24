# Development Guide

Guide for developing and contributing to PocketDev.

## Quick Reference

| Task | Command |
|------|---------|
| Start containers | `docker compose up -d --build` |
| Hard reset | See [commands.md](commands.md#hard-reset) |
| Frontend rebuild | `docker compose up -d --force-recreate` |
| View logs | `docker compose logs -f pocket-dev-php` |
| Run artisan | `docker compose exec pocket-dev-php php artisan ...` |

## Development Workflow

### 1. Laravel Code Changes

**No rebuild required** - code is mounted as volume.

Changes take effect immediately for:
- Controllers, Services, Models
- Routes
- Blade templates (except Alpine.js inline code)

### 2. Frontend Changes

**Requires container restart** to trigger Vite build:

```bash
docker compose up -d --force-recreate
```

Why? The PHP container's entrypoint runs `npm run build` on startup.

**Affected files**:
- `resources/js/app.js`
- `resources/css/app.css`
- Blade templates with inline Alpine.js (`x-show`, `x-model`, etc.)

### 3. Docker Changes

**Requires hard reset**:

```bash
docker compose down -v && docker compose up -d --build
```

**Affected files**:
- Dockerfiles
- Entrypoint scripts
- Files in `docker-ttyd/shared/defaults/`
- Nginx templates in `docker-proxy/shared/`

## Documentation

- [Commands Reference](commands.md) - All development commands
- [Common Pitfalls](pitfalls.md) - Mistakes to avoid

## Project Structure

```
pocket-dev/
├── www/                        # Laravel application
│   ├── app/
│   │   ├── Http/Controllers/   # API and web controllers
│   │   ├── Models/             # Eloquent models
│   │   └── Services/           # Business logic services
│   ├── config/
│   │   └── claude.php          # Claude Code configuration
│   ├── resources/
│   │   └── views/
│   │       └── chat.blade.php  # Main chat interface
│   └── routes/
│       ├── api.php             # API routes
│       └── web.php             # Web routes
├── docker-laravel/             # PHP container config
├── docker-proxy/               # Nginx proxy config
├── docker-ttyd/                # Terminal container (deprecated)
├── docs/                       # This documentation
├── compose.yml                 # Development compose
└── deploy/                     # Production deployment
```

## Key Files

| File | Purpose |
|------|---------|
| `www/app/Services/ClaudeCodeService.php` | Claude CLI wrapper |
| `www/app/Http/Controllers/Api/ClaudeController.php` | API endpoints |
| `www/resources/views/chat.blade.php` | Chat interface |
| `www/config/claude.php` | Claude configuration |
| `docker-proxy/shared/nginx.conf.template` | Proxy routing |
