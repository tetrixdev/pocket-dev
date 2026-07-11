# Credentials & System Packages Implementation Plan

> **Status:** ✅ Complete
> **Created:** 2025-01-05
> **Completed:** 2025-01-05
> **Context:** Flexible credential management and system package installation for PocketDev

## Executive Summary

PocketDev needs a flexible way to manage:
1. **Credentials** - API keys/tokens for external services (Hetzner, GitHub, AWS, etc.)
2. **System Packages** - Linux applications needed by tools or direct CLI usage

The goal is to enable users to add arbitrary credentials and packages, making them available to the AI without hardcoding anything.

---

## Background & Decision Log

### Why Not Just Use .env?

The current GitHub token only works when set in `.env`, not through the UI. This is inflexible because:
- Users can't add arbitrary credentials without editing files
- No encryption at rest
- No UI for management

### Why Credentials Separate from Tools?

Initial thought was to link credentials to specific tools. However:
- Many CLIs (gh, hcloud, aws) read from environment variables directly
- If `HETZNER_TOKEN` is in the environment and `hcloud` is installed, you don't need a tool wrapper
- Credentials should be reusable across multiple tools and direct CLI usage

**Decision:** Credentials are standalone. They're injected as env vars into ALL Bash and tool execution.

### Why System Packages Separate from Tools?

Same reasoning:
- If `hcloud` CLI is installed and credentials are set, no tool needed
- Packages like `jq` are used by many tools - shouldn't duplicate the dependency
- AI can use CLIs directly if it knows what's installed

**Decision:** System packages are a global list, installed on container start and available everywhere.

### Security Considerations

We discussed several approaches for protecting credentials from AI access:

1. **Output filtering** - Scan tool output for credential values, redact them
2. **Credential proxy** - Inject references that get resolved at network layer
3. **OAuth short-lived tokens** - Generate temporary tokens

**Decision:** Skip all of these for Phase 1. Reasoning:
- Self-hosted tool, user is the admin
- User can already see credentials in UI
- AI would have to intentionally exfiltrate (unlikely)
- Complexity not worth it for current threat model

May revisit filtering in the future if needed.

### Name vs Slug for Credentials

Discussed whether credentials need both a `name` and `slug`.

**Decision:** Just `slug`. Display can derive a nice name from slug if needed (replace underscores with spaces, title case). One less field, less confusion.

### Env Var Naming

Should env vars be auto-derived from slug or user-specified?

**Decision:** User specifies `env_var` explicitly. Reasoning:
- CLIs expect specific names (`GH_TOKEN` vs `GITHUB_TOKEN`)
- User knows what their CLI expects
- More flexible

### System Packages Storage

Options considered:
1. Database table
2. JSON file in storage

**Decision:** JSON file (`storage/app/system-packages.json`). Reasoning:
- Entrypoint runs before Laravel boots
- No DB connection needed at container start
- Simple to read/write
- Can be version controlled if desired

### UI for System Packages

Concern: "User might mess up package names"

**Decision:** Yes to UI, with validation. When adding:
1. Attempt `apt-get install -y <package>`
2. If fails → show error, don't add to list
3. If succeeds → add to list

This ensures the list only contains packages that actually installed.

---

## Technical Design

### Credentials Table Schema

```sql
CREATE TABLE credentials (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    slug VARCHAR(255) UNIQUE NOT NULL,      -- e.g., 'github_token'
    env_var VARCHAR(255) NOT NULL,          -- e.g., 'GITHUB_TOKEN' or 'GH_TOKEN'
    encrypted_value TEXT NOT NULL,          -- Laravel encrypted
    description TEXT,                       -- Optional notes for user
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
```

### System Packages File

```json
// storage/app/system-packages.json
["gh", "jq", "hcloud", "pandoc", "poppler-utils"]
```

### Env Var Injection

When executing Bash commands or tool scripts, inject all credentials:

```php
$credentials = Credential::all();
$env = [];
foreach ($credentials as $cred) {
    $env[$cred->env_var] = decrypt($cred->encrypted_value);
}

Process::env($env)->run($command);
```

### System Prompt Addition

Dynamically inject into system prompt:

```markdown
## Environment

**Credentials:** GITHUB_TOKEN, HETZNER_TOKEN, AWS_ACCESS_KEY_ID
**Packages:** gh, jq, hcloud, pandoc, poppler-utils
```

This is lightweight (~2 lines) but gives AI full context on what's available.

### Container Entrypoint Hook

Add to entrypoint script:

```bash
# Install user-configured system packages
if [ -f /var/www/storage/app/system-packages.json ]; then
    packages=$(cat /var/www/storage/app/system-packages.json | jq -r '.[]' 2>/dev/null | tr '\n' ' ')
    if [ -n "$packages" ]; then
        echo "Installing system packages: $packages"
        apt-get update -qq && apt-get install -y -qq $packages
    fi
fi
```

---

## Implementation Steps

### Step 1: Credentials Migration & Model

Create the database migration and Eloquent model for credentials.

**Files to create:**
- `database/migrations/xxxx_create_credentials_table.php`
- `app/Models/Credential.php`

**Details:**
- UUID primary key
- Slug unique index
- Use Laravel's `encrypt()`/`decrypt()` for the value
- Model should cast `encrypted_value` appropriately

---

### Step 2: Credentials Artisan Commands

Create artisan commands for managing credentials (useful for AI and CLI users).

**Commands:**
- `credential:list` - List all credentials (values hidden)
- `credential:add` - Add a new credential
- `credential:remove` - Remove a credential
- `credential:show` - Show a single credential's details (value hidden)

**Files to create:**
- `app/Console/Commands/CredentialList.php`
- `app/Console/Commands/CredentialAdd.php`
- `app/Console/Commands/CredentialRemove.php`
- `app/Console/Commands/CredentialShow.php`

---

### Step 3: System Packages Artisan Commands

Create artisan commands for managing system packages.

**Commands:**
- `system:package list` - List installed packages
- `system:package add <name>` - Add and install a package
- `system:package remove <name>` - Remove from list (doesn't uninstall)

**Files to create:**
- `app/Console/Commands/SystemPackageList.php`
- `app/Console/Commands/SystemPackageAdd.php`
- `app/Console/Commands/SystemPackageRemove.php`

**Behavior:**
- `add` runs `apt-get install -y` immediately, only adds to JSON if successful
- `remove` just removes from JSON (uninstalling is complex, skip for now)
- JSON file created automatically if doesn't exist

---

### Step 4: Credential Injection into Bash/Tool Execution

Modify the Bash tool and custom tool execution to inject credentials as environment variables.

**Files to modify:**
- Find where Bash commands are executed (likely in a service or controller)
- Find where custom tools are executed (likely `ToolRunCommand.php` or similar)

**Implementation:**
- Load all credentials from DB
- Decrypt values
- Pass as environment variables to the Process

---

### Step 5: Container Entrypoint Hook

Modify the container entrypoint to install system packages on startup.

**Files to modify:**
- `docker-php/shared/defaults/entrypoint.sh` (or similar)
- Possibly other container entrypoints if queue worker is separate

**Implementation:**
- Check if system-packages.json exists
- Parse JSON, install packages via apt-get
- Handle errors gracefully (don't fail container start)

---

### Step 6: Credentials UI (Backend)

Create the controller and routes for credentials management.

**Files to create:**
- `app/Http/Controllers/CredentialController.php`

**Routes:**
- `GET /credentials` - List page
- `POST /credentials` - Create new
- `PUT /credentials/{id}` - Update
- `DELETE /credentials/{id}` - Delete

---

### Step 7: Credentials UI (Frontend)

Create the Blade view for credentials management.

**Files to create:**
- `resources/views/credentials/index.blade.php`

**Features:**
- Table showing: Env Var, Description, Actions
- Values hidden (show `••••••••`)
- Add modal: slug, env_var, value, description
- Edit modal: same fields, value shows placeholder unless changed
- Delete confirmation

---

### Step 8: System Packages UI (Backend)

Create the controller and routes for system packages management.

**Files to create:**
- `app/Http/Controllers/SystemPackageController.php`

**Routes:**
- `GET /system-packages` - List page (or could be part of credentials page)
- `POST /system-packages` - Add package
- `DELETE /system-packages/{name}` - Remove package

**Behavior:**
- POST runs apt-get install, returns success/error
- Frontend should show loading state during install

---

### Step 9: System Packages UI (Frontend)

Create the Blade view for system packages management.

**Files to create:**
- `resources/views/system-packages/index.blade.php`

**Features:**
- List of packages with delete button
- Input + Add button
- Loading state during install
- Error display if install fails

---

### Step 10: Rename Providers Page

Rename the current "Credentials" page to "Providers" since it's specifically for AI provider API keys.

**Files to modify:**
- Navigation/sidebar
- Page title
- Route names (if needed)

---

### Step 11: System Prompt Integration

Add credentials and packages lists to the system prompt dynamically.

**Files to modify:**
- Wherever the system prompt is built (likely a service or the Claude CLI integration)

**Implementation:**
- Query credentials, extract env_var names
- Read system-packages.json
- Inject as a small section in the system prompt

---

### Step 12: Documentation & Cleanup

Update documentation and clean up any loose ends.

**Tasks:**
- Update docs/configuration/README.md or create new doc
- Add to CLAUDE.md if needed for AI context
- Test full flow end-to-end

---

## UI Navigation Structure

After implementation:

```
Sidebar:
├── Chat
├── Conversations
├── Providers (renamed from Credentials) - AI provider API keys
├── Credentials (new) - Custom env vars for tools/CLIs
├── System Packages (new) - Installed Linux packages
├── Tools
└── Settings
```

Alternatively, Credentials and System Packages could be tabs on a single "Environment" page. Decide during implementation based on what feels right.

---

## Testing Checklist

- [ ] Can create a credential via UI
- [ ] Credential value is encrypted in database
- [ ] Credential is available as env var in Bash tool
- [ ] Credential is available as env var in custom tools
- [ ] Can add system package via UI, it installs immediately
- [ ] Failed package install shows error, doesn't add to list
- [ ] Packages install on container restart
- [ ] System prompt shows available credentials and packages
- [ ] AI can use `gh` CLI directly when GITHUB_TOKEN is set
- [ ] AI can use `hcloud` CLI directly when HETZNER_TOKEN is set and hcloud is installed

---

## Future Considerations (Out of Scope)

- Output filtering to prevent credential leakage
- Tool-specific credential requirements with validation
- OAuth flows for services that support it
- Package version pinning
- Package groups/profiles (e.g., "data-science" installs pandas, numpy, etc.)
