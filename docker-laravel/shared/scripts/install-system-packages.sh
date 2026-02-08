#!/bin/bash
#
# install-system-packages.sh - Installs user-configured system packages
#
# This script is called from both PHP and Queue container entrypoints.
# It requires:
#   - Database to be ready (migrations complete)
#   - Running as root (for apt-get)
#   - Working directory /var/www OR LARAVEL_PATH set
#
# Returns 0 on success (even if some packages fail - status is in DB)

LARAVEL_PATH="${LARAVEL_PATH:-/var/www}"

echo "Installing system packages..."

# Ensure jq is installed first (needed for JSON parsing)
if ! command -v jq &> /dev/null; then
    echo "  Installing jq (required for package management)..."
    if ! apt-get update -qq || ! apt-get install -y -qq jq; then
        echo "  ERROR: Failed to install jq - marking all pending packages as failed"
        cd "$LARAVEL_PATH" && php artisan system:package fail-all-pending \
            --message="System error: failed to install jq (required for package management)" 2>/dev/null || true
        exit 1
    fi
fi

# Get packages from database
packages_json=$(cd "$LARAVEL_PATH" && php artisan system:package export-scripts 2>/dev/null)

if [ -z "$packages_json" ] || [ "$packages_json" = "[]" ]; then
    echo "  No system packages configured"
    exit 0
fi

# Verify JSON is parseable
if ! echo "$packages_json" | jq -e . >/dev/null 2>&1; then
    echo "  ERROR: Failed to parse package JSON - marking all pending packages as failed"
    cd "$LARAVEL_PATH" && php artisan system:package fail-all-pending \
        --message="System error: failed to parse package list JSON" 2>/dev/null || true
    exit 1
fi

# Parse JSON and install each package
echo "$packages_json" | jq -c '.[]' 2>/dev/null | while read -r pkg; do
    pkg_id=$(echo "$pkg" | jq -r '.id')
    pkg_name=$(echo "$pkg" | jq -r '.name')
    pkg_script=$(echo "$pkg" | jq -r '.script')

    # Auto-rewrite /tmp/ to /var/tmp/ in install scripts
    # This fixes curl write errors caused by the shared /tmp volume during startup
    # Only replaces /tmp/ when preceded by space, quote, or equals (not /var/tmp/ or /path/tmp/)
    pkg_script=$(echo "$pkg_script" | sed \
        -e 's| /tmp/| /var/tmp/|g' \
        -e 's|"/tmp/|"/var/tmp/|g' \
        -e "s|'/tmp/|'/var/tmp/|g" \
        -e 's|=/tmp/|=/var/tmp/|g')

    # Mark as failed if no script defined
    if [ -z "$pkg_script" ] || [ "$pkg_script" = "null" ]; then
        echo "  ✗ $pkg_name: No install script defined"
        cd "$LARAVEL_PATH" && php artisan system:package status-by-id \
            --id="$pkg_id" --status=failed --message="No install script defined" 2>/dev/null || true
        continue
    fi

    echo "  Installing: $pkg_name"

    # Run the install script (non-interactive)
    # Use && / || pattern to avoid set -e exiting on failure
    install_output=$(bash -c "$pkg_script" 2>&1) && install_status=0 || install_status=$?

    if [ $install_status -eq 0 ]; then
        echo "  ✓ $pkg_name installed"
        cd "$LARAVEL_PATH" && php artisan system:package status-by-id \
            --id="$pkg_id" --status=installed 2>/dev/null || true
    else
        echo "  ✗ $pkg_name failed"
        # Capture last few lines of error
        error_msg=$(echo "$install_output" | tail -3 | tr '\n' ' ')
        [ -z "$error_msg" ] && error_msg="Install script exited with code $install_status"
        echo "    Error: $error_msg"
        cd "$LARAVEL_PATH" && php artisan system:package status-by-id \
            --id="$pkg_id" --status=failed --message="$error_msg" 2>/dev/null || true
    fi
done

echo "System package installation complete"
