# PocketDev

AI-powered development environment with Claude Code integration. Run Claude Code in a containerized Laravel environment with full terminal access.

## Quick Start

**Prerequisites**: Docker and Docker Compose

### One-Liner Install

```bash
mkdir pocket-dev && cd pocket-dev && \
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/setup.sh -o setup.sh && \
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/compose.yml -o compose.yml && \
curl -sL https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/.env.example -o .env.example && \
chmod +x setup.sh && ./setup.sh
```

Or manually:

```bash
# Download deployment files
mkdir pocket-dev && cd pocket-dev
curl -sLO https://raw.githubusercontent.com/tetrixdev/pocket-dev/main/deploy/{setup.sh,compose.yml,.env.example}
chmod +x setup.sh

# Run setup (generates secrets, asks for port)
./setup.sh
```

Then open http://localhost (or your configured port) and follow the setup wizard.

## Features

- **Claude Code Integration** - AI-powered development through web interface
- **PHP 8.4** with Laravel, Composer, Node.js 22
- **PostgreSQL 17** with persistent storage
- **Hot Reload** with Vite dev server
- **Git & GitHub CLI** - configure via web UI
- **Remote Development** - VS Code and JetBrains support

## First Run Setup

On first visit, PocketDev will guide you through:

1. **AI Provider Setup** - Configure Claude Code, Anthropic API, or OpenAI
2. **Git Credentials** (optional) - For git operations inside the environment

All configuration is done through the web UI - no need to edit config files.

## Architecture

```
pocket-dev-proxy (nginx)     ← HTTP requests
    ↓
pocket-dev-nginx (Laravel)   ← Static assets
    ↓
pocket-dev-php (PHP-FPM)     ← Application logic
    ↓
pocket-dev-postgres          ← Database
pocket-dev-redis             ← Queue & cache
pocket-dev-queue             ← Background jobs (Claude Code runs here)
```

## Development Commands

```bash
# Access container shell
docker compose exec pocket-dev-php bash

# Laravel commands
docker compose exec pocket-dev-php php artisan migrate
docker compose exec pocket-dev-php php artisan tinker

# View logs
docker compose logs -f pocket-dev-queue

# Restart after config changes
docker compose restart
```

## Remote Development

### VS Code

1. Install "Dev Containers" extension
2. Command Palette → "Dev Containers: Attach to Running Container..."
3. Select `pocket-dev-php`
4. Open `/var/www` folder

### JetBrains Gateway

1. Use Docker connection type
2. Attach to `pocket-dev-php`
3. Open `/var/www` directory

## Troubleshooting

**Container won't start?**
```bash
docker compose logs -f
```

**Database issues?**
```bash
docker compose exec pocket-dev-php php artisan migrate:fresh
```

**Need a clean restart?**
```bash
docker compose down -v && docker compose up -d
```

## Contributing

Want to contribute to PocketDev? See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup.

## License

PocketDev is source-available software. Free for personal and commercial
development work. See [LICENSE.md](LICENSE.md) for details.
