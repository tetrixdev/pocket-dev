# Authentication

PocketDev has two authentication layers:

1. **Network-level Security** - Tailscale private network (recommended) or Basic Auth
2. **Claude Authentication** - OAuth credentials for Claude CLI

> **Note**: Basic Auth is currently disabled in the proxy. For production deployments, use Tailscale for security. See `deployment/secure-server-setup.md`.

---

## Tailscale (Recommended)

For production deployments, use Tailscale to create a private network. This makes PocketDev invisible to the public internet.

**How it works:**
- Install Tailscale on server and client devices
- Server firewall blocks all public access
- Only Tailscale-connected devices can reach PocketDev
- No passwords or API keys exposed

**Setup:** See `deployment/secure-server-setup.md`

---

## Proxy Authentication (Basic Auth + IP Whitelist)

On server deployments, authentication is handled by **proxy-nginx** (an external reverse proxy), not a PocketDev container.

### How It Works

The proxy-nginx service provides:
- HTTP Basic Auth for all requests
- IP whitelist support (e.g., Tailscale network only)
- SSL/TLS termination

**Configuration via proxy-nginx:**
```bash
# Add domain with Tailscale restriction
docker exec proxy-nginx /scripts/domain.sh upsert \
  --domain=pocketdev.example.com \
  --upstream=pocket-dev-nginx \
  --whitelist="100.64.0.0/10"
```

### IP Whitelist Examples

| Use Case | Whitelist Value |
|----------|-----------------|
| Tailscale only | `100.64.0.0/10` |
| Specific IPs | `192.168.1.100,10.0.0.50` |
| No restriction | (omit whitelist) |

**Note:** For local development without proxy-nginx, PocketDev's nginx is exposed directly on the configured port.

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
[proxy-nginx] (server only)
    │ Basic Auth check
    │ IP whitelist check
    │ ✗ 401/403
    │ ✓
    ▼
pocket-dev-nginx
    │
    ▼
Laravel checks Claude credentials
    │ ✗ Redirect to /claude/auth
    │ ✓
    ▼
Chat interface loads
```

---

## Security Notes

1. **Network-level security is always required** - Use Tailscale for production, or enable Basic Auth for local access
2. **Credentials are sensitive** - Never commit `.credentials.json` or `.env` to git
3. **Token expiry** - Claude tokens expire; the auth status page shows days remaining
