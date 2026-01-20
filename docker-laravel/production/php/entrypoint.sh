#!/bin/bash
set -e

# =============================================================================
# PocketDev PHP-FPM Production Entrypoint
# Uses gosu privilege drop pattern (same as official Docker images)
# - Runs as root initially for privileged operations
# - PHP-FPM workers run as www-data (standard PHP-FPM architecture)
# - Files owned by TARGET_UID, accessible to www-data via appgroup membership
# =============================================================================

echo "Starting Laravel production container..."

# Runtime configurable UID/GID (from compose.yml environment)
TARGET_UID="${PD_TARGET_UID:-1000}"
TARGET_GID="${PD_TARGET_GID:-1000}"

# Set HOME for tools that expect a writable home directory
export HOME=/home/appuser

# =============================================================================
# USER SETUP (collision-safe)
# =============================================================================
# Handle UID/GID that may differ from the Dockerfile defaults (1000/1000).
# The Dockerfile pre-creates "appgroup" with GID 1000, so we need unique names
# if TARGET_GID differs to avoid "name already in use" errors.

# First ensure the group exists for TARGET_GID
if ! getent group "$TARGET_GID" > /dev/null 2>&1; then
    # GID doesn't exist - create it with a collision-safe name
    if getent group appgroup > /dev/null 2>&1; then
        # "appgroup" name is taken (by GID 1000), use unique name
        groupadd -g "$TARGET_GID" "appgroup_$TARGET_GID" 2>/dev/null || true
    else
        groupadd -g "$TARGET_GID" appgroup 2>/dev/null || true
    fi
fi

# Get the actual group name for TARGET_GID (fail if creation failed)
TARGET_GROUP=$(getent group "$TARGET_GID" | cut -d: -f1)
if [ -z "$TARGET_GROUP" ]; then
    echo "FATAL: Failed to create or find group for GID $TARGET_GID" >&2
    exit 1
fi

# Create a user for TARGET_UID if it doesn't exist
if ! getent passwd "$TARGET_UID" > /dev/null 2>&1; then
    # UID doesn't exist - create it with a collision-safe name
    if getent passwd appuser > /dev/null 2>&1; then
        # "appuser" name is taken (by UID 1000), use unique name
        useradd -u "$TARGET_UID" -g "$TARGET_GID" -d /home/appuser -s /bin/bash "appuser_$TARGET_UID" 2>/dev/null || true
    else
        useradd -u "$TARGET_UID" -g "$TARGET_GID" -d /home/appuser -s /bin/bash appuser 2>/dev/null || true
    fi
fi

# Get the actual username for TARGET_UID (fail if creation failed)
TARGET_USER=$(getent passwd "$TARGET_UID" | cut -d: -f1)
if [ -z "$TARGET_USER" ]; then
    echo "FATAL: Failed to create or find user for UID $TARGET_UID" >&2
    exit 1
fi

# Add www-data to TARGET_GROUP so PHP-FPM can read/write files owned by TARGET_UID
usermod -aG "$TARGET_GROUP" www-data 2>/dev/null || true

# Ensure CLI config directories exist and are owned by TARGET_UID
mkdir -p "$HOME/.claude" "$HOME/.codex" 2>/dev/null || true
chown -R "${TARGET_UID}:${TARGET_GID}" "$HOME" 2>/dev/null || true
chmod 775 "$HOME" "$HOME/.claude" "$HOME/.codex" 2>/dev/null || true

# =============================================================================
# DOCKER SOCKET ACCESS
# =============================================================================
# Set up Docker socket access (needed for backup/restore operations)
# PD_DOCKER_GID is passed from compose.yml and matches the host's docker group
if [ -n "$PD_DOCKER_GID" ]; then
    echo "🐳 Setting up Docker socket access..."

    # Create the docker group with the host's GID if it doesn't exist
    if ! getent group "$PD_DOCKER_GID" > /dev/null 2>&1; then
        groupadd -g "$PD_DOCKER_GID" hostdocker_runtime 2>/dev/null || true
    fi

    # Get the group name for this GID
    DOCKER_GROUP_NAME=$(getent group "$PD_DOCKER_GID" | cut -d: -f1)
    if [ -z "$DOCKER_GROUP_NAME" ]; then
        DOCKER_GROUP_NAME="hostdocker_runtime"
    fi

    # Add www-data to docker group (for PHP-FPM workers)
    usermod -aG "$DOCKER_GROUP_NAME" www-data 2>/dev/null || true
    echo "  ✅ Added www-data to group $DOCKER_GROUP_NAME (GID $PD_DOCKER_GID)"

    # Also add TARGET_USER for CLI operations
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

# =============================================================================
# VOLUME PERMISSIONS (for backup/restore and UID/GID changes)
# =============================================================================
# When restoring from backup or changing PD_TARGET_UID/GID, volume data may have
# wrong ownership. Fix permissions on all volumes that should be owned by TARGET_UID.

# workspace volume - safe to chown -R (dedicated PocketDev volume)
chown -R "${TARGET_UID}:${TARGET_GID}" /workspace 2>/dev/null || true
chmod 775 /workspace 2>/dev/null || true

# pocketdev-storage volume (/var/www/storage/pocketdev)
# Use 2775 for directories (setgid) and 664 for files (no execute on data files)
if [ -d /var/www/storage/pocketdev ]; then
    chown -R "${TARGET_UID}:${TARGET_GID}" /var/www/storage/pocketdev 2>/dev/null || true
    find /var/www/storage/pocketdev -type d -exec chmod 2775 {} \; 2>/dev/null || true
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
    chown -R "${TARGET_UID}:${TARGET_GID}" "$d" 2>/dev/null || true
    find "$d" -type d -exec chmod 2775 {} \; 2>/dev/null || true
    find "$d" -type f -exec chmod 664 {} \; 2>/dev/null || true
done

# =============================================================================
# STORAGE AND CACHE PERMISSIONS
# =============================================================================
# Fix permissions on Laravel storage and cache directories.
# Production images bake in files as www-data:www-data, but queue workers run as
# TARGET_USER. This ensures both PHP-FPM (www-data) and queue workers can write.

echo "Setting storage permissions..."
chgrp -R "$TARGET_GID" /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
find /var/www/storage -type d -exec chmod 2775 {} \; 2>/dev/null || true
find /var/www/storage -type f -exec chmod 664 {} \; 2>/dev/null || true
find /var/www/bootstrap/cache -type d -exec chmod 2775 {} \; 2>/dev/null || true
find /var/www/bootstrap/cache -type f -exec chmod 664 {} \; 2>/dev/null || true

# Fix permissions for mounted config volumes (for config editor)
if [ -d "/etc/nginx-proxy-config" ]; then
    echo "Setting permissions on /etc/nginx-proxy-config..."
    chgrp -R "$TARGET_GID" /etc/nginx-proxy-config 2>/dev/null || true
    find /etc/nginx-proxy-config -type d -exec chmod 2775 {} \; 2>/dev/null || true
    find /etc/nginx-proxy-config -type f -exec chmod 664 {} \; 2>/dev/null || true
fi

# Check if running as main PHP container (no args or php-fpm)
# vs secondary container (queue worker, scheduler, etc.)
if [ $# -eq 0 ] || [ "$1" = "php-fpm" ]; then
    # Main PHP container: run migrations, caching, and start PHP-FPM

    # Generate Laravel application key if not set
    if [ -f ".env" ] && ! grep -q "^PD_APP_KEY=.\+" .env; then
        echo "Generating Laravel application key..."
        PD_APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        sed -i "s|^PD_APP_KEY=.*|PD_APP_KEY=$PD_APP_KEY|" .env
    fi

    # Run Laravel production optimizations (as www-data, which is in TARGET_GID group)
    echo "Running Laravel optimizations..."
    gosu www-data php artisan migrate --force --no-interaction
    gosu www-data php artisan config:cache --no-interaction
    gosu www-data php artisan route:cache --no-interaction
    gosu www-data php artisan view:cache --no-interaction
    gosu www-data php artisan queue:restart --no-interaction

    echo "Laravel application ready for production"

    # PHP-FPM master runs as root, pool workers run as www-data (via www.conf)
    # This is standard Docker practice - see https://github.com/docker-library/php/issues/70
    # Running master as non-root fails with "failed to open error_log (/proc/self/fd/2): Permission denied"
    # Set group-writable umask so appuser (in appgroup) can edit files created by www-data
    # Default umask 022 creates 644 files; umask 002 creates 664 files (group-writable)
    umask 002

    echo "Starting PHP-FPM (master as root, workers as www-data)..."

    # Start PHP-FPM as root - pool workers will be www-data per www.conf
    exec php-fpm
else
    # Secondary container (queue worker, scheduler, etc.):
    # Wait for main container to complete migrations, then run the command
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
    exec gosu www-data "$@"
fi
