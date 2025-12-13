# Self-Development Mode

This guide explains how to develop PocketDev using PocketDev itself (dogfooding).

## Overview

Self-development mode enables you to:
- Edit PocketDev source code from within PocketDev
- Restart/rebuild containers from inside the PHP container
- Develop on mobile without needing host terminal access

## Setup

### 1. Enable Self-Development Mode

Add to your `.env` file:

```env
# Self-Development Mode
HOST_PROJECT_PATH=/path/to/your/pocket-dev
```

Replace `/path/to/your/pocket-dev` with the actual host path where PocketDev is cloned.

### 2. Restart Containers

```bash
docker compose down && docker compose up -d --build
```

### 3. Verify Setup

```bash
docker compose exec pocket-dev-php /pocketdev-source/scripts/pocketdev help
```

## Usage

### The `pocketdev` CLI

Once set up, you can use the `pocketdev` command from inside the PHP container.

**Full path:** `/pocketdev-source/scripts/pocketdev`

**Tip:** Create an alias for convenience:
```bash
alias pocketdev='/pocketdev-source/scripts/pocketdev'
```

| Command | Description | When to Use |
|---------|-------------|-------------|
| `pocketdev restart` | Restart PHP/Nginx containers | After entrypoint.sh or config changes |
| `pocketdev rebuild` | Rebuild and restart PHP containers | After Dockerfile changes |
| `pocketdev rebuild-all` | Rebuild all containers | After major Docker changes |
| `pocketdev frontend` | Run `npm run build` | After JS/CSS changes |
| `pocketdev status` | Show container status | Check container health |
| `pocketdev logs` | Show PHP container logs | Debug issues |

### What Requires Restart?

| Change Type | Location | Action Needed |
|-------------|----------|---------------|
| PHP code | `www/app/`, `www/routes/` | None (immediate) |
| Blade templates | `www/resources/views/` | None (immediate) |
| JS/CSS | `www/resources/js,css/` | `pocketdev frontend` |
| Composer deps | `www/composer.json` | `composer install` |
| NPM deps | `www/package.json` | `npm install` |
| Entrypoint | `docker-laravel/.../entrypoint.sh` | `pocketdev restart` |
| PHP config | `docker-laravel/.../local.ini` | `pocketdev restart` |
| Dockerfile | `docker-laravel/.../Dockerfile` | `pocketdev rebuild` |
| Nginx config | `docker-laravel/.../default.conf` | `pocketdev restart` |

**Key insight**: 95% of development work (PHP, Blade, JS) doesn't need any restart!

## How It Works

The self-development feature uses a clever technique to allow the container to restart itself:

1. **Source Mount**: PocketDev source is mounted at `/pocketdev-source`
2. **Docker Socket**: Docker socket is mounted for container management
3. **Helper Container**: Restart commands run via a detached `docker:cli` container
4. **Host Path Mapping**: `HOST_PROJECT_PATH` ensures correct volume mounts

When you run `pocketdev restart`, it:
1. Launches a temporary `docker:cli` container (detached)
2. Mounts the project at the same path as on the host
3. Runs `docker compose up -d --force-recreate` from that container
4. The original PHP container is recreated while the command runs in the helper

## Troubleshooting

### "HOST_PROJECT_PATH environment variable is not set"

Add `HOST_PROJECT_PATH=/your/path` to your `.env` file and restart.

### Container restart doesn't pick up changes

For Dockerfile changes, use `pocketdev rebuild` instead of `restart`.

### Permission issues after restart

Ensure `USER_ID` and `GROUP_ID` in `.env` match your host user.

## Source Files

- `scripts/pocketdev` - CLI script
- `compose.yml` - HOST_PROJECT_PATH environment variable
- `docker-laravel/local/php/entrypoint.sh` - Symlink setup for pocketdev CLI
