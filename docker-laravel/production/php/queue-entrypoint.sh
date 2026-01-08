#!/bin/bash
set -e

# =============================================================================
# PocketDev Queue Worker Entrypoint (Production)
# Uses gosu privilege drop pattern (same as official Docker images)
# - Runs as root initially for privileged operations (apt-get, group setup)
# - Drops to www-data before starting supervisord
# =============================================================================

# Production uses www-data consistently (not TARGET_UID)
echo "Starting queue container initialization..."

# =============================================================================
# PRIVILEGED SECTION - Runs as root
# =============================================================================

# Set up Docker socket access for www-data
if [ -n "$DOCKER_GID" ]; then
    echo "🐳 Setting up Docker socket access for www-data..."

    # Create the docker group with the host's GID if it doesn't exist
    if ! getent group "$DOCKER_GID" > /dev/null 2>&1; then
        groupadd -g "$DOCKER_GID" hostdocker_runtime 2>/dev/null || true
    fi

    # Get the group name for this GID
    DOCKER_GROUP_NAME=$(getent group "$DOCKER_GID" | cut -d: -f1)
    if [ -z "$DOCKER_GROUP_NAME" ]; then
        DOCKER_GROUP_NAME="hostdocker_runtime"
    fi

    # Add www-data to docker group
    usermod -aG "$DOCKER_GROUP_NAME" www-data 2>/dev/null || true
    echo "  ✅ Added www-data to group $DOCKER_GROUP_NAME (GID $DOCKER_GID)"

    # Ensure docker socket has correct group ownership
    if [ -S /var/run/docker.sock ]; then
        chgrp "$DOCKER_GROUP_NAME" /var/run/docker.sock 2>/dev/null || true
        chmod 660 /var/run/docker.sock 2>/dev/null || true
    fi
fi

# Ensure home directory exists and has correct permissions for www-data
mkdir -p /home/appuser/.claude /home/appuser/.codex 2>/dev/null || true
chown -R www-data:www-data /home/appuser 2>/dev/null || true
chmod 775 /home/appuser /home/appuser/.claude /home/appuser/.codex 2>/dev/null || true

# Ensure workspace directory is writable by www-data
chown www-data:www-data /workspace 2>/dev/null || true
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
# /tmp may be a shared Docker volume between containers. During container
# STARTUP, writing to shared /tmp can cause curl write errors.
# SOLUTION: We auto-rewrite /tmp/ to /var/tmp/ in install scripts below.
# /var/tmp is container-local and works reliably during startup.
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

                # Auto-rewrite /tmp/ to /var/tmp/ in install scripts (see comment above)
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

# =============================================================================
# CONFIGURE AS WWW-DATA - Using gosu for proper environment
# =============================================================================

# Configure git and GitHub CLI if credentials are provided
if [[ -n "$GIT_TOKEN" && -n "$GIT_USER_NAME" && -n "$GIT_USER_EMAIL" ]]; then
    echo "Configuring git credentials for queue worker..."

    # Write git-credentials as root first (avoids exposing token in ps via gosu bash -c)
    echo "https://token:$GIT_TOKEN@github.com" > /home/appuser/.git-credentials
    chown www-data:www-data /home/appuser/.git-credentials
    chmod 600 /home/appuser/.git-credentials

    # Configure git as www-data (no secrets in command args)
    gosu www-data bash -c "
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

echo "Starting supervisord as www-data..."

# Ensure stdout/stderr are writable by the target user
# This is needed because supervisor logs to /dev/stdout and /dev/stderr
chmod 666 /dev/stdout /dev/stderr 2>/dev/null || true

# Use exec to replace this shell with supervisord (proper signal handling)
# gosu with username (not UID:GID) properly initializes supplementary groups
exec gosu www-data /usr/bin/supervisord -c /etc/supervisor/conf.d/queue-workers.conf
