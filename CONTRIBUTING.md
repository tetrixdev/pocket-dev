# Contributing to PocketDev

This guide is for developers who want to contribute to PocketDev itself.

## Development Setup

```bash
# Clone the repository
git clone https://github.com/tetrixdev/pocket-dev.git
cd pocket-dev

# Run setup (auto-detects USER_ID, GROUP_ID, DOCKER_GID)
./setup.sh

# Start development environment
docker compose up -d
```

The root `compose.yml` is configured for development with:
- Source code mounted from `./www`
- Vite dev server on port 5173 (hot reload)
- Docker socket access for dogfooding

## Directory Structure

```
pocket-dev/
├── www/                    # Laravel application
├── docker-laravel/         # PHP container configs
│   ├── local/             # Development Dockerfiles
│   ├── production/        # Production Dockerfiles
│   └── shared/            # Shared configs
├── docker-proxy/           # Nginx proxy container
├── deploy/                 # Production deployment package
│   ├── compose.yml        # Production compose file
│   ├── setup.sh           # User setup script
│   └── .env.example       # Production env template
├── compose.yml             # Development compose file
├── setup.sh                # Developer setup script
└── .env.example            # Development env template
```

## Development Workflow

### Making Changes

1. Create a feature branch
2. Make your changes in `www/`
3. Test locally
4. Submit a pull request

### Rebuilding Containers

After changing Dockerfiles or entrypoints:

```bash
# Quick rebuild
docker compose up -d --build

# Clean rebuild (no cache)
docker compose build --no-cache && docker compose up -d

# Full rebuild (clears volumes - WARNING: loses data)
docker compose down -v && docker compose build --no-cache && docker compose up -d
```

### Viewing Logs

```bash
# All logs
docker compose logs -f

# Specific service
docker compose logs -f pocket-dev-queue
docker compose logs -f pocket-dev-php
```

## Dogfooding (Self-Development)

PocketDev can develop itself! The queue container has:

- `/pocketdev-source` - Full project source (read/write)
- Docker socket access - Can restart containers
- Git/GitHub CLI configured

### Self-Restart Pattern

When making changes that require container restart:

```bash
# From inside PocketDev (safe self-restart)
docker run --rm -d \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "$HOST_PROJECT_PATH:$HOST_PROJECT_PATH" \
    -w "$HOST_PROJECT_PATH" \
    docker:27-cli \
    docker compose restart pocket-dev-queue
```

## Code Style

- PHP: Follow PSR-12 (but don't run automated formatters)
- Use public properties over getter/setter methods
- Use Blade components (`<x-*>`) for reusable UI
- Keep it simple - avoid over-engineering

## Pull Request Guidelines

1. Keep PRs focused on a single feature/fix
2. Test your changes locally
3. Update documentation if needed
4. Use conventional commit messages

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

**Permission issues?**
Check that USER_ID and GROUP_ID in `.env` match your host user (`id -u` and `id -g`).
