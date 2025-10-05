# Pocket-Dev Development Instructions

This file contains instructions for working on pocket-dev itself (not for users inside the ttyd container).

## Hard Reset Procedure

When you need to do a complete clean rebuild (e.g., after changing Docker image files, entrypoints, or defaults):

### Step 1: Stop and Remove All Containers

```bash
# Stop and remove ALL containers using pocket-dev volumes
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm

# Alternative: More aggressive - stop ALL containers not part of pocket-dev core
docker ps -a --format "{{.Names}}" | grep -v "^pocket-dev-" | xargs -r docker stop
docker ps -a --format "{{.Names}}" | grep -v "^pocket-dev-" | xargs -r docker rm
```

### Step 2: Bring Down pocket-dev

```bash
docker compose down -v
```

### Step 3: Clean Rebuild

```bash
docker compose up -d --build
```

### Quick One-Liner

For quick resets during development:

```bash
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop && docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm && docker compose down -v && docker compose up -d --build
```

## When to Use Hard Reset

**Use hard reset when:**
- ✅ Modified files in `docker-ttyd/shared/defaults/`
- ✅ Changed Dockerfile
- ✅ Updated entrypoint scripts
- ✅ Changed nginx templates
- ✅ Need fresh volumes for testing

**Don't need hard reset for:**
- ❌ Code changes in `/www` (Laravel app)
- ❌ Git operations
- ❌ README updates
- ❌ Changes to files already mounted as volumes

## Troubleshooting

**If `docker compose down -v` fails with "volume still in use":**

```bash
# Find what's using the volume
docker ps -a --filter volume=pocket-dev-workspace

# Stop and remove those containers
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm

# Now try again
docker compose down -v
```

**If you get "network in use" errors:**

```bash
# Find containers on pocket-dev-public network
docker ps -a --filter network=pocket-dev-public

# Stop and remove them
docker ps -a --filter network=pocket-dev-public --format "{{.Names}}" | xargs -r docker stop
docker ps -a --filter network=pocket-dev-public --format "{{.Names}}" | xargs -r docker rm
```

## Development Workflow

### Making Changes to Agent Instructions

1. Edit files in `docker-ttyd/shared/defaults/`
2. Run hard reset to rebuild ttyd image
3. Verify changes: `docker exec pocket-dev-ttyd cat /home/devuser/.claude/CLAUDE.md`

### Making Changes to Nginx Config

1. Edit `docker-proxy/shared/nginx.conf.template`
2. If only template changed (not Dockerfile): `docker compose restart pocket-dev-proxy`
3. If Dockerfile changed: Full rebuild with `docker compose up -d --build pocket-dev-proxy`

### Testing User Project Setup

1. Access terminal: http://localhost/terminal-ws/
2. Ask Claude Code to create a project
3. Verify it works
4. Clean up: Remove project from `/workspace` and nginx config

## Common Commands

```bash
# View logs
docker compose logs -f pocket-dev-ttyd
docker logs pocket-dev-proxy --tail 50

# Restart single service
docker compose restart pocket-dev-ttyd

# Rebuild single service
docker compose up -d --build pocket-dev-ttyd

# Check what's in volumes
docker volume inspect pocket-dev-workspace
docker run --rm -v pocket-dev-workspace:/data alpine ls -la /data

# Execute command in container
docker exec pocket-dev-ttyd <command>

# Access container shell
docker exec -it pocket-dev-ttyd bash
```

## Git Workflow

### Committing Changes

```bash
# Check status
git status
git diff

# Stage and commit
git add <files>
git commit -m "Description of changes"
git push
```

### Creating Pull Requests

Only create PRs when explicitly asked. Follow the standard git workflow with meaningful commit messages.

## Notes

- Always test after making changes to defaults/ files
- User containers (created by Claude Code inside ttyd) will block volume removal
- The `-r` flag in `xargs -r` prevents errors when no containers match the filter
