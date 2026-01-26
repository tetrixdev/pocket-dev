#!/bin/bash
set -e

# =============================================================================
# PocketDev Queue Worker Entrypoint (Production)
# Uses gosu privilege drop pattern (same as official Docker images)
# - Runs as root initially for privileged operations (apt-get, group setup)
# - Drops to TARGET_UID before starting supervisord
# =============================================================================

# Runtime configurable UID (from compose.yml environment)
# GID is always 33 (www-data) for cross-group ownership model
TARGET_UID="${PD_TARGET_UID:-1000}"
TARGET_GID=33  # www-data group - enables user containers to access files

echo "Starting queue container initialization..."

# =============================================================================
# USER SETUP (cross-group ownership model)
# =============================================================================
# appuser: primary group www-data (33), secondary group appgroup (1000)
# This enables bidirectional file access between appuser and www-data processes.

# Ensure appgroup (1000) exists for cross-group ownership
if ! getent group 1000 > /dev/null 2>&1; then
    groupadd -g 1000 appgroup 2>/dev/null || true
fi

# Create a user for TARGET_UID if it doesn't exist
# Cross-group ownership: primary group www-data (33), secondary group appgroup (1000)
if ! getent passwd "$TARGET_UID" > /dev/null 2>&1; then
    # UID doesn't exist - create it with a collision-safe name
    if getent passwd appuser > /dev/null 2>&1; then
        # "appuser" name is taken (by UID 1000), use unique name
        useradd -u "$TARGET_UID" -g 33 -G 1000 -d /home/appuser -s /bin/bash "appuser_$TARGET_UID" 2>/dev/null || true
    else
        useradd -u "$TARGET_UID" -g 33 -G 1000 -d /home/appuser -s /bin/bash appuser 2>/dev/null || true
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
mkdir -p /home/appuser/.claude /home/appuser/.codex 2>/dev/null || true
chown -R "${TARGET_UID}:33" /home/appuser 2>/dev/null || true
chmod 775 /home/appuser /home/appuser/.claude /home/appuser/.codex 2>/dev/null || true

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

# Export user-configured credentials as environment variables
# These will be inherited by supervisord and all queue workers
echo "Loading credentials into environment..."
set +e  # Disable exit-on-error so we can handle failures gracefully
cred_raw=$(cd /var/www && php artisan credential export 2>&1)
cred_exit_code=$?
set -e  # Re-enable exit-on-error
if [ $cred_exit_code -ne 0 ]; then
    echo "  FATAL: Credential export failed (exit code $cred_exit_code)"
    echo "  Check database connectivity and credential configuration."
    exit 1
fi
# Filter to only valid export lines (ignores any warnings/noise in output)
cred_exports=$(echo "$cred_raw" | grep '^export ' || true)
if [ -n "$cred_exports" ]; then
    eval "$cred_exports"
    cred_count=$(echo "$cred_exports" | grep -c '^export ' || true)
    echo "  Loaded $cred_count credential(s)"
else
    echo "  No credentials configured"
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
