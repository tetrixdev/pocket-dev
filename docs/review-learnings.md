# Review Learnings

Decisions made during code reviews that should not be re-flagged. Each entry documents a finding that was evaluated and intentionally accepted.

---

## BL-009/SEC-004 -- Password hash stored in session during multi-step flows
- **Scope:** `www/app/Http/Controllers/Auth/*`
- **Finding:** During the add-password flow, the user's new password hash is stored in the session (session key `add_password.password`). The hash remains until the user completes or abandons the flow.
- **Resolution:** Tracked in #305. Bcrypt hashes in session are low risk since the session is already authenticated and the hash provides no practical advantage over other data available in the session. Will be addressed as part of the session-data-hygiene follow-up.

## SEC-005 -- TOTP secrets stored in plaintext in session during enrollment flows
- **Scope:** `www/app/Http/Controllers/Auth/*`
- **Finding:** During the reset-TOTP flow, the new TOTP secret is stored in plaintext in the session (`reset_totp.new_secret`). If the session store is compromised, the TOTP secret is exposed before enrollment completes.
- **Resolution:** The session is auth-gated and short-lived. Laravel encrypts cookies by default via the EncryptCookies middleware. Encrypting session values at the application layer would add complexity with minimal benefit since session data can be encrypted at the driver level. Tracked alongside #305.

## SEC-003 -- TOTP secret stored as plaintext in session during setup wizard
- **Scope:** `www/app/Http/Controllers/Auth/SetupController.php`
- **Finding:** During the first-run setup wizard, the TOTP secret is stored in plaintext in the session (`setup.totp_secret`).
- **Resolution:** The user account does not exist yet during the setup wizard, so the TOTP secret is for a not-yet-created account. An attacker who can read session storage already has sufficient access to compromise the system in other ways. Tracked alongside #305.

## SEC-014 -- TOCTOU race in setup wizard
- **Scope:** `www/app/Http/Controllers/Auth/SetupController.php`
- **Finding:** Two concurrent requests could both pass the `User::count() === 0` check before either creates a user.
- **Resolution:** The email unique constraint prevents duplicate users at the database level. For `skipAuth`, two concurrent requests produce the same idempotent end state. The first-run race is a known, documented limitation (#303) that is mitigated by performing setup before exposing the instance.

## ARCH-002 -- Large controller with 15+ methods
- **Scope:** `www/app/Http/Controllers/Auth/SecuritySettingsController.php`
- **Finding:** `SecuritySettingsController` handles 6 distinct flows in a single 474-line controller with 15+ public methods.
- **Resolution:** Tracked as a to-do in #311. The flows share session state patterns (`pending_2fa_commit`, step keys) that benefit from co-location. Will be refactored into Action classes when test coverage (#309) is in place to prevent regression. Not a permanent acceptable state -- this is a tracked improvement.

## ARCH-006 -- Session key strings used as literals rather than constants
- **Scope:** `www/app/Http/Controllers/Auth/*`
- **Finding:** Session keys like `add_password.password`, `reset_totp.verified`, etc. are used as string literals. Only `pending_2fa_commit` is centralized as a class constant.
- **Resolution:** Low risk since each key is used in only 2-3 methods within the same controller. Will be addressed naturally when #311 extracts Action classes (each action class would own its session keys as constants).

## SEC-006 -- No TOTP timestep replay prevention
- **Scope:** `www/app/Services/TotpEnrollmentService.php`
- **Finding:** The TOTP verification via Google2FA's `verifyKey` does not track previously used timestamps. A replayed code is accepted within the 30-second window.
- **Resolution:** Tracked in #304. Standard TOTP libraries do not track used timesteps by default. The attack requires real-time code interception AND usage within the same 30-second window. Rate limiting (5/min for login 2FA) provides additional defense.

## SEC-007 -- 2FA rate limiter falls back to IP, causing shared-IP collisions
- **Scope:** `www/app/Providers/FortifyServiceProvider.php`
- **Finding:** The two-factor authentication rate limiter uses `session('login.id')` as the primary key, falling back to the request IP address. In shared-IP environments, multiple users share the 5/min limit.
- **Resolution:** Tracked in #308. The fallback to IP only applies when `login.id` is not in the session. In normal flow, `login.id` is set after successful password auth, so the IP fallback is only hit by direct requests to the 2FA endpoint. The 1-minute rate limit window is short.

## BL-005 -- confirmRecovery redirects to chat.index causing double redirect
- **Scope:** `www/app/Http/Controllers/Auth/SetupController.php`
- **Finding:** After completing the setup wizard, the user is redirected to `chat.index`, then middleware redirects to `setup.provider`, creating a double-redirect chain.
- **Resolution:** The middleware catches the redirect and sends the user to `setup.provider` correctly. Redirecting to `chat.index` is intentional: it follows the same pattern as `skipAuth` and avoids coupling the auth setup wizard to the provider setup flow. If provider setup is already complete, the user lands directly on chat.

## BL-006 -- Generic 'Unauthenticated.' JSON response for all middleware rejection reasons
- **Scope:** `www/app/Http/Middleware/EnsureSetupComplete.php`
- **Finding:** The middleware returns the same `401 Unauthenticated.` JSON for three different situations: no users exist, setup incomplete, and not logged in.
- **Resolution:** The SPA frontend receives web page redirects for most flows (only AJAX calls get JSON 401s). The generic message matches Laravel's built-in authentication middleware behavior. Differentiating would add complexity for an edge case (session expiry during AJAX).

## EFF-001 -- User::count() runs on every non-public request
- **Scope:** `www/app/Http/Middleware/EnsureSetupComplete.php`
- **Finding:** `User::count()` executes on every web request that passes through the middleware.
- **Resolution:** A count query on a table with 0-1 rows is sub-millisecond. Caching would add complexity (cache invalidation when user is created/deleted) for negligible performance gain.

## ARCH-005 -- CSRF fetch wrapper duplicated for main page and panel iframe
- **Scope:** `www/resources/js/bootstrap.js`, `www/app/Http/Controllers/Api/PanelController.php`
- **Finding:** The CSRF fetch wrapper is implemented twice: once in `bootstrap.js` for the main page and once as inline JavaScript in `PanelController.php` for panel iframes.
- **Resolution:** The duplication is intentional. The main page version uses URL-based origin detection, while the panel version uses path-prefix detection because `srcdoc` iframes break `URL.origin`. Consolidation would require a build step or shared file serving mechanism that adds complexity for minimal benefit.

## SEC-002 -- Modal validation errors display in global error container
- **Scope:** `www/resources/views/auth/security.blade.php`
- **Finding:** When a user enters an incorrect password in a security settings modal (logout other sessions, regenerate recovery codes, disable auth) and submits, the page reloads with the modal closed. The validation error appears in the global error container at the top of the page, disconnected from the modal where the action was taken.
- **Resolution:** This is a common pattern in server-rendered modal forms. Fixing it would require either keeping the modal open on reload via a flash variable + Alpine.js state binding, or using AJAX form submission. Both add complexity for an edge case (incorrect password). The error message text is clear enough for the user to understand what happened.
