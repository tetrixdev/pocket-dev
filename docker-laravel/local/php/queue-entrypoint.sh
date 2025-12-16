#!/bin/bash
set -e

# Ensure Claude Code CLI config directory exists
mkdir -p "$HOME/.claude" 2>/dev/null || true

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

# Execute the command passed to the container
exec "$@"
