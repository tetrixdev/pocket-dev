#!/bin/bash
set -e

# Add www-data to the host's docker group for docker socket access
# DOCKER_GID is passed from compose.yml and matches the host's docker group
if [ -n "$DOCKER_GID" ]; then
    # Check if a group with this GID already exists
    if ! getent group "$DOCKER_GID" > /dev/null 2>&1; then
        groupadd -g "$DOCKER_GID" hostdocker_runtime 2>/dev/null || true
    fi
    # Add www-data to this group
    usermod -aG "$DOCKER_GID" www-data 2>/dev/null || true
fi

# Set HOME for Claude Code CLI, Codex CLI, and other tools that expect a writable home directory
# www-data is in group 1000 (appgroup) which owns /home/appuser with 775 permissions
export HOME=/home/appuser

# Ensure CLI config directories exist
mkdir -p "$HOME/.claude" "$HOME/.codex" 2>/dev/null || true

# Configure git and GitHub CLI if credentials are provided
# This is critical for Claude Code agents that need git/gh access
if [[ -n "$GIT_TOKEN" && -n "$GIT_USER_NAME" && -n "$GIT_USER_EMAIL" ]]; then
    echo "⚙️  Configuring git credentials for queue worker..."

    # Configure git user information (continue on failure)
    if git config --global user.name "$GIT_USER_NAME" 2>/dev/null && \
       git config --global user.email "$GIT_USER_EMAIL" 2>/dev/null && \
       git config --global credential.helper store 2>/dev/null; then

        # Store GitHub credentials in standard format (username = "token" for GitHub tokens)
        echo "https://token:$GIT_TOKEN@github.com" > ~/.git-credentials 2>/dev/null
        chmod 600 ~/.git-credentials 2>/dev/null || true

        echo "✅ Git and GitHub CLI configured for queue worker"
    else
        echo "⚠️  Could not configure git credentials (permission issue) - continuing without"
    fi
else
    echo "ℹ️  Git credentials not provided - skipping git/GitHub CLI setup"
fi

# Wait for database migrations to complete (php container runs them)
# Check for the cache table which is created early in migrations
echo "⏳ Waiting for database migrations..."
max_attempts=60
attempt=0
while [ $attempt -lt $max_attempts ]; do
    # Use migrate:status to check if migrations have run - exits 0 if table exists
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

# Start supervisord to manage multiple queue workers
# The queue-workers.conf defines 10 workers for parallel job processing
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/queue-workers.conf
