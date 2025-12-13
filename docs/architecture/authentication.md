# Authentication

PocketDev has two authentication layers:

1. **Proxy Authentication** - Basic Auth for accessing the application
2. **Claude Authentication** - OAuth credentials for Claude CLI

---

## Proxy Authentication (Basic Auth)

### How It Works

The nginx proxy requires HTTP Basic Auth for all requests (except `/health`).

**Configuration:**
- `BASIC_AUTH_USER` - Username (required)
- `BASIC_AUTH_PASS` - Password (required)

**Source:** `docker-proxy/shared/entrypoint.sh:21-43`

```bash
# Credentials stored via htpasswd
htpasswd -cb /etc/nginx/.htpasswd "$BASIC_AUTH_USER" "$BASIC_AUTH_PASS"
```

### IP Whitelist (Optional)

Additional security layer that restricts access to specific IPs.

**Configuration:**
- `IP_WHITELIST` - Comma-separated list of allowed IPs (optional)

**Example:**
```env
IP_WHITELIST=192.168.1.100,10.0.0.50
```

**Source:** `docker-proxy/shared/entrypoint.sh:46-71`

When enabled, nginx creates a map that checks `$remote_addr` against the whitelist.

---

## Claude Authentication

### Credential Structure

Claude CLI uses OAuth tokens stored in `.credentials.json`:

```json
{
  "claudeAiOauth": {
    "accessToken": "...",
    "refreshToken": "...",
    "expiresAt": 1234567890
  }
}
```

### Credential Location

| Path | User |
|------|------|
| `/var/www/.claude/.credentials.json` | www-data |

### Authentication Methods

#### 1. Web UI Upload

**Route:** `/claude/auth`

Users can:
- Upload `.credentials.json` file
- Paste JSON content directly

**Source:** `app/Http/Controllers/ClaudeAuthController.php`

The controller:
1. Validates credential structure
2. Writes to `/var/www/.claude/.credentials.json`
3. Sets ownership to `www-data:www-data`

#### 2. Docker Exec

Run authentication directly in PHP container:

```bash
docker exec -it pocket-dev-php claude setup-token
```

This opens a browser auth flow and writes credentials to `/var/www/.claude/`.

### Checking Authentication Status

**Web UI:**
- Visit `/claude/auth` to see status
- Shows: subscription type, expiry date, days until expiry, scopes

**API:**
```bash
curl -u admin:password http://localhost/claude/auth/status
```

**Source:** `app/Http/Controllers/ClaudeAuthController.php:23-58`

### Logout

**Web UI:** Click "Logout" on `/claude/auth`

**API:**
```bash
curl -u admin:password -X DELETE http://localhost/claude/auth/logout
```

This deletes `/var/www/.claude/.credentials.json`.

---

## Authentication Flow Diagram

```
User visits /
    │
    ▼
Proxy checks Basic Auth
    │ ✗ 401 Unauthorized
    │ ✓
    ▼
Proxy checks IP whitelist (if configured)
    │ ✗ 403 Forbidden
    │ ✓
    ▼
Laravel checks Claude credentials
    │ ✗ Redirect to /claude/auth
    │ ✓
    ▼
Chat interface loads
```

---

## Security Notes

1. **Basic Auth is always required** - There's no "development mode" without auth
2. **Credentials are sensitive** - Never commit `.credentials.json` or `.env` to git
3. **Token expiry** - Claude tokens expire; the auth status page shows days remaining
