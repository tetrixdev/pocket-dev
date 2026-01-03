# Routes Architecture

## API Routes (`api.php`)

The routes in `api.php` are **browser-only internal endpoints**, not a public API. They're accessed via `fetch()` from the Blade frontend.

### Why This Matters

API routes in Laravel skip CSRF protection (designed for stateless/token auth). Since these are same-origin browser requests, they'd typically belong in `web.php` for CSRF protection.

### Current Decision

**Keep as-is** for now because:
- PocketDev is a local development tool with limited attack surface
- CSRF attacks require a malicious site targeting your local instance
- The `/api/` prefix convention is clean for JSON endpoints
- Refactoring 32 routes + all frontend fetch calls is non-trivial

### Guidelines

1. **Don't add new browser-only endpoints to `api.php`** - prefer `web.php` for new work
2. **Consider gradual migration** - when touching existing API routes, consider moving them
3. **True external APIs** (if ever needed) should stay in `api.php` with proper token auth

### Container Architecture

The PHP and queue containers share the same Laravel codebase. The queue container runs artisan commands and writes to the database directly - it doesn't make HTTP requests to the app. So the route file choice (`api.php` vs `web.php`) doesn't affect container communication.
