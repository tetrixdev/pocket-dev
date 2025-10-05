# Pocket-Dev Architecture Reference

This document describes the pocket-dev environment architecture. All agents should understand these concepts.

## Where You Are

- **Container:** pocket-dev-ttyd (terminal server)
- **Working directory:** `/home/linux/projects/pocket-dev` (pocket-dev's source code - **DO NOT create user projects here**)
- **User workspace:** `/workspace` (create all user projects in subdirectories here, e.g., `/workspace/myproject/`)
- **Home directory:** `/home/devuser` (persistent across restarts)

## Critical Architectural Rule

⚠️ **NOTHING IS WEB-ACCESSIBLE WITHOUT GOING THROUGH pocket-dev-proxy**

```
User browser → http://localhost/myapp
              ↓
         pocket-dev-proxy (port 80, basic auth, IP filtering)
              ↓ (checks nginx route for /myapp)
         myapp-container (must be on pocket-dev-public network)
```

**This means:**
- ❌ Files in `/workspace` are **NOT** automatically accessible in browser
- ❌ Creating HTML files does **NOT** make them web-accessible
- ❌ Running a container does **NOT** make it web-accessible
- ✅ **ONLY** projects configured through the proxy are accessible

## Container Services

**Core services** (on `pocket-dev` internal network):
- `pocket-dev-proxy` - Nginx reverse proxy (exposed on port 80)
- `pocket-dev-php` - Laravel application with PHP 8.4-FPM
- `pocket-dev-nginx` - Laravel web server
- `pocket-dev-postgres` - PostgreSQL 17 database
- `pocket-dev-ttyd` - Terminal server (you are here!)

**User containers** (on `pocket-dev-public` network):
- Must be on `pocket-dev-public` network to be accessible through proxy
- Accessed through proxy routes only (no direct port exposure)

## Networks

- **`pocket-dev`** - Internal network for core services only
- **`pocket-dev-public`** - Shared network that user containers must join
  - `pocket-dev-proxy` bridges both networks

**User containers MUST:**
- Join `pocket-dev-public` network (not `pocket-dev`)
- NOT expose ports (use proxy routes instead)
- Use correct container names matching nginx `proxy_pass`

## Volumes

- **`pocket-dev-workspace`** - Your project files (`/workspace`)
- **`pocket-dev-user`** - Home directory with configs (`/home/devuser`)
- **`pocket-dev-postgres`** - Database data
- **`pocket-dev-proxy-config`** - Nginx configuration (mounted at `/etc/nginx-proxy-config`)

## Sibling Container Pattern

When you run `docker compose` from inside pocket-dev-ttyd, the Docker daemon on the **host** creates containers as **siblings** (not inside ttyd).

**This means:**
- ❌ Relative paths (`.` or `./path`) in volume mounts **don't work** - they resolve to host paths, not ttyd paths
- ✅ Must use `pocket-dev-workspace` volume with `subpath` in `compose.override.yml`
- ✅ User containers and pocket-dev containers are siblings on the Docker host

**Example:**

```yaml
# compose.yml (portable, works everywhere)
services:
  app:
    volumes:
      - .:/var/www/html  # Relative path

# compose.override.yml (pocket-dev-specific)
services:
  app:
    volumes:
      - type: volume
        source: pocket-dev-workspace
        target: /var/www/html
        volume:
          subpath: myproject  # Project folder in /workspace/
```

## Proxy Configuration

Nginx configuration template: `/etc/nginx-proxy-config/nginx.conf.template`

**Environment variables** (used in envsubst):
- `$IP_ALLOWED` - IP whitelist check result
- `$AUTH_ENABLED` - Basic auth status (on/off)
- `$DEFAULT_SERVER` - Deployment mode flag
- `$DOMAIN_NAME` - Domain name (localhost for local, your-domain.com for production)

**Testing nginx config:**
```bash
# Always include all 4 variables
docker exec pocket-dev-proxy sh -c "envsubst '\$IP_ALLOWED \$AUTH_ENABLED \$DEFAULT_SERVER \$DOMAIN_NAME' < /etc/nginx-proxy-config/nginx.conf.template > /tmp/nginx.conf.test && nginx -t -c /tmp/nginx.conf.test"
```

**Applying nginx config:**
```bash
docker exec pocket-dev-proxy sh -c "envsubst '\$IP_ALLOWED \$AUTH_ENABLED \$DEFAULT_SERVER \$DOMAIN_NAME' < /etc/nginx-proxy-config/nginx.conf.template > /etc/nginx/nginx.conf && nginx -s reload"
```

## Common Error Codes

| Error | Meaning | Likely Cause |
|-------|---------|--------------|
| 404 Not Found | Route missing | No nginx route configured |
| 502 Bad Gateway | Container unreachable | Container not running, wrong network, or wrong container name |
| 500 Internal Error | Nginx config error | Syntax error in nginx configuration |
| 401 Unauthorized | Auth required | Expected - basic auth is working correctly |

## Quick Verification

```bash
# Test container directly (bypasses proxy/auth)
docker exec pocket-dev-proxy curl -f http://container-name/

# Test proxy route (includes auth - expect 401)
docker exec pocket-dev-proxy curl -f http://localhost/route

# Check container network
docker inspect container-name | grep -A 5 Networks

# Check nginx logs
docker logs pocket-dev-proxy --tail 50
```
