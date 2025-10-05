# Claude Code Instructions

## Environment Overview

You're running inside a Docker container (pocket-dev-ttyd) with special capabilities:

- **Docker Access**: You can run docker commands to manage containers
- **Nginx Proxy Access**: You can safely add new routes to the reverse proxy
- **Network**: User containers should connect to `pocket-dev-public` network
- **Working Directory**: `/workspace` for user projects

## Working with Docker Compose Projects in Pocket-Dev

### Understanding the Sibling Container Pattern

When you run `docker compose` from inside pocket-dev, the Docker daemon on the host creates your containers (sibling containers), not the ttyd container. This means relative paths like `.` don't resolve correctly because the Docker daemon can't see inside the `pocket-dev-workspace` volume.

**Solution:** Maintain two compose files - a portable `compose.yml` and a pocket-dev-specific `compose.override.yml`.

**Key Principle:** Any volume mount using relative paths (`.` or `./path`) in your standard `compose.yml` must be rewritten in `compose.override.yml` to use the `pocket-dev-workspace` volume with a `subpath` pointing to your project directory. This applies to all services - web servers, databases, application servers, etc.

### Setting Up Docker Compose Projects

**IMPORTANT:** Always create user projects in subdirectories of `/workspace`, never in `/workspace` itself or in the pocket-dev root directory. For example: `/workspace/my-project/`.

**1. Create your standard compose.yml** (commit this to git):

```yaml
services:
  demo-nginx:
    image: nginx:alpine
    container_name: demo-nginx
    volumes:
      - .:/usr/share/nginx/html
    ports:
      - "80:80"
```

**2. Create compose.override.yml** (pocket-dev only - add to .gitignore):

```yaml
services:
  demo-nginx:
    volumes:
      - type: volume
        source: pocket-dev-workspace
        target: /usr/share/nginx/html
        volume:
          subpath: demo  # Replace 'demo' with your project directory
    ports: []  # Remove port exposure - use proxy instead
    networks:
      - pocket-dev-public

volumes:
  pocket-dev-workspace:
    external: true

networks:
  pocket-dev-public:
    external: true
```

**Important Notes:**
- Docker Compose automatically merges `compose.yml` and `compose.override.yml` when you run any `docker compose` commands
- When making changes to your service configuration, maintain both files to keep the project portable
- The override file is environment-specific and should not be committed to version control

**3. Add to .gitignore:**

```
compose.override.yml
```

**4. Environment Files (.env):**

If the project requires a `.env` file, **always ask the user to create it manually** before continuing. Never create, read, or edit `.env` files as they typically contain secrets and credentials.

**5. Start your containers:**

```bash
docker compose up -d
```

### Adding Nginx Proxy Routes

**IMPORTANT:** Start your containers first before adding proxy routes. The nginx config test will fail if it references containers that don't exist yet.

**1. Edit the proxy config:**

```bash
nano /etc/nginx-proxy-config/nginx.conf.template
```

**2. Add your route in the CUSTOM ROUTES section:**

```nginx
location /demo {
    proxy_pass http://demo-nginx/;  # Trailing slash strips /demo prefix
    proxy_http_version 1.1;

    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    # WebSocket support (always include)
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection $connection_upgrade;

    # Timeouts for long-running requests
    proxy_read_timeout 300s;
    proxy_send_timeout 300s;

    # Docker DNS resolution
    resolver 127.0.0.11 valid=30s;
    proxy_redirect off;
}
```

**Note:** The trailing slash in `proxy_pass http://demo-nginx/;` strips the location prefix, so `/demo/page` becomes `/page`. For Laravel projects, set `APP_URL=http://localhost/demo` in `.env` to generate correct URLs.

**3. Test and apply the configuration:**

```bash
# Test syntax
docker exec pocket-dev-proxy sh -c "envsubst '\$IP_ALLOWED \$AUTH_ENABLED \$DEFAULT_SERVER \$DOMAIN_NAME' < /etc/nginx-proxy-config/nginx.conf.template > /tmp/nginx.conf.test && nginx -t -c /tmp/nginx.conf.test"

# Apply if successful
docker exec pocket-dev-proxy sh -c "envsubst '\$IP_ALLOWED \$AUTH_ENABLED \$DEFAULT_SERVER \$DOMAIN_NAME' < /etc/nginx-proxy-config/nginx.conf.template > /etc/nginx/nginx.conf && nginx -s reload"
```

**4. Access your app:** http://localhost/demo

### Advanced: Mounting Individual Config Files

**Important Limitation:** The `volume.subpath` feature only works for **directories**, not individual files. If your compose.yml mounts individual config files (e.g., `./config/nginx.conf:/etc/nginx/nginx.conf`), you need to use the symlink approach:

```yaml
# compose.override.yml - for services mounting individual config files
services:
  my-service:
    volumes:
      - type: volume
        source: pocket-dev-workspace
        target: /workspace
      - my-service-config:/etc/myapp/config  # Writable config location
    command: >
      sh -c "ln -sf /workspace/myproject/config/* /etc/myapp/config/ &&
             exec original-entrypoint-command"
    networks:
      - pocket-dev-public

volumes:
  pocket-dev-workspace:
    external: true
  my-service-config:
    driver: local
```

This approach:
1. Mounts workspace at `/workspace`
2. Creates a writable volume for the config directory
3. Symlinks config files from workspace into the writable location on startup
4. Then executes the original entrypoint

### Proxy Configuration Rules

**IMPORTANT:**
1. ⚠️ **NEVER modify the CORE CONFIGURATION section** - it contains critical routes for the application
2. ✅ **ONLY add routes in the CUSTOM ROUTES section**
3. ✅ **ALWAYS test config before applying**
4. ✅ **Use trailing slash** in `proxy_pass` when you want to strip the location prefix

## Safety Reminders

- ✅ Always test nginx config before reloading
- ✅ Use `nginx -s reload` (graceful) not restart
- ✅ Keep backups of working configs
- ⚠️ Never modify core routes (/, /terminal-ws/, /health)
- ⚠️ Don't expose ports on user containers - use proxy routes
- ⚠️ User containers must use `pocket-dev-public` network only

## Troubleshooting

For detailed troubleshooting steps, see `/home/devuser/.claude/TROUBLESHOOTING.md`.

**Quick debugging commands:**
```bash
# Check nginx logs
docker logs pocket-dev-proxy --tail 50

# Test nginx config syntax
docker exec pocket-dev-proxy nginx -t

# Verify container is running and networked
docker ps | grep your-container
docker inspect your-container | grep pocket-dev-public

# Check what's in the container
docker exec your-container ls -la /workspace
```

---

Happy coding! Remember: test before reload, and keep the core config safe! 🚀
