# Routes Architecture

## API Routes (`api.php`) - Technical Debt

The 32 routes in `api.php` are **browser-only internal endpoints**, not a public API. They're accessed via `fetch()` from the Blade frontend.

### The Problem

API routes in Laravel skip CSRF protection (designed for stateless/token auth). Since these are same-origin browser requests using session cookies, they should be in `web.php` for CSRF protection.

### Why They're Here

No strong reason - likely just convention (`/api/` prefix for JSON endpoints). This is technical debt.

### Migration Path

Moving to `web.php` requires:
1. Move routes (can keep `/api/` prefix if desired)
2. Add CSRF token to all frontend `fetch()` calls
3. ~32 routes affected
4. **Verify functionality** - test thoroughly after migration

**Note:** Some routes might need to stay in `api.php` due to the container structure (PHP + queue containers), but this is uncertain. It's possible all can be migrated - needs investigation.

### Guidelines

1. **New browser-only endpoints go in `web.php`**
2. **When modifying existing API routes**, consider migrating them
3. **True external APIs** (if ever needed) should stay in `api.php` with proper token auth
