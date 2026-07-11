#!/bin/bash
set -e

# =============================================================================
# PocketDev Queue Worker Entrypoint
# Uses gosu privilege drop pattern (same as official Docker images)
# - Runs as root initially for privileged operations (apt-get, group setup)
# - Drops to TARGET_UID before starting supervisord
# =============================================================================

TARGET_UID="${PD_TARGET_UID:-1000}"
TARGET_GID="${PD_TARGET_GID:-1000}"

# Ensure PD_QUEUE_WORKERS is exported so supervisord can read %(ENV_PD_QUEUE_WORKERS)s.
# compose.yml passes this in; this default guards against manual `docker run` without it.
export PD_QUEUE_WORKERS="${PD_QUEUE_WORKERS:-20}"

echo "Starting queue container initialization..."

# =============================================================================
# PRIVILEGED SECTION - Runs as root
# =============================================================================

# Set up Docker socket access for TARGET_UID
if [ -n "$PD_DOCKER_GID" ]; then
    echo "🐳 Setting up Docker socket access for UID $TARGET_UID..."

    # Create the docker group with the host's GID if it doesn't exist
    if ! getent group "$PD_DOCKER_GID" > /dev/null 2>&1; then
        groupadd -g "$PD_DOCKER_GID" hostdocker_runtime 2>/dev/null || true
    fi

    # Get the group name for this GID
    DOCKER_GROUP_NAME=$(getent group "$PD_DOCKER_GID" | cut -d: -f1)
    if [ -z "$DOCKER_GROUP_NAME" ]; then
        DOCKER_GROUP_NAME="hostdocker_runtime"
    fi

    # Ensure appgroup exists with TARGET_GID (for host file access)
    if ! getent group "$TARGET_GID" > /dev/null 2>&1; then
        groupadd -g "$TARGET_GID" appgroup 2>/dev/null || true
    fi

    # Create a user for TARGET_UID if it doesn't exist (needed for group membership)
    # Cross-group ownership: primary group www-data (33), secondary group TARGET_GID (for host)
    if ! getent passwd "$TARGET_UID" > /dev/null 2>&1; then
        useradd -u "$TARGET_UID" -g 33 -G "$TARGET_GID" -d /home/appuser -s /bin/bash appuser 2>/dev/null || true
    fi

    # Get the username for TARGET_UID and add to docker group
    TARGET_USER=$(getent passwd "$TARGET_UID" | cut -d: -f1)
    if [ -n "$TARGET_USER" ]; then
        usermod -aG "$DOCKER_GROUP_NAME" "$TARGET_USER" 2>/dev/null || true
        echo "  ✅ Added $TARGET_USER to group $DOCKER_GROUP_NAME (GID $PD_DOCKER_GID)"
    fi

    # Ensure docker socket has correct group ownership
    if [ -S /var/run/docker.sock ]; then
        chgrp "$DOCKER_GROUP_NAME" /var/run/docker.sock 2>/dev/null || true
        chmod 660 /var/run/docker.sock 2>/dev/null || true
    fi
fi

# Ensure home directory exists and has correct permissions for target user
# Cross-group ownership: appuser:www-data (1000:33) with group-writable permissions
mkdir -p /home/appuser/.claude /home/appuser/.codex /home/appuser/.docker 2>/dev/null || true
chown -R "${TARGET_UID}:33" /home/appuser 2>/dev/null || true
chmod 775 /home/appuser /home/appuser/.claude /home/appuser/.codex /home/appuser/.docker 2>/dev/null || true

# Fix SSH key permissions for www-data (PHP-FPM) panel access
# More restrictive than other dirs: 750 for dir, 640 for private keys
if [ -d /home/appuser/.ssh ]; then
    chmod 750 /home/appuser/.ssh 2>/dev/null || true
    find /home/appuser/.ssh -name "id_*" ! -name "*.pub" -exec chmod 640 {} \; 2>/dev/null || true
fi

# Set up default Claude Code permissions.deny to protect .env files
# This is read by Claude Code CLI via --settings flag in ClaudeCodeProvider
CLAUDE_SETTINGS="/home/appuser/.claude/settings.json"
if [ ! -f "$CLAUDE_SETTINGS" ]; then
    # Create minimal settings with default deny patterns
    echo '{"permissions":{"deny":["Read(**/.env)"]}}' > "$CLAUDE_SETTINGS"
    chown "${TARGET_UID}:33" "$CLAUDE_SETTINGS"
    chmod 664 "$CLAUDE_SETTINGS"
    echo "Created default Claude settings with .env protection"
fi

# Ensure workspace directory is writable by target user
# Cross-group ownership: group www-data (33) for user container compatibility
# Safe to chown -R: dedicated PocketDev volume, all files should be owned by target user
chown -R "${TARGET_UID}:33" /workspace 2>/dev/null || true
chmod 775 /workspace 2>/dev/null || true
find /workspace -type d -exec chmod 775 {} \; 2>/dev/null || true

# =============================================================================
# STORAGE AND CACHE PERMISSIONS
# =============================================================================
# Fix permissions on Laravel storage and cache directories.
# Cross-group ownership: group www-data (33) for user container compatibility

echo "Setting storage permissions..."
chgrp -R 33 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
find /var/www/storage -type d -exec chmod 775 {} \; 2>/dev/null || true
find /var/www/storage -type f -exec chmod 664 {} \; 2>/dev/null || true
find /var/www/bootstrap/cache -type d -exec chmod 775 {} \; 2>/dev/null || true
find /var/www/bootstrap/cache -type f -exec chmod 664 {} \; 2>/dev/null || true

# Fix /tmp permissions for cross-group access (shared volume between containers)
# Ensures files created by any user are accessible by www-data group
chgrp -R 33 /tmp 2>/dev/null || true
chmod -R g+rwX /tmp 2>/dev/null || true

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

# Fix permissions on Claude config files so PHP-FPM (www-data) can read/write them
# Claude CLI creates these with 600, we need 660 for group (appgroup) write access
# Done after DB wait to ensure volumes are fully mounted
chmod 660 /home/appuser/.claude.json /home/appuser/.claude.json.backup 2>/dev/null || true
chmod 664 /home/appuser/.claude/settings.json 2>/dev/null || true

# =============================================================================
# SYSTEM PACKAGE INSTALLATION (requires root)
# =============================================================================
# Install user-configured system packages so they're available for workers.
if [ -x /usr/local/bin/install-system-packages ]; then
    /usr/local/bin/install-system-packages || echo "Warning: System package installation had errors"
fi

# =============================================================================
# CLAUDE CODE VERSION OVERRIDE
# =============================================================================
# If CLAUDE_CODE_VERSION is set, reinstall Claude Code at that specific version.
# This is a fallback mechanism for pinning to a known-working version.
# Example: CLAUDE_CODE_VERSION=2.1.17

if [ -n "$CLAUDE_CODE_VERSION" ]; then
    current_version=$(claude --version 2>/dev/null | head -1 | awk '{print $1}' || echo "unknown")
    if [ "$current_version" != "$CLAUDE_CODE_VERSION" ]; then
        echo "📦 Installing Claude Code version $CLAUDE_CODE_VERSION (current: $current_version)..."
        if npm install -g "@anthropic-ai/claude-code@$CLAUDE_CODE_VERSION" --force 2>&1; then
            new_version=$(claude --version 2>/dev/null | head -1 | awk '{print $1}' || echo "unknown")
            echo "  ✓ Claude Code $new_version installed"
        else
            echo "  ✗ Failed to install Claude Code $CLAUDE_CODE_VERSION"
        fi
    else
        echo "Claude Code already at requested version $CLAUDE_CODE_VERSION"
    fi
fi

# =============================================================================
# DROP PRIVILEGES AND START WORKERS
# =============================================================================

echo "Starting supervisord as appuser..."

# Ensure stdout/stderr are writable by the target user
# This is needed because supervisor logs to /dev/stdout and /dev/stderr
chmod 666 /dev/stdout /dev/stderr 2>/dev/null || true

# Ensure supervisord log/pid files are writable by TARGET_USER
# /tmp is a shared volume - files may exist from previous runs with different ownership
# Guard against symlink traversal (same pattern as /tmp/pocketdev*)
for f in /tmp/supervisord.log /tmp/supervisord.pid /tmp/supervisor.sock; do
    if [ -L "$f" ]; then
        echo "WARN: $f is a symlink; removing before recreation" >&2
        rm -f "$f" 2>/dev/null || true
    fi
    touch "$f" 2>/dev/null || true
    chown "${TARGET_UID}:33" "$f" 2>/dev/null || true
done

# Ensure queue worker log files are writable by TARGET_USER
# Worker count is controlled by PD_QUEUE_WORKERS (default 20). Pre-touch up to 30
# slots so ownership is set correctly even if numprocs is changed at runtime.
for i in $(seq -f '%02g' 0 29); do
    for f in "/tmp/queue-worker-${i}.log" "/tmp/queue-worker-${i}-error.log"; do
        if [ -L "$f" ]; then
            echo "WARN: $f is a symlink; removing before recreation" >&2
            rm -f "$f" 2>/dev/null || true
        fi
        touch "$f" 2>/dev/null || true
        chown "${TARGET_UID}:33" "$f" 2>/dev/null || true
    done
done

# Ensure scheduler log files are writable by TARGET_USER
for f in /tmp/scheduler.log /tmp/scheduler-error.log; do
    if [ -L "$f" ]; then
        echo "WARN: $f is a symlink; removing before recreation" >&2
        rm -f "$f" 2>/dev/null || true
    fi
    touch "$f" 2>/dev/null || true
    chown "${TARGET_UID}:33" "$f" 2>/dev/null || true
done

# Set group-writable umask so www-data (in appgroup) can edit files created by appuser
# Default umask 022 creates 644 files; umask 002 creates 664 files (group-writable)
umask 002

# Use exec to replace this shell with supervisord (proper signal handling)
# gosu with username (not UID:GID) properly initializes supplementary groups
# This is critical for docker socket access via hostdocker_runtime group
exec gosu appuser /usr/bin/supervisord -c /etc/supervisor/conf.d/queue-workers.conf
