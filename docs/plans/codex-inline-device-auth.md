# Plan: Codex Inline Device Auth

## Probleem

De huidige Codex authenticatie flows werken niet goed op een VPS:

| Flow | Probleem |
|------|---------|
| Wizard | Vereist `sudo` + browser op host machine |
| Settings (device-auth) | Vereist terminal toegang + interactieve TTY |

## Oplossing

Device auth flow volledig ingebouwd in de PocketDev UI. Gebruiker hoeft
alleen een link te openen op een ander apparaat (telefoon/laptop).

### User flow

1. Gebruiker klikt **"Login met ChatGPT"** in PocketDev UI
2. PocketDev start `codex login --device-auth` als background process in de queue container
3. PocketDev parseert stdout/stderr → toont **URL + code groot in de UI**
4. Gebruiker opent `https://auth.openai.com/codex/device` op telefoon/laptop
5. Voert de code in → logt in met ChatGPT account
6. PocketDev pollt `~/.codex/auth.json` → detecteert success → toont ✅

Geen sudo, geen terminal, geen docker commando's.

---

## Implementatie

### 1. Backend: `CodexAuthController`

**Nieuwe endpoints:**

#### `POST /codex/auth/device-start`
- Start `codex login --device-auth` als background process in de queue container via `docker exec`
- Parseert output voor URL en user code
- Slaat process PID op in cache (voor cleanup)
- Returnt: `{ verification_url, user_code, expires_in: 900 }`

```php
public function startDeviceAuth(): JsonResponse
{
    // Run in queue container where codex is installed
    $process = Process::start([
        'docker', 'exec', '-u', 'appuser',
        config('pocketdev.queue_container', 'pocket-dev-queue'),
        'codex', 'login', '--device-auth'
    ]);

    // Parse URL and code from stderr output
    // Output format:
    //   1. Open: https://auth.openai.com/codex/device
    //   2. Enter code: CDB9-2OZPE
    ...
}
```

#### `GET /codex/auth/device-status`
- Pollt of `~/.codex/auth.json` aangemaakt is in de queue container
- Returnt: `{ authenticated: bool, auth_type: string }`

---

### 2. Frontend: `codex-auth.blade.php`

**Nieuwe "Subscription" tab UI:**

```text
┌─────────────────────────────────────────┐
│  Login met ChatGPT Subscription         │
│                                         │
│  [Start Login]                          │
│                                         │
│  ── na klik ──                          │
│                                         │
│  1. Open deze link op je telefoon       │
│     of laptop:                          │
│                                         │
│  https://auth.openai.com/codex/device   │
│  [📋 Kopieer link]                      │
│                                         │
│  2. Voer deze code in:                  │
│                                         │
│  ┌──────────────┐                       │
│  │  CDB9-2OZPE  │  [📋 Kopieer]        │
│  └──────────────┘                       │
│                                         │
│  ⏳ Wachten op authenticatie...         │
│     (verloopt over 14:32)               │
│                                         │
└─────────────────────────────────────────┘
```

**Copy knoppen:**
- Kopieer URL → `navigator.clipboard.writeText(url)`
- Kopieer code → `navigator.clipboard.writeText(code)`
- Beide tonen ✓ bevestiging na kopiëren

**Polling:**
- Frontend pollt `GET /codex/auth/device-status` elke 3 seconden
- Bij success: toont ✅ "Authenticated!" + herlaadt status

**Countdown timer:**
- 15 minuten aftellen via `setInterval`
- Bij 0: toont melding "Code verlopen, probeer opnieuw"

---

### 3. Wizard: `setup/wizard.blade.php`

Zelfde inline device auth component hergebruiken in de wizard stap voor Codex.

---

## Bestanden om aan te passen

| Bestand | Wijziging |
|---------|-----------|
| `CodexAuthController.php` | `startDeviceAuth()` + `deviceStatus()` endpoints |
| `routes/web.php` | `POST /codex/auth/device-start` + `GET /codex/auth/device-status` |
| `codex-auth.blade.php` | Nieuwe subscription tab UI met copy knoppen + polling |
| `setup/wizard.blade.php` | Inline device auth component |
| `EnsureSetupComplete.php` | Nieuwe routes whitelisten |

---

## Open vragen

- [ ] Hoe parsen we de URL + code uit `codex login --device-auth` output?
  - Output gaat naar stderr met ANSI color codes
  - Regex: `https://auth\.openai\.com/codex/device` + `[A-Z0-9]{4}-[A-Z0-9]{5}`
- [ ] Timeout afhandeling: process killen als 15 min verstreken zijn
- [ ] Wat als Docker niet beschikbaar is (non-Docker deployment)?
  - Fallback: run `codex login --device-auth` direct (niet via docker exec)
