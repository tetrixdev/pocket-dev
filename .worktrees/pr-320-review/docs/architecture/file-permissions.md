# File Permissions Model

PocketDev runs multiple processes that need to share files. This document explains how file permissions work across these processes.

## The Problem

Two main processes write to shared locations (logs, storage, etc.):

| Process | User | Container | Role |
|---------|------|-----------|------|
| PHP-FPM | `www-data` | pocket-dev-php | Web requests |
| Queue Workers | `appuser` | pocket-dev-queue | Background jobs |

By default, files created by one user aren't writable by the other (umask `022` creates `644` files). This causes permission errors when:
- A web request creates a log file, then a queue job tries to append to it
- A queue job creates a cache file, then a web request tries to update it

## The Solution

Both users are members of `appgroup`. We use two mechanisms to ensure group-writable files:

### 1. Umask Configuration

Set `umask(0002)` so new files are created with `664` (group-writable) instead of `644`:

| Location | File | Purpose |
|----------|------|---------|
| PHP bootstrap | `www/bootstrap/app.php` | Applies to all PHP-FPM requests |
| Supervisor | `docker-laravel/shared/supervisor/queue-workers.conf` | Applies to queue worker processes |

**Without umask fix:**
```
-rw-r--r-- www-data appgroup  request-flow.log  # Queue can't write!
```

**With umask fix:**
```
-rw-rw-r-- www-data appgroup  request-flow.log  # Queue can write via group
```

### 2. Explicit Permission Setting

For extra reliability, critical code paths explicitly set permissions after creating files:

```php
// Create directory with group permissions
File::makeDirectory($path, 0775, true);
@chmod($path, 0775);
@chgrp($path, 'appgroup');

// Set file permissions after creation
if ($isNewFile) {
    @chmod($filepath, 0664);
    @chgrp($filepath, 'appgroup');
}
```

The `@` suppresses errors if the operation fails (e.g., on a read-only filesystem).

## Defensive Error Handling

Logging and other infrastructure code must **never** cause application failures. The `RequestFlowLogger` wraps all file operations in try-catch and falls back to Laravel's error log:

```php
try {
    // File operations...
} catch (\Throwable $e) {
    // Fall back to Laravel log - never throw
    \Log::warning('RequestFlowLogger: Failed to write entry', [...]);
}
```

This prevents permission issues from causing conversations to get stuck in "processing" state.

## Directory Permissions Reference

| Directory | Permissions | Owner | Purpose |
|-----------|-------------|-------|---------|
| `storage/logs/` | `775` | www-data:appgroup | Laravel logs |
| `storage/logs/request-flow/` | `775` | www-data:appgroup | Request flow logs |
| `storage/framework/cache/` | `775` | www-data:appgroup | File cache |

## Troubleshooting

### Permission denied errors in logs

Check if files have correct group permissions:
```bash
ls -la storage/logs/
# Should show: -rw-rw-r-- www-data appgroup
```

Fix existing files:
```bash
chgrp -R appgroup storage/
chmod -R g+w storage/
```

### New files not group-writable

Verify umask is set correctly:
```bash
# In PHP container
php -r "echo 'umask: ' . sprintf('%04o', umask()) . PHP_EOL;"
# Should output: umask: 0002
```

### Queue jobs failing on file writes

1. Check supervisor config has `umask=002`
2. Restart supervisor: `supervisorctl restart all`
3. Check the queue container logs for permission errors
