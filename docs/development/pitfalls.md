# Common Pitfalls

Critical mistakes to avoid when developing PocketDev.

## 1. Route Order Matters

**Problem**: Auth routes not matching.

**Cause**: Laravel matches routes in order. Wildcard routes like `/claude/{sessionId?}` will capture `/claude/auth`.

**Solution**: Define specific routes BEFORE wildcard routes.

```php
// CORRECT
Route::get('/claude/auth', ...);          // Specific first
Route::get('/claude/{sessionId?}', ...);  // Wildcard last

// WRONG
Route::get('/claude/{sessionId?}', ...);  // This captures everything!
Route::get('/claude/auth', ...);          // Never reached
```

**Reference**: `routes/web.php`

---

## 2. Credentials Are Container-Specific

**Problem**: Authenticated in terminal but API returns "not authenticated".

**Cause**: TTYD and PHP containers have separate credential files.

| Container | Credential Path | User |
|-----------|-----------------|------|
| TTYD | `/home/devuser/.claude/.credentials.json` | devuser |
| PHP | `/var/www/.claude/.credentials.json` | www-data |

**Solution**: Copy credentials between containers:

```bash
docker cp pocket-dev-ttyd:/home/devuser/.claude pocket-dev-php:/var/www/.claude
docker exec pocket-dev-php chown -R www-data:www-data /var/www/.claude
```

---

## 3. Dual-Container DOM Updates

**Problem**: Messages appear on desktop but not mobile (or vice versa).

**Cause**: Only updating one DOM container.

**Solution**: Always update BOTH containers:

```javascript
// CORRECT
const containers = ['messages', 'messages-mobile'];
containers.forEach(containerId => {
    const container = document.getElementById(containerId);
    if (container) {
        container.innerHTML += html;
    }
});

// WRONG
document.getElementById('messages').innerHTML += html;
```

**Reference**: `chat.blade.php`, `addMsg()` and `updateMsg()` functions

---

## 4. Microphone Requires Secure Context

**Problem**: Voice recording doesn't work on mobile device via IP address.

**Cause**: Browser `getUserMedia()` API requires HTTPS or `localhost`.

**Valid**:
- `http://localhost` ✓
- `https://example.com` ✓

**Invalid**:
- `http://192.168.1.175` ✗
- `http://example.com` ✗

**Solution**: Access via `localhost` for voice, or set up HTTPS.

---

## 5. Claude CLI Flags Changed in v2.0+

**Problem**: Claude CLI returns errors about unknown flags.

**Cause**: Using deprecated flags.

| Old (v1.x) | New (v2.0+) |
|------------|-------------|
| `--json` | `--output-format json` |
| `-j` | `--output-format json` |

**Solution**: Use `--print --output-format json` for synchronous, `--output-format stream-json` for streaming.

---

## 6. File Permissions (www-data)

**Problem**: PHP can't read/write files.

**Cause**: Files owned by host user, not www-data.

**Solution**: Fix ownership:

```bash
docker exec pocket-dev-php chown -R www-data:www-data /var/www/.claude
docker exec pocket-dev-php chmod -R 775 /var/www/storage
```

---

## 7. Volume Persistence Blocks Removal

**Problem**: `docker compose down -v` hangs or fails.

**Cause**: User containers created inside TTYD are using the workspace volume.

**Solution**: Stop user containers first:

```bash
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm
docker compose down -v
```

---

## 8. Response Structure Changed

**Problem**: Code expects response directly, gets nested object.

**Cause**: Claude returns wrapped response.

**Claude Response Format**:
```json
{
  "type": "result",
  "subtype": "success",
  "is_error": false,
  "result": "[actual message here]",
  "cost_usd": 0.001,
  "usage": {...}
}
```

**Solution**: Extract the `result` field, not the whole response.

---

## 9. Scroll Behavior Differs by Platform

**Problem**: Auto-scroll works on desktop but not mobile.

**Cause**: Different scroll mechanisms.

| Platform | Method |
|----------|--------|
| Desktop | `container.scrollTop = container.scrollHeight` |
| Mobile | `window.scrollTo(0, document.body.scrollHeight)` |

**Solution**: Handle both:

```javascript
// Desktop
const container = document.getElementById('messages');
if (container) container.scrollTop = container.scrollHeight;

// Mobile
const mobileContainer = document.getElementById('messages-mobile');
if (mobileContainer) window.scrollTo(0, document.body.scrollHeight);
```

---

## 10. Vite Dev Server Single Domain

**Problem**: Assets load on localhost but not on IP address.

**Cause**: Vite dev server binds to one domain. CORS prevents cross-origin loading.

**Workaround**: Don't use `npm run dev`. Use `npm run build` via container restart:

```bash
docker compose up -d --force-recreate
```

**Why**: Desktop uses `localhost` (required for mic), mobile uses IP. Can't serve both with Vite dev server.

---

## 11. Slash Commands + Thinking Mode Incompatibility

**Problem**: Slash commands work once, then session breaks.

**Cause**: Claude CLI creates synthetic "No response requested." messages without thinking blocks. API rejects these when thinking mode is enabled.

**Status**: Known issue. See `SLASH_COMMAND_ISSUE.md` for details.

**Workaround**: Start new session after running slash commands with thinking mode enabled.

---

## 12. SSE Buffering

**Problem**: Streaming responses arrive in chunks, not real-time.

**Cause**: Nginx or PHP buffering SSE events.

**Solution**: Ensure buffering is disabled:

```nginx
# In proxy location block
proxy_buffering off;

# In PHP nginx config
fastcgi_buffering off;
```

**Reference**: `docker-proxy/shared/nginx.conf.template`, `docker-laravel/shared/nginx/default.conf`

---

## 13. Environment Variable Not Taking Effect

**Problem**: Changed .env but behavior didn't change.

**Cause**: Laravel caches configuration.

**Solution**: Clear caches:

```bash
docker compose exec pocket-dev-php php artisan optimize:clear
docker compose exec pocket-dev-php php artisan config:cache
```

---

## 14. Git Credentials Not Working

**Problem**: Git operations fail with auth errors.

**Cause**: GIT_TOKEN not set or entrypoint didn't run.

**Solution**:

1. Verify .env has `GIT_TOKEN`, `GIT_USER_NAME`, `GIT_USER_EMAIL`
2. Restart container to re-run entrypoint:
   ```bash
   docker compose restart pocket-dev-php
   ```
3. Check credentials were set:
   ```bash
   docker exec pocket-dev-php cat ~/.git-credentials
   ```
