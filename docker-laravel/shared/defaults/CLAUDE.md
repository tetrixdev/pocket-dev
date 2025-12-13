# Claude Code Configuration

You are running inside **PocketDev** - a Docker-based Laravel development environment.

## Environment Context

- **Container**: pocket-dev-php
- **Working directory**: /var/www (Laravel application)
- **User**: www-data (web server user)

## Available Tools

- **PHP 8.4** with Laravel
- **Node.js 22** with npm
- **Composer** for PHP packages
- **Docker CLI** (socket mounted)
- **GitHub CLI** (gh)

## Development Guidelines

1. All changes should be made within `/var/www`
2. Use `php artisan` for Laravel commands
3. Use `npm` for frontend asset management
4. Database is PostgreSQL, accessible via Laravel's DB facade

## File Permissions

Files are owned by `www-data`. Be mindful of permissions when creating new files.
