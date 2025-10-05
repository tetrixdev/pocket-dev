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

# Fix permissions for mounted config volumes (for config editor)
# Make directories and files group-writable so www-data (in group 1001) can edit configs
if [ -d "/etc/nginx-proxy-config" ]; then
    echo "Setting permissions on /etc/nginx-proxy-config..."
    find /etc/nginx-proxy-config -type d -exec chmod 775 {} \; 2>/dev/null || true
    find /etc/nginx-proxy-config -type f -exec chmod 664 {} \; 2>/dev/null || true
    # Change group ownership to hostdocker so www-data can write
    find /etc/nginx-proxy-config -exec chgrp hostdocker {} \; 2>/dev/null || true
fi

if [ -d "/ttyd-user-home" ]; then
    echo "Setting permissions on /ttyd-user-home..."
    chmod 775 /ttyd-user-home 2>/dev/null || true
    find /ttyd-user-home -type f -exec chmod 664 {} \; 2>/dev/null || true
    find /ttyd-user-home -type d -exec chmod 775 {} \; 2>/dev/null || true
fi

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
    
    # Function to handle shutdown signals
    shutdown() {
        echo "Shutting down..."
        kill $VITE_PID 2>/dev/null || true
        exit 0
    }
    
    # Trap signals for graceful shutdown
    trap shutdown SIGTERM SIGINT
fi

composer install
composer dump-autoload -o
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart

echo "Starting PHP-FPM"
exec php-fpm