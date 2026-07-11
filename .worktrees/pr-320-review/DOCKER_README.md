# Laravel Docker Environment

This project uses Docker for local development and production deployment.

## Quick Start

```bash
# Start containers
docker compose up -d

# Check status
docker compose ps
```

## Daily Commands

### Laravel Commands
```bash
# Run migrations
docker compose exec pocket-dev-php php artisan migrate

# Fresh migration with seeding
docker compose exec pocket-dev-php php artisan migrate:fresh --seed

# Generate application key
docker compose exec pocket-dev-php php artisan key:generate

# Clear all caches
docker compose exec pocket-dev-php php artisan optimize:clear

# Run tinker
docker compose exec pocket-dev-php php artisan tinker
```

### Package Management
```bash
# Install/update Composer dependencies
docker compose exec pocket-dev-php composer install
docker compose exec pocket-dev-php composer update

# Install/update NPM dependencies
docker compose exec pocket-dev-php npm install
docker compose exec pocket-dev-php npm update

# Build frontend assets
docker compose exec pocket-dev-php npm run build
```

### Container Management
```bash
# View logs
docker compose logs -f pocket-dev-php
docker compose logs -f pocket-dev-nginx
docker compose logs -f pocket-dev-postgres

# Restart containers
docker compose restart

# Stop containers
docker compose down

# Access PHP container shell
docker compose exec pocket-dev-php bash
```

### Database Access
```bash
# Access PostgreSQL
docker compose exec pocket-dev-postgres psql -U pocket-dev -d pocket-dev
```

## Environments

- **Local Development**: Uses `compose.yml` with hot-reload and debugging
- **Production**: See `docker-laravel/production/README.md` for deployment instructions

## Ports

- **Web**: http://localhost:8080
- **Vite Dev Server**: http://localhost:5173
- **PostgreSQL**: localhost:5432

## Troubleshooting

### Permission Issues
```bash
docker compose exec pocket-dev-php chown -R www-data:www-data storage bootstrap/cache
```

### Container Won't Start
```bash
# Check logs for specific service
docker compose logs pocket-dev-php
```

### Database Connection Failed
- Ensure `.env` has correct DB credentials
- Check if PostgreSQL container is healthy: `docker compose ps`

### Vite HMR Not Working
- Ensure port 5173 is not blocked
- Check Vite config includes Docker settings