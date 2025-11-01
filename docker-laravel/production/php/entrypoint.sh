#!/bin/bash
set -e

echo "Starting Laravel production container..."

# Configure git and GitHub CLI if credentials are provided
if [[ -n "$GIT_TOKEN" && -n "$GIT_USER_NAME" && -n "$GIT_USER_EMAIL" ]]; then
    echo "⚙️  Configuring git credentials..."

    # Configure git user information
    git config --global user.name "$GIT_USER_NAME"
    git config --global user.email "$GIT_USER_EMAIL"

    # Configure git credential helper for HTTPS repos
    git config --global credential.helper store

    # Store GitHub credentials in standard format (username = "token" for GitHub tokens)
    echo "https://token:$GIT_TOKEN@github.com" > ~/.git-credentials
    chmod 600 ~/.git-credentials

    echo "✅ Git and GitHub CLI configured for user: $GIT_USER_NAME"
    echo "   GitHub CLI will use GH_TOKEN environment variable"
else
    echo "ℹ️  Git credentials not provided - skipping git/GitHub CLI setup"
fi

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