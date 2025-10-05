# Troubleshooting Guide - Pocket-Dev

## Nginx Proxy Issues

### Nginx fails to reload

```bash
# Check nginx error logs
docker logs pocket-dev-proxy

# Test config syntax
docker exec pocket-dev-proxy nginx -t

# Restart container if needed (last resort)
docker restart pocket-dev-proxy
```

### 404 errors on proxy route

```bash
# Check if the trailing slash is present in proxy_pass
docker exec pocket-dev-proxy cat /etc/nginx/nginx.conf | grep "location /your-route" -A 1

# Check if the target container is running
docker ps | grep your-container-name

# Check if container is on correct network
docker inspect <container-name> | grep pocket-dev-public

# Check nginx logs for the actual error
docker logs pocket-dev-proxy --tail 50
```

**Common causes:**
- Missing trailing slash in `proxy_pass` (should be `http://service:80/`)
- Container not on `pocket-dev-public` network
- Container not running
- Wrong container name in `proxy_pass`

## Docker Compose Issues

### Volume mounts not working (files not showing up)

**Remember:** You're in a Docker-in-Docker setup using the sibling container pattern.

```bash
# Check if compose.override.yml exists
ls -la compose.override.yml

# Verify the volume is mounted correctly
docker inspect <container-name> | grep Mounts -A 10

# Check what's actually in the container at the expected path
docker exec <container-name> ls -la /usr/share/nginx/html
```

**Common causes:**
- No `compose.override.yml` created
- Wrong `subpath` in volume configuration
- Trying to use relative paths (`.`) - these don't work in Docker-in-Docker
- Subdirectory doesn't exist in the workspace volume

### Changes to files aren't reflecting

**With the recommended setup (using `volume.subpath`):**
- Changes should be immediate - no restart needed
- Verify files are actually changing: `docker exec <container> cat /path/to/file`
- Check if the subpath is correctly mounted: `docker inspect <container> | grep Mounts -A 10`

### Container fails to start

```bash
# Check container logs
docker logs <container-name>

# Check if there are port conflicts
docker ps | grep <port-number>

# Verify compose configuration
docker compose config

# Check if volumes exist
docker volume ls | grep pocket-dev
```

## Network Issues

### Container can't reach other containers

```bash
# Verify both containers are on the same network
docker network inspect pocket-dev-public

# Check if network exists
docker network ls | grep pocket-dev

# Test connectivity between containers
docker exec <container-1> ping <container-2>
```

**Remember:** User containers should use `pocket-dev-public`, not `pocket-dev`.

## General Debugging Commands

```bash
# View all running containers
docker ps

# View all containers (including stopped)
docker ps -a

# Check container logs
docker logs <container-name>
docker logs <container-name> --tail 50
docker logs <container-name> -f  # Follow logs

# Inspect container details
docker inspect <container-name>

# Execute commands in running container
docker exec <container-name> <command>
docker exec -it <container-name> sh  # Interactive shell

# Check Docker networks
docker network ls
docker network inspect <network-name>

# Check Docker volumes
docker volume ls
docker volume inspect <volume-name>
```
