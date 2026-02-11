#!/bin/bash
set -e

# =============================================================================
# PocketDev Queue Worker Entrypoint (Production)
# Uses gosu privilege drop pattern (same as official Docker images)
# - Runs as root initially for privileged operations (apt-get, group setup)
# - Drops to TARGET_UID before starting supervisord
# =============================================================================

# Runtime configurable UID/GID (from compose.yml environment)
TARGET_UID="${PD_TARGET_UID:-1000}"
TARGET_GID="${PD_TARGET_GID:-1000}"

echo "Starting queue container initialization..."

# =============================================================================
# USER SETUP (cross-group ownership model)
# =============================================================================
# appuser: primary group www-data (33), secondary group TARGET_GID (for host)
# This enables file access between appuser and www-data processes.

# Ensure appgroup exists with TARGET_GID (for host file access)
if ! getent group "$TARGET_GID" > /dev/null 2>&1; then
    groupadd -g "$TARGET_GID" appgroup 2>/dev/null || true
fi

# Create a user for TARGET_UID if it doesn't exist
# Cross-group ownership: primary group www-data (33), secondary group TARGET_GID (for host)
if ! getent passwd "$TARGET_UID" > /dev/null 2>&1; then
    # UID doesn't exist - create it with a collision-safe name
    if getent passwd appuser > /dev/null 2>&1; then
        # "appuser" name is taken (by UID 1000), use unique name
        useradd -u "$TARGET_UID" -g 33 -G "$TARGET_GID" -d /home/appuser -s /bin/bash "appuser_$TARGET_UID" 2>/dev/null || true
    else
        useradd -u "$TARGET_UID" -g 33 -G "$TARGET_GID" -d /home/appuser -s /bin/bash appuser 2>/dev/null || true
    fi
fi

# Get the actual username for TARGET_UID (fail if creation failed)
TARGET_USER=$(getent passwd "$TARGET_UID" | cut -d: -f1)
if [ -z "$TARGET_USER" ]; then
    echo "FATAL: Failed to create or find user for UID $TARGET_UID" >&2
    exit 1
fi

# =============================================================================
# PRIVILEGED SECTION - Runs as root
# =============================================================================

# Set up Docker socket access for TARGET_UID
# PD_DOCKER_GID is passed from compose.yml and matches the host's docker group
if [ -n "$PD_DOCKER_GID" ]; then
    echo "🐳 Setting up Docker socket access for $TARGET_USER..."

    # Create the docker group with the host's GID if it doesn't exist
    if ! getent group "$PD_DOCKER_GID" > /dev/null 2>&1; then
        groupadd -g "$PD_DOCKER_GID" hostdocker_runtime 2>/dev/null || true
    fi

    # Get the group name for this GID
    DOCKER_GROUP_NAME=$(getent group "$PD_DOCKER_GID" | cut -d: -f1)
    if [ -z "$DOCKER_GROUP_NAME" ]; then
        DOCKER_GROUP_NAME="hostdocker_runtime"
    fi

    # Add TARGET_USER to docker group
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

# Ensure home directory exists and has correct permissions for TARGET_UID
# Cross-group ownership: appuser:www-data (TARGET_UID:33) with group-writable permissions
mkdir -p /home/appuser/.claude /home/appuser/.codex /home/appuser/.docker 2>/dev/null || true
chown -R "${TARGET_UID}:33" /home/appuser 2>/dev/null || true
chmod 775 /home/appuser /home/appuser/.claude /home/appuser/.codex /home/appuser/.docker 2>/dev/null || true

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

# Ensure workspace volume is owned by TARGET_UID (for backup/restore and UID changes)
# Safe to chown -R: dedicated PocketDev volume, all files should be owned by target user
# Cross-group ownership: group www-data (33) for user container compatibility
chown -R "${TARGET_UID}:33" /workspace 2>/dev/null || true
chmod 775 /workspace 2>/dev/null || true
find /workspace -type d -exec chmod 775 {} \; 2>/dev/null || true

# =============================================================================
# VOLUME PERMISSIONS (for backup/restore and UID changes)
# =============================================================================
# When restoring from backup or changing PD_TARGET_UID, volume data may have
# wrong ownership. Fix permissions on all volumes that should be owned by TARGET_UID.
# Cross-group ownership: group www-data (33) for user container compatibility

# pocketdev-storage volume (/var/www/storage/pocketdev)
if [ -d /var/www/storage/pocketdev ]; then
    chown -R "${TARGET_UID}:33" /var/www/storage/pocketdev 2>/dev/null || true
    find /var/www/storage/pocketdev -type d -exec chmod 775 {} \; 2>/dev/null || true
    find /var/www/storage/pocketdev -type f -exec chmod 664 {} \; 2>/dev/null || true
fi

# shared-tmp volume (/tmp) - fix PocketDev-specific directories
# Don't chown all of /tmp as other processes may use it
# Guard against symlink traversal (shared /tmp could have malicious symlinks)
for d in /tmp/pocketdev /tmp/pocketdev-uploads; do
    if [ -L "$d" ]; then
        echo "WARN: $d is a symlink; skipping ownership fix" >&2
        continue
    fi
    mkdir -p "$d" 2>/dev/null || true
    chown -R "${TARGET_UID}:33" "$d" 2>/dev/null || true
    find "$d" -type d -exec chmod 775 {} \; 2>/dev/null || true
    find "$d" -type f -exec chmod 664 {} \; 2>/dev/null || true
done

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

# Fix permissions for mounted config volumes (for config editor)
if [ -d "/etc/nginx-proxy-config" ]; then
    echo "Setting permissions on /etc/nginx-proxy-config..."
    chgrp -R 33 /etc/nginx-proxy-config 2>/dev/null || true
    find /etc/nginx-proxy-config -type d -exec chmod 775 {} \; 2>/dev/null || true
    find /etc/nginx-proxy-config -type f -exec chmod 664 {} \; 2>/dev/null || true
fi

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

# Fix permissions on Claude config files so they're accessible
# Claude CLI creates these with 600, we need 660 for group write access
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
# CREDENTIAL LOADING (requires DB ready)
# =============================================================================
# Export user-configured credentials as environment variables.
# These will be inherited by all worker processes.
if [ -x /usr/local/bin/load-credentials ]; then
    if ! cred_output=$(/usr/local/bin/load-credentials 2>&1); then
        echo "Credential loading failed - aborting startup"
        echo "$cred_output"
        exit 1
    fi
    cred_exports=$(echo "$cred_output" | grep '^export ' || true)
    if [ -n "$cred_exports" ]; then
        eval "$cred_exports"
    fi
fi

# =============================================================================
# DROP PRIVILEGES AND START WORKERS
# =============================================================================

echo "Starting supervisord as $TARGET_USER..."

# Ensure stdout/stderr are writable by the target user
# This is needed because supervisor logs to /dev/stdout and /dev/stderr
chmod 666 /dev/stdout /dev/stderr 2>/dev/null || true

# Ensure supervisord log/pid files are writable by TARGET_USER
# /tmp is a shared volume - files may exist from previous runs with different ownership
# Guard against symlink traversal (same pattern as /tmp/pocketdev*)
for f in /tmp/supervisord.log /tmp/supervisord.pid; do
    if [ -L "$f" ]; then
        echo "WARN: $f is a symlink; removing before recreation" >&2
        rm -f "$f" 2>/dev/null || true
    fi
    touch "$f" 2>/dev/null || true
    chown "${TARGET_UID}:33" "$f" 2>/dev/null || true
done

# Ensure queue worker log files are writable by TARGET_USER
# Supervisor creates 10 workers (00-09) with stdout and stderr logs each
for i in $(seq -f '%02g' 0 9); do
    for f in "/tmp/queue-worker-${i}.log" "/tmp/queue-worker-${i}-error.log"; do
        if [ -L "$f" ]; then
            echo "WARN: $f is a symlink; removing before recreation" >&2
            rm -f "$f" 2>/dev/null || true
        fi
        touch "$f" 2>/dev/null || true
        chown "${TARGET_UID}:33" "$f" 2>/dev/null || true
    done
done

# Set group-writable umask so www-data (in appgroup) can edit files created by appuser
# Default umask 022 creates 644 files; umask 002 creates 664 files (group-writable)
umask 002

# Use exec to replace this shell with supervisord (proper signal handling)
# gosu with username (not UID:GID) properly initializes supplementary groups
# This is critical for docker socket access via hostdocker_runtime group
exec gosu "$TARGET_USER" /usr/bin/supervisord -c /etc/supervisor/conf.d/queue-workers.conf
