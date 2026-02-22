#!/bin/bash
#
# load-credentials.sh - Exports user-configured credentials as environment variables
#
# This script is called from both PHP and Queue container entrypoints.
# It requires:
#   - Database to be ready (migrations complete)
#   - Working directory /var/www OR LARAVEL_PATH set
#
# Output: Prints export statements to stdout (caller should eval)
# Status messages go to stderr so they don't interfere with export output
#
# Returns 0 on success (including when no credentials are configured)
# Returns 1 on failure (fail fast - container should not start without credentials)

LARAVEL_PATH="${LARAVEL_PATH:-/var/www}"

echo "Loading credentials into environment..." >&2

# Disable exit-on-error for credential export (we handle failures explicitly)
set +e
cred_raw=$(cd "$LARAVEL_PATH" && php artisan credential export 2>&1)
cred_exit_code=$?
set -e

if [ $cred_exit_code -ne 0 ]; then
    echo "  FATAL: Credential export failed (exit code $cred_exit_code)" >&2
    echo "  Output: $cred_raw" >&2
    echo "  Check database connectivity and migrations." >&2
    exit 1  # Fail fast - don't start container without credentials
fi

# Filter to only valid export lines (ignores any warnings/noise in output)
cred_exports=$(echo "$cred_raw" | grep '^export ' || true)

if [ -n "$cred_exports" ]; then
    # Output the export statements to stdout (caller should eval)
    echo "$cred_exports"
    cred_count=$(echo "$cred_exports" | grep -c '^export ' || true)
    echo "  Loaded $cred_count credential(s)" >&2
else
    echo "  No credentials configured" >&2
fi
