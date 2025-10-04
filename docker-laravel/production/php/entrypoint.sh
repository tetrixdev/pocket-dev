#!/bin/bash
set -e

echo "Starting Laravel production container..."

# TODO fix this to work correctly, check slim-docker-setup for latest changes because i think it worked before, although we never tried production update so actually that's probably not true!
# Generate Laravel application key if not set
if [ -f ".env" ] && ! grep -q "^APP_KEY=.\+" .env; then
    echo "Generating Laravel application key..."
    php artisan key:generate --no-interaction --force
fi

# Run Laravel production optimizations
echo "Running Laravel optimizations..."
php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
php artisan queue:restart --no-interaction

echo "Laravel application ready for production"
echo "Starting PHP-FPM..."

# Start PHP-FPM
exec php-fpm