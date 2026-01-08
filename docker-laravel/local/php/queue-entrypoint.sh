#!/bin/bash
set -e

# =============================================================================
# PocketDev Queue Worker Entrypoint
# Uses gosu privilege drop pattern (same as official Docker images)
# - Runs as root initially for privileged operations (apt-get, group setup)
# - Drops to TARGET_UID before starting supervisord
# =============================================================================

TARGET_UID="${TARGET_UID:-1000}"
TARGET_GID="${TARGET_GID:-1000}"

echo "Starting queue container initialization..."

# =============================================================================
# PRIVILEGED SECTION - Runs as root
# =============================================================================

# Set up Docker socket access for TARGET_UID
if [ -n "$DOCKER_GID" ]; then
    echo "🐳 Setting up Docker socket access for UID $TARGET_UID..."

    # Create the docker group with the host's GID if it doesn't exist
    if ! getent group "$DOCKER_GID" > /dev/null 2>&1; then
        groupadd -g "$DOCKER_GID" hostdocker_runtime 2>/dev/null || true
    fi

    # Get the group name for this GID
    DOCKER_GROUP_NAME=$(getent group "$DOCKER_GID" | cut -d: -f1)
    if [ -z "$DOCKER_GROUP_NAME" ]; then
        DOCKER_GROUP_NAME="hostdocker_runtime"
    fi

    # Create a user for TARGET_UID if it doesn't exist (needed for group membership)
    if ! getent passwd "$TARGET_UID" > /dev/null 2>&1; then
        useradd -u "$TARGET_UID" -g "$TARGET_GID" -d /home/appuser -s /bin/bash appuser 2>/dev/null || true
    fi

    # Get the username for TARGET_UID and add to docker group
    TARGET_USER=$(getent passwd "$TARGET_UID" | cut -d: -f1)
    if [ -n "$TARGET_USER" ]; then
        usermod -aG "$DOCKER_GROUP_NAME" "$TARGET_USER" 2>/dev/null || true
        echo "  ✅ Added $TARGET_USER to group $DOCKER_GROUP_NAME (GID $DOCKER_GID)"
    fi

    # Ensure docker socket has correct group ownership
    if [ -S /var/run/docker.sock ]; then
        chgrp "$DOCKER_GROUP_NAME" /var/run/docker.sock 2>/dev/null || true
        chmod 660 /var/run/docker.sock 2>/dev/null || true
    fi
fi

# Ensure home directory exists and has correct permissions for target user
mkdir -p /home/appuser/.claude /home/appuser/.codex 2>/dev/null || true
chown -R "${TARGET_UID}:${TARGET_GID}" /home/appuser 2>/dev/null || true
chmod 775 /home/appuser /home/appuser/.claude /home/appuser/.codex 2>/dev/null || true

# Ensure workspace directory is writable by target user
chown "${TARGET_UID}:${TARGET_GID}" /workspace 2>/dev/null || true
chmod 775 /workspace 2>/dev/null || true

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

# =============================================================================
# SYSTEM PACKAGE INSTALLATION
# =============================================================================
# Install user-configured system packages from database (requires root).
#
# IMPORTANT: /tmp vs /var/tmp
# ---------------------------
# /tmp is a shared Docker volume (shared-tmp) between PHP and Queue containers.
# This allows agents to write temp files that PHP can read for the chat UI.
# However, during container STARTUP, writing to shared /tmp can cause issues:
#   - curl: (23) client returned ERROR on write
# This is a timing/race condition with the shared volume during early boot.
#
# SOLUTION: We auto-rewrite /tmp/ to /var/tmp/ in install scripts below.
# /var/tmp is container-local and works reliably during startup.
# Users can write scripts with /tmp naturally - it gets fixed automatically.
#
# The shared /tmp works fine at RUNTIME when agents use it.
# =============================================================================

# Ensure jq is installed first (needed for JSON parsing)
if ! command -v jq &> /dev/null; then
    echo "Installing jq (required for package management)..."
    if ! apt-get update -qq || ! apt-get install -y -qq jq; then
        echo "ERROR: Failed to install jq - marking all pending packages as failed"
        cd /var/www && php artisan system:package fail-all-pending --message="System error: failed to install jq (required for package management)" 2>/dev/null || true
    fi
fi

# Only proceed if jq is available
if command -v jq &> /dev/null; then
    packages_json=$(cd /var/www && php artisan system:package export-scripts 2>/dev/null)

    if [ -n "$packages_json" ] && [ "$packages_json" != "[]" ]; then
        # Verify JSON is parseable
        if ! echo "$packages_json" | jq -e . >/dev/null 2>&1; then
            echo "ERROR: Failed to parse package JSON - marking all pending packages as failed"
            cd /var/www && php artisan system:package fail-all-pending --message="System error: failed to parse package list JSON" 2>/dev/null || true
        else
            echo "Installing system packages..."

            # Parse JSON and install each package
            echo "$packages_json" | jq -c '.[]' 2>/dev/null | while read -r pkg; do
                pkg_id=$(echo "$pkg" | jq -r '.id')
                pkg_name=$(echo "$pkg" | jq -r '.name')
                pkg_script=$(echo "$pkg" | jq -r '.script')

                # Auto-rewrite /tmp/ to /var/tmp/ in install scripts
                # This fixes curl write errors caused by the shared /tmp volume during startup
                # Only replaces /tmp/ when preceded by space, quote, or equals (not /var/tmp/ or /path/tmp/)
                pkg_script=$(echo "$pkg_script" | sed -e 's| /tmp/| /var/tmp/|g' -e 's|"/tmp/|"/var/tmp/|g' -e "s|'/tmp/|'/var/tmp/|g" -e 's|=/tmp/|=/var/tmp/|g')

                # Mark as failed if no script defined
                if [ -z "$pkg_script" ] || [ "$pkg_script" = "null" ]; then
                    echo "  ✗ $pkg_name: No install script defined"
                    cd /var/www && php artisan system:package status-by-id --id="$pkg_id" --status=failed --message="No install script defined" 2>/dev/null || true
                    continue
                fi

                echo "  Installing: $pkg_name"

                # Run the install script (non-interactive)
                # Use && / || pattern to avoid set -e exiting on failure
                install_output=$(bash -c "$pkg_script" 2>&1) && install_status=0 || install_status=$?

                if [ $install_status -eq 0 ]; then
                    echo "  ✓ $pkg_name installed"
                    cd /var/www && php artisan system:package status-by-id --id="$pkg_id" --status=installed 2>/dev/null || true
                else
                    echo "  ✗ $pkg_name failed"
                    # Capture last few lines of error
                    error_msg=$(echo "$install_output" | tail -3 | tr '\n' ' ')
                    [ -z "$error_msg" ] && error_msg="Install script exited with code $install_status"
                    echo "    Error: $error_msg"
                    cd /var/www && php artisan system:package status-by-id --id="$pkg_id" --status=failed --message="$error_msg" 2>/dev/null || true
                fi
            done
            echo "System package installation complete"
        fi
    else
        echo "No system packages configured"
    fi
else
    echo "WARNING: jq not available - skipping package installation"
fi

# Export user-configured credentials as environment variables
# These will be inherited by supervisord and all queue workers
echo "Loading credentials into environment..."
cred_exports=$(cd /var/www && php artisan credential export 2>/dev/null)
if [ -n "$cred_exports" ]; then
    eval "$cred_exports"
    cred_count=$(echo "$cred_exports" | wc -l)
    echo "  Loaded $cred_count credential(s)"
else
    echo "  No credentials configured"
fi

# =============================================================================
# CONFIGURE AS TARGET USER - Using gosu for proper environment
# =============================================================================

# Configure git and GitHub CLI if credentials are provided
if [[ -n "$GIT_TOKEN" && -n "$GIT_USER_NAME" && -n "$GIT_USER_EMAIL" ]]; then
    echo "Configuring git credentials for queue worker..."

    # Write git-credentials as root first (avoids exposing token in ps via gosu bash -c)
    echo "https://token:$GIT_TOKEN@github.com" > /home/appuser/.git-credentials
    chown "${TARGET_UID}:${TARGET_GID}" /home/appuser/.git-credentials
    chmod 600 /home/appuser/.git-credentials

    # Configure git as target user (no secrets in command args)
    gosu appuser bash -c "
        export HOME=/home/appuser
        git config --global user.name \"$GIT_USER_NAME\" 2>/dev/null && \
        git config --global user.email \"$GIT_USER_EMAIL\" 2>/dev/null && \
        git config --global credential.helper store 2>/dev/null
    " && echo "Git and GitHub CLI configured for queue worker" \
      || echo "Warning: Could not configure git credentials - continuing without"
else
    echo "Git credentials not provided - skipping git/GitHub CLI setup"
fi

# =============================================================================
# DROP PRIVILEGES AND START WORKERS
# =============================================================================

echo "Starting supervisord as appuser..."

# Ensure stdout/stderr are writable by the target user
# This is needed because supervisor logs to /dev/stdout and /dev/stderr
chmod 666 /dev/stdout /dev/stderr 2>/dev/null || true

# Use exec to replace this shell with supervisord (proper signal handling)
# gosu with username (not UID:GID) properly initializes supplementary groups
# This is critical for docker socket access via hostdocker_runtime group
exec gosu appuser /usr/bin/supervisord -c /etc/supervisor/conf.d/queue-workers.conf
