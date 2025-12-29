#!/bin/bash
set -e

echo "Starting queue workers..."

# Set HOME for Claude Code CLI, Codex CLI, and other tools that expect a writable home directory
# www-data is in group 1000 (appgroup) which owns /home/appuser with 775 permissions
export HOME=/home/appuser

# Ensure CLI config directories exist
mkdir -p "$HOME/.claude" "$HOME/.codex" 2>/dev/null || true

# Configure git and GitHub CLI if credentials are provided
if [[ -n "$GIT_TOKEN" && -n "$GIT_USER_NAME" && -n "$GIT_USER_EMAIL" ]]; then
    echo "Configuring git credentials..."

    # Configure git user information
    git config --global user.name "$GIT_USER_NAME"
    git config --global user.email "$GIT_USER_EMAIL"

    # Configure git credential helper for HTTPS repos
    git config --global credential.helper store

    # Store GitHub credentials in standard format
    echo "https://token:$GIT_TOKEN@github.com" > ~/.git-credentials
    chmod 600 ~/.git-credentials

    echo "Git configured for user: $GIT_USER_NAME"
fi

# Wait for database migrations to complete (php container runs them)
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

# Start supervisord to manage multiple queue workers
# The queue-workers.conf defines 3 workers for parallel job processing
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/queue-workers.conf
