#!/bin/bash
set -e

echo "Starting Laravel production container..."

# Set HOME for Claude Code CLI, Codex CLI, and other tools that expect a writable home directory
# www-data is in group 1000 (appgroup) which owns /home/appuser with 775 permissions
export HOME=/home/appuser

# Ensure CLI config directories exist
mkdir -p "$HOME/.claude" "$HOME/.codex" 2>/dev/null || true

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

# Check if running as main PHP container (no args or php-fpm)
# vs secondary container (queue worker, scheduler, etc.)
if [ $# -eq 0 ] || [ "$1" = "php-fpm" ]; then
    echo "Starting PHP-FPM..."
    exec php-fpm
else
    # Secondary container: wait for migrations to complete before running command
    # This handles queue workers, schedulers, or any other artisan commands
    echo "⏳ Waiting for database migrations..."
    max_attempts=60
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if php artisan migrate:status > /dev/null 2>&1; then
            echo "✅ Database ready"
            break
        fi
        attempt=$((attempt + 1))
        if [ $attempt -eq $max_attempts ]; then
            echo "❌ Timeout waiting for database migrations"
            exit 1
        fi
        sleep 1
    done
    exec "$@"
fi