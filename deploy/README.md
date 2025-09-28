# PocketDev - Production Deployment

This directory contains everything needed to deploy PocketDev to production using pre-built Docker images.

## 🚀 Quick Production Deployment

### Prerequisites

- Docker and Docker Compose installed on production server
- Pre-built images available at GitHub Container Registry

### Deployment Steps

1. **Copy this directory to your production server**:
   ```bash
   scp -r deploy/ user@server:/opt/pocket-dev/
   cd /opt/pocket-dev/deploy/
   ```

2. **Configure production environment**:
   ```bash
   cp .env.example .env
   nano .env
   ```

   **Critical settings to update**:
   ```bash
   APP_URL=https://your-domain.com
   DB_PASSWORD=your_secure_database_password
   BASIC_AUTH_PASS=your_secure_admin_password  # REQUIRED - change from default!
   IP_WHITELIST=your.office.ip/32,your.home.ip/32
   ```

3. **Deploy with pre-built images**:
   ```bash
   # Deploy latest version
   docker compose up -d

   # Deploy specific version
   IMAGE_TAG=v1.2.0 docker compose up -d
   ```

4. **Access your environment**:
   - **Laravel App**: https://your-domain.com
   - **Terminal**: https://your-domain.com/terminal-ws/

## 🔐 Security Configuration

### Required Security Settings

```bash
# Strong database password
DB_PASSWORD=use_a_strong_password_here

# Admin credentials for web access (REQUIRED - must be changed!)
BASIC_AUTH_USER=admin
BASIC_AUTH_PASS=use_a_strong_password_here

# Restrict access to your IPs only
IP_WHITELIST=203.0.113.0/24,198.51.100.5/32
```

⚠️ **Important**: The deployment will fail if you don't change `BASIC_AUTH_PASS` from the default value.

### Optional Git Integration

If you want git functionality in the terminal:

```bash
GIT_TOKEN=ghp_your_github_token_here
GIT_USER_NAME="Your Full Name"
GIT_USER_EMAIL=your.email@domain.com
```

## 🛠️ Management Commands

```bash
# Start services
docker compose up -d

# Stop services
docker compose down

# View logs
docker compose logs -f

# Update to latest version
docker compose pull && docker compose up -d

# Deploy specific version
IMAGE_TAG=v1.3.0 docker compose pull && docker compose up -d

# Database maintenance
docker compose exec pocket-dev-php php artisan migrate --force
docker compose exec pocket-dev-php php artisan optimize:clear

# Database backup
docker compose exec pocket-dev-postgres pg_dump -U pocket-dev pocket-dev > backup.sql

# Database restore
docker compose exec -i pocket-dev-postgres psql -U pocket-dev -d pocket-dev < backup.sql
```

## 🔍 Monitoring

### Health Checks

All services include health checks. Monitor with:

```bash
# Check service health
docker compose ps

# View health check logs
docker compose logs pocket-dev-proxy
docker compose logs pocket-dev-php
docker compose logs pocket-dev-nginx
docker compose logs pocket-dev-postgres
docker compose logs pocket-dev-ttyd
```

### Volume Management

```bash
# List volumes
docker volume ls | grep pocket-dev

# Backup workspace data
docker run --rm -v pocket-dev_workspace-data:/data -v $(pwd):/backup ubuntu tar czf /backup/workspace-backup.tar.gz -C /data .

# Restore workspace data
docker run --rm -v pocket-dev_workspace-data:/data -v $(pwd):/backup ubuntu tar xzf /backup/workspace-backup.tar.gz -C /data
```

## 🆘 Troubleshooting

### Common Production Issues

**502 Bad Gateway**:
```bash
# Check PHP-FPM status
docker compose logs pocket-dev-php
docker compose restart pocket-dev-php
```

**Database connection issues**:
```bash
# Check PostgreSQL health
docker compose exec pocket-dev-postgres pg_isready -U pocket-dev
docker compose logs pocket-dev-postgres
```

**Authentication not working**:
```bash
# Verify proxy configuration
docker compose logs pocket-dev-proxy
docker compose restart pocket-dev-proxy
```

**Can't access terminal**:
```bash
# Check TTYD service
docker compose logs pocket-dev-ttyd
docker compose restart pocket-dev-ttyd
```

## 🔄 Updates

### Updating to New Versions

1. **Check available versions**: Visit GitHub releases
2. **Update and restart**:
   ```bash
   IMAGE_TAG=v1.4.0 docker compose pull
   docker compose up -d
   ```

### Rolling Back

```bash
# Rollback to previous version
IMAGE_TAG=v1.3.0 docker compose pull
docker compose up -d
```

## 🌐 SSL/HTTPS Setup

This configuration runs on HTTP. For HTTPS in production:

### Option 1: Reverse Proxy (Recommended)

Use Nginx, Caddy, or Cloudflare in front of this stack:

```nginx
# /etc/nginx/sites-available/pocket-dev
server {
    listen 443 ssl;
    server_name your-domain.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://localhost:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Option 2: Modify Compose

Add SSL certificates directly to the proxy container.

---

**🚀 Your PocketDev production environment is ready for AI-powered development!**