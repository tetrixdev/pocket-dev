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
- Vite dev server on port 5173
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

# Full rebuild (clears volumes)
docker compose down -v && docker compose up -d --build
```

### Frontend Changes

After changing JS, CSS, or Blade templates with Alpine.js:

```bash
# Rebuild assets
docker compose exec pocket-dev-php npm run build

# Or run dev server for hot reload
docker compose exec pocket-dev-php npm run dev
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
- Use Laravel anonymous components (`<x-*>`) for UI
- Keep it simple - avoid over-engineering

## Testing

```bash
docker compose exec pocket-dev-php php artisan test
```

## Pull Request Guidelines

1. Keep PRs focused on a single feature/fix
2. Test your changes locally
3. Update documentation if needed
4. Use conventional commit messages

## Release Process

1. Create a release on GitHub (e.g., `v1.0.0`)
2. GitHub Actions builds and pushes Docker images
3. Users can update via `docker compose pull && docker compose up -d`
