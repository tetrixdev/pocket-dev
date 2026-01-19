#!/bin/bash
set -e

# =============================================================================
# PocketDev PHP-FPM Production Entrypoint
# Uses gosu privilege drop pattern (same as official Docker images)
# - Runs as root initially for privileged operations
# - Drops to www-data before starting PHP-FPM
# =============================================================================

echo "Starting Laravel production container..."

# Set HOME for tools that expect a writable home directory
export HOME=/home/appuser

# Ensure CLI config directories exist and are owned by www-data
mkdir -p "$HOME/.claude" "$HOME/.codex" 2>/dev/null || true
chown -R www-data:www-data "$HOME" 2>/dev/null || true
chmod 775 "$HOME" "$HOME/.claude" "$HOME/.codex" 2>/dev/null || true

# Check if running as main PHP container (no args or php-fpm)
# vs secondary container (queue worker, scheduler, etc.)
if [ $# -eq 0 ] || [ "$1" = "php-fpm" ]; then
    # Main PHP container: run migrations, caching, and start PHP-FPM

    # Generate Laravel application key if not set (as www-data since it writes to .env)
    if [ -f ".env" ] && ! grep -q "^PD_APP_KEY=.\+" .env; then
        echo "Generating Laravel application key..."
        gosu www-data php artisan key:generate --no-interaction --force
    fi

    # Run Laravel production optimizations (as www-data)
    echo "Running Laravel optimizations..."
    gosu www-data php artisan migrate --force --no-interaction
    gosu www-data php artisan config:cache --no-interaction
    gosu www-data php artisan route:cache --no-interaction
    gosu www-data php artisan view:cache --no-interaction
    gosu www-data php artisan queue:restart --no-interaction

    echo "Laravel application ready for production"

    # PHP-FPM master runs as root, pool workers run as www-data (via www.conf)
    # This is standard Docker practice - see https://github.com/docker-library/php/issues/70
    # Running master as non-root fails with "failed to open error_log (/proc/self/fd/2): Permission denied"
    echo "Starting PHP-FPM (master as root, workers as www-data)..."

    # Start PHP-FPM as root - pool workers will be www-data per www.conf
    exec php-fpm
else
    # Secondary container (queue worker, scheduler, etc.):
    # Wait for main container to complete migrations, then run the command
    echo "Waiting for database migrations..."
    max_attempts=60
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if php artisan migrate:status > /dev/null 2>&1; then
            echo "Database ready"
            break
        fi
        attempt=$((attempt + 1))
        if [ $attempt -eq $max_attempts ]; then
            echo "Timeout waiting for database migrations"
            exit 1
        fi
        sleep 1
    done
    exec gosu www-data "$@"
fi
