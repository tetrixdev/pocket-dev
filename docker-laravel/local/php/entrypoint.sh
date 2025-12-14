#!/bin/bash
set -e

echo "🚀 Configuring PHP development environment..."

# Configure git and GitHub CLI if credentials are provided
if [[ -n "$GIT_TOKEN" && -n "$GIT_USER_NAME" && -n "$GIT_USER_EMAIL" ]]; then
    echo "⚙️  Configuring git credentials..."

    # Ensure home directory exists and is writable
    mkdir -p "$HOME" 2>/dev/null || true

    # Configure git user information (continue on failure)
    if git config --global user.name "$GIT_USER_NAME" 2>/dev/null && \
       git config --global user.email "$GIT_USER_EMAIL" 2>/dev/null && \
       git config --global credential.helper store 2>/dev/null; then

        # Store GitHub credentials in standard format (username = "token" for GitHub tokens)
        echo "https://token:$GIT_TOKEN@github.com" > ~/.git-credentials 2>/dev/null
        chmod 600 ~/.git-credentials 2>/dev/null || true

        echo "✅ Git and GitHub CLI configured for user: $GIT_USER_NAME"
        echo "   GitHub CLI will use GH_TOKEN environment variable"
    else
        echo "⚠️  Could not configure git credentials (permission issue) - continuing without"
    fi
else
    echo "ℹ️  Git credentials not provided - skipping git/GitHub CLI setup"
    echo "   Set GIT_TOKEN, GIT_USER_NAME, and GIT_USER_EMAIL to enable"
fi

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

# Fix permissions for pocketdev storage volume (recursive with setgid)
if [ -d "/var/www/storage/pocketdev" ]; then
    echo "Setting permissions on /var/www/storage/pocketdev..."
    chgrp -R www-data /var/www/storage/pocketdev 2>/dev/null || true
    find /var/www/storage/pocketdev -type d -exec chmod 2775 {} \; 2>/dev/null || true
    find /var/www/storage/pocketdev -type f -exec chmod 664 {} \; 2>/dev/null || true
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

# Install npm dependencies and build assets
cd /var/www
if [ -f "package.json" ]; then
    echo "Installing npm dependencies..."
    npm install

    echo "Building frontend assets..."
    npm run build

    echo "✅ Built assets ready in public/build/"
    echo "ℹ️  To start Vite dev server: docker compose exec pocket-dev-php npm run dev"
fi

composer install
composer dump-autoload -o
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart

echo "Starting PHP-FPM"
exec php-fpm