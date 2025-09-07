#!/bin/bash
set -e

# Set group ownership to www-data for shared access (user:www-data)
# This allows both host user and container to write files
chgrp -R www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

# Set group write permissions (664 for files, 775 for directories)
find /var/www/storage -type d -exec chmod 775 {} \;
find /var/www/storage -type f -exec chmod 664 {} \;
find /var/www/bootstrap/cache -type d -exec chmod 775 {} \;
find /var/www/bootstrap/cache -type f -exec chmod 664 {} \;

# Generate Laravel application key if not set
if [ -f ".env" ] && ! grep -q "^APP_KEY=.\+" .env; then
    echo "Generating Laravel application key..."
    php artisan key:generate --no-interaction
fi

# Create storage symlink if it doesn't exist
if [ ! -L "public/storage" ]; then
    echo "Creating storage symlink..."
    php artisan storage:link --no-interaction
fi

# Install npm dependencies and start Vite dev server
cd /var/www
if [ -f "package.json" ]; then
    echo "Installing npm dependencies..."
    npm install
    
    echo "Starting Vite dev server in background..."
    npm run dev &
    VITE_PID=$!

    # =============================================================================
    # CUSTOM CODE START - PocketDev specific additions
    # =============================================================================

    # Function to handle shutdown signals
    # shutdown() {
    #     echo "Shutting down..."
    #     kill $VITE_PID 2>/dev/null || true
    #     exit 0
    # }
    
    # Trap signals for graceful shutdown
    # trap shutdown SIGTERM SIGINT

    # =============================================================================
    # CUSTOM CODE END
    # =============================================================================
fi

# =============================================================================
# CUSTOM CODE START - PocketDev specific additions
# =============================================================================

# Initialize workspace directory and start ttyd web terminal
echo "Setting up development workspace..."
ttyd --port 7681 --writable --cwd /workspace bash &
TTYD_PID=$!

# Custom shutdown function to handle both Vite and ttyd processes
shutdown() {
    echo "Shutting down..."
    [ -n "$VITE_PID" ] && kill $VITE_PID 2>/dev/null || true
    kill $TTYD_PID 2>/dev/null || true
    exit 0
}

# Trap signals for graceful shutdown
trap shutdown SIGTERM SIGINT

# =============================================================================
# CUSTOM CODE END
# =============================================================================

composer install
composer dump-autoload -o
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart

echo "Starting PHP-FPM"
exec php-fpm