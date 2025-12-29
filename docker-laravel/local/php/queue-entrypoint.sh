#!/bin/bash
set -e

# Set HOME for Claude Code CLI, Codex CLI, and other tools that expect a writable home directory
# www-data is in group 1000 (appgroup) which owns /home/appuser with 775 permissions
export HOME=/home/appuser

# Ensure CLI config directories exist
mkdir -p "$HOME/.claude" "$HOME/.codex" 2>/dev/null || true

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
