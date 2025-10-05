# Pocket-Dev Environment Overview

## Where You Are

You are running inside the **pocket-dev-ttyd** container, which is part of the pocket-dev development environment.

**Current context:**
- **Container:** pocket-dev-ttyd
- **Working directory:** `/home/linux/projects/pocket-dev` (pocket-dev's own source code)
- **User workspace:** `/workspace` (where user projects should be created)
- **Home directory:** `/home/devuser` (persistent across restarts)

**Important:** Always create user projects in **subdirectories** of `/workspace`, never in `/workspace` itself or in the pocket-dev root directory. For example: `/workspace/my-project/`.

## Critical Architectural Rule

⚠️ **NOTHING IS WEB-ACCESSIBLE WITHOUT GOING THROUGH pocket-dev-proxy**

**This means:**
- ❌ Files in `/workspace` are **NOT** automatically accessible in browser
- ❌ Creating HTML files does **NOT** make them web-accessible
- ❌ Running a container does **NOT** make it web-accessible
- ✅ **ONLY** projects configured through the proxy are accessible

Think of pocket-dev like a building:
- `pocket-dev-proxy` = the front door with security (port 80, basic auth, IP filtering)
- User containers = individual rooms
- Proxy routes = hallways connecting the front door to each room
- Networks = which floor rooms are on

```
Internet → http://localhost/myapp
         ↓
    pocket-dev-proxy (port 80)
         ↓ (checks /myapp route)
    myapp-container (on pocket-dev-public network)
```

**If the user wants something accessible in their browser, you MUST use the pocket-dev-docker agent.**

## Pocket-Dev Architecture

### Container Services

**Core services** (on `pocket-dev` internal network):
- `pocket-dev-proxy` - Nginx reverse proxy (exposed on port 80)
- `pocket-dev-php` - Laravel application with PHP 8.4-FPM
- `pocket-dev-nginx` - Laravel web server
- `pocket-dev-postgres` - PostgreSQL 17 database
- `pocket-dev-ttyd` - Terminal server (you are here!)

**User containers** must be on `pocket-dev-public` network to be accessible through the proxy.

### Networks

- `pocket-dev` - Internal network for core services only
- `pocket-dev-public` - Shared network that user containers must join
  - `pocket-dev-proxy` bridges both networks

### Volumes

- `pocket-dev-workspace` - Your project files (`/workspace`)
- `pocket-dev-user` - Home directory with configs
- `pocket-dev-postgres` - Database data
- `pocket-dev-proxy-config` - Nginx configuration (mounted at `/etc/nginx-proxy-config`)

### Sibling Container Pattern

When you run `docker compose` from inside pocket-dev-ttyd, the Docker daemon on the **host** creates your containers (sibling containers), not inside ttyd. This means:
- Relative paths like `.` don't work in volume mounts
- You need `compose.override.yml` to use `pocket-dev-workspace` volume with `subpath`
- Your containers and pocket-dev containers are siblings on the Docker host

## When to Use the Pocket-Dev-Docker Agent

⚠️ **CRITICAL RULE:** You MUST use the `pocket-dev-docker` agent for **ANY** task involving:
- Docker containers
- Nginx proxy configuration
- Browser accessibility (making projects accessible via http://localhost)
- Troubleshooting 404, 502, 500 errors
- Container networking
- Docker Compose files
- Creating new web-accessible projects

**The agent handles:**
- ✅ Setting up new projects with proper Docker Compose configuration
- ✅ Configuring nginx proxy routes with correct syntax
- ✅ Troubleshooting why projects aren't accessible in browser
- ✅ Debugging container networking and connectivity issues
- ✅ Modifying existing nginx routes or docker configurations
- ✅ Verifying accessibility with curl tests before reporting success
- ✅ Investigating and fixing 404 (route missing), 502 (container unreachable), 500 (config error) issues

**Do NOT attempt these tasks yourself - always delegate to the agent:**
- Creating or modifying Docker Compose files
- Adding/changing nginx proxy routes
- Debugging why a project isn't accessible in browser
- Troubleshooting container issues

**You CAN handle directly (without the agent):**
- Simple file operations (reading, writing code in `/workspace`)
- Git operations
- Installing packages with npm/composer inside containers
- Running application commands (npm run dev, php artisan, etc.)
- General development tasks that don't involve docker/nginx/proxy

## Quick Reference

### Accessing Proxy Configuration

Nginx configuration template is shared:
```bash
nano /etc/nginx-proxy-config/nginx.conf.template
```

### Networks Your Containers Need

```yaml
# In compose.override.yml
networks:
  - pocket-dev-public  # Required for web access through proxy
```

### Testing If Your Route Works

```bash
# Test if proxy route is accessible (bypasses basic auth for testing)
docker exec pocket-dev-proxy curl -f http://localhost/your-route

# Or from outside (requires basic auth)
curl -u username:password http://localhost/your-route
```

### Common Issues

**404 Not Found:**
- Missing proxy route in nginx config
- Container not on `pocket-dev-public` network
- Container not running

**502 Bad Gateway:**
- Container is not running
- Container name doesn't match proxy_pass
- Container not on `pocket-dev-public` network

**Connection refused:**
- Check container logs: `docker logs container-name`
- Verify container is healthy: `docker ps`

For detailed troubleshooting, see `/home/devuser/.claude/TROUBLESHOOTING.md`

## Environment Variables

Proxy configuration uses these variables (set in entrypoint):
- `$IP_ALLOWED` - IP whitelist check result
- `$AUTH_ENABLED` - Basic auth status
- `$DEFAULT_SERVER` - Server flag for deployment mode
- `$DOMAIN_NAME` - Domain name (localhost for local, your-domain.com for production)

When testing nginx config, always include all variables:
```bash
envsubst '\$IP_ALLOWED \$AUTH_ENABLED \$DEFAULT_SERVER \$DOMAIN_NAME'
```

---

**For detailed Docker Compose and nginx setup procedures, invoke the pocket-dev-setup agent.**
