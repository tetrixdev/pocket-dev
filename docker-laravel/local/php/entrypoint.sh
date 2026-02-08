#!/bin/bash
set -e

# =============================================================================
# PocketDev PHP-FPM Local Development Entrypoint
# Uses gosu privilege drop pattern (same as official Docker images)
# - Runs as root initially for privileged operations
# - Drops to TARGET_UID before starting PHP-FPM
# =============================================================================

echo "🚀 Configuring PHP development environment..."

# Runtime configurable UID/GID (from compose.yml environment)
TARGET_UID="${PD_TARGET_UID:-1000}"
TARGET_GID="${PD_TARGET_GID:-1000}"

# =============================================================================
# PHASE 1: ROOT OPERATIONS (permissions, groups)
# =============================================================================

# Set up Docker socket access for TARGET_UID
# PD_DOCKER_GID is passed from compose.yml and matches the host's docker group
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

    # Get the username for TARGET_UID
    TARGET_USER=$(getent passwd "$TARGET_UID" | cut -d: -f1)
    if [ -n "$TARGET_USER" ]; then
        usermod -aG "$DOCKER_GROUP_NAME" "$TARGET_USER" 2>/dev/null || true
        echo "  ✅ Added $TARGET_USER to group $DOCKER_GROUP_NAME (GID $PD_DOCKER_GID)"
    fi

    # Also add www-data for PHP-FPM workers (web app needs Docker access)
    usermod -aG "$DOCKER_GROUP_NAME" www-data 2>/dev/null || true
    echo "  ✅ Added www-data to group $DOCKER_GROUP_NAME (GID $PD_DOCKER_GID)"

    # Verify docker socket is accessible
    if [ -S /var/run/docker.sock ]; then
        chgrp "$DOCKER_GROUP_NAME" /var/run/docker.sock 2>/dev/null || true
        chmod 660 /var/run/docker.sock 2>/dev/null || true
    fi
fi

# Set up home directory with correct ownership (cross-group: appuser:www-data)
export HOME=/home/appuser
mkdir -p "$HOME/.claude" "$HOME/.codex" "$HOME/.npm" "$HOME/.composer" 2>/dev/null || true
chown -R "${TARGET_UID}:33" "$HOME" 2>/dev/null || true
chmod 775 "$HOME" "$HOME/.claude" "$HOME/.codex" 2>/dev/null || true

# Fix permissions for /pocketdev-source (dogfooding - AI edits PocketDev source)
# PHP-FPM runs as www-data, so we need group ownership for git operations
# This avoids the CVE-2022-24765 "dubious ownership" error
if [ -d "/pocketdev-source" ]; then
    echo "Setting permissions on /pocketdev-source..."
    chgrp -R 33 /pocketdev-source 2>/dev/null || true
    find /pocketdev-source -type d -exec chmod g+rwx {} \; 2>/dev/null || true
    find /pocketdev-source -type f -exec chmod g+rw {} \; 2>/dev/null || true
fi

# Check if running as main PHP container (no args or php-fpm)
# vs secondary container (queue worker, scheduler, etc.)
if [ $# -eq 0 ] || [ "$1" = "php-fpm" ]; then
    # Main PHP container: run full setup

    # Fix storage and cache permissions (as root)
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

    # Fix permissions for pocketdev storage volume
    if [ -d "/var/www/storage/pocketdev" ]; then
        echo "Setting permissions on /var/www/storage/pocketdev..."
        chgrp -R 33 /var/www/storage/pocketdev 2>/dev/null || true
        find /var/www/storage/pocketdev -type d -exec chmod 775 {} \; 2>/dev/null || true
        find /var/www/storage/pocketdev -type f -exec chmod 664 {} \; 2>/dev/null || true
    fi

    # =============================================================================
    # PHASE 2: TARGET USER OPERATIONS (composer, npm, git, artisan)
    # =============================================================================

    # Install composer dependencies as appuser
    echo "Installing composer dependencies..."
    gosu appuser bash -c "
        export HOME=/home/appuser
        export COMPOSER_HOME=/tmp/.composer
        cd /var/www && composer install && composer dump-autoload -o
    "

    # Generate Laravel application key if not set
    if [ -f "/var/www/.env" ] && ! grep -q "^PD_APP_KEY=.\+" /var/www/.env; then
        echo "Generating Laravel application key..."
        PD_APP_KEY="base64:$(gosu appuser php -r 'echo base64_encode(random_bytes(32));')"
        sed -i "s|^PD_APP_KEY=.*|PD_APP_KEY=$PD_APP_KEY|" /var/www/.env
    fi

    # Create storage symlink if it doesn't exist
    if [ ! -L "/var/www/public/storage" ]; then
        echo "Creating storage symlink..."
        gosu appuser php /var/www/artisan storage:link --no-interaction
    fi

    # Install npm dependencies and build assets as appuser
    if [ -f "/var/www/package.json" ]; then
        echo "Installing npm dependencies..."
        gosu appuser bash -c "
            export HOME=/home/appuser
            export NPM_CONFIG_CACHE=/tmp/.npm
            cd /var/www && npm install
        "

        echo "Building frontend assets..."
        gosu appuser bash -c "
            export HOME=/home/appuser
            export NPM_CONFIG_CACHE=/tmp/.npm
            cd /var/www && npm run build
        "
        echo "✅ Built assets ready in public/build/"
    fi

    # Run Laravel commands as appuser
    echo "Running Laravel setup..."
    gosu appuser php /var/www/artisan migrate --force
    gosu appuser php /var/www/artisan optimize:clear
    gosu appuser php /var/www/artisan config:cache
    gosu appuser php /var/www/artisan queue:restart

    # =============================================================================
    # SYSTEM PACKAGE INSTALLATION (requires root)
    # =============================================================================
    # Install user-configured system packages so they're available for workers.
    if [ -x /usr/local/bin/install-system-packages ]; then
        /usr/local/bin/install-system-packages || echo "Warning: System package installation had errors"
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
    # PHASE 3: START PHP-FPM AS ROOT (standard Docker practice)
    # =============================================================================

    # PHP-FPM master runs as root, pool workers run as www-data (via www.conf)
    # This is standard Docker practice - see https://github.com/docker-library/php/issues/70
    # Running master as non-root fails with "failed to open error_log (/proc/self/fd/2): Permission denied"

    echo "✅ Development environment ready"
    echo "🚀 Starting PHP-FPM (master as root, workers as www-data)..."

    # Set group-writable umask for cross-group ownership model
    # Default umask 022 creates 644 files; umask 002 creates 664 files (group-writable)
    umask 002

    # Start PHP-FPM as root - pool workers will be www-data per www.conf
    exec php-fpm
else
    # Secondary container (queue worker, scheduler, etc.):
    # Wait for main container to complete migrations, then run the command
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

    # Drop privileges and execute the command
    # Using username (not UID:GID) to properly initialize supplementary groups
    exec gosu appuser "$@"
fi
