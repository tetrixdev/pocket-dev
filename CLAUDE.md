# CLAUDE.md

PocketDev is a **Docker-based Laravel development environment** with integrated Claude Code AI capabilities.

## Documentation

**Read `docs/README.md` first** - it has TLDR navigation to help you find relevant sections quickly. The docs/ folder is the single source of truth for architecture, implementation details, and design decisions.

### Quick Topic Lookup

| Looking for... | Go to |
|----------------|-------|
| Database schema, conversation statuses | `docs/database/schema.md` |
| How SSE streaming works | `docs/modules/chat/streaming.md` |
| Claude CLI integration | `docs/integrations/claude-cli.md` |
| Container architecture | `docs/architecture/system-overview.md` |
| Auth (Basic Auth, API keys) | `docs/architecture/authentication.md` |
| Reasoning/thinking settings | `docs/architecture/provider-specific-reasoning-settings.md` |
| Config files (.env, ai.php) | `docs/configuration/README.md` |

## Quick Reference

### Container Command Prefix
```bash
docker compose exec pocket-dev-php <command>

# Examples:
docker compose exec pocket-dev-php php artisan migrate
docker compose exec pocket-dev-php composer install
```

### Hard Reset (Docker rebuild)
Use when changing Dockerfiles, entrypoints, or files in `docker-*/shared/defaults/`:
```bash
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker stop && \
docker ps -a --filter volume=pocket-dev-workspace --format "{{.Names}}" | xargs -r docker rm && \
docker compose down -v && docker compose up -d --build
```

### Frontend Rebuild (Vite)
Use after changing JS, CSS, or Alpine.js directives in Blade templates:
```bash
docker compose up -d --force-recreate
```

### Docker Self-Management (for AI/Dogfooding)
The queue container has full dogfooding capabilities:
- **`/pocketdev-source`** - Full PocketDev project (read/write) for editing code, git operations
- **Docker socket** - Can run docker commands (read-only operations like `docker ps`, `docker logs`)
- **Git/GitHub CLI** - Configured with credentials from environment

```bash
# Work on PocketDev source
cd /pocketdev-source
git status
git diff

# View container status and logs (safe, read-only)
docker ps --filter "name=pocket-dev"
docker logs pocket-dev-php --tail 50
```

**⚠️ Container restarts**: See "Critical Pitfalls" #4 - the AI must NEVER restart containers. If code changes require a restart, ask the user to do it via the Developer tab or CLI.

## Critical Pitfalls

1. **File permissions**: PHP runs as `www-data`. Files in `/var/www/.claude/` must be owned by `www-data:www-data`.

2. **Route order matters**: Specific routes (like `/claude/auth`) must come BEFORE wildcard routes (`/claude/{sessionId?}`).

3. **Claude CLI flags**: Use `--print --output-format json`, NOT `--json`.

4. **NEVER restart containers directly**: The AI must NEVER restart any PocketDev containers (`docker restart`, `docker compose restart`, etc.). Restarting the queue container mid-conversation causes the conversation to enter a limbo state. If a container restart is needed (e.g., to pick up code changes), **ask the user** to restart it via the Developer tab or manually. The user should always be in control of container lifecycle.

## PHP Style Preferences

- Use public properties for simple values (booleans, strings, arrays) instead of getter methods
- Reserve methods for computed values, logic, or actions (like `execute()`)

## Blade Components

Use Laravel anonymous components (`<x-*>`) instead of partials/includes for reusable UI elements.

### Available Components

| Component | Usage | Variants/Props |
|-----------|-------|----------------|
| `<x-modal>` | Modal dialogs | `show`, `title`, `max-width` (sm/md/lg/xl/2xl) |
| `<x-button>` | All buttons | `variant` (primary/secondary/success/danger/purple/ghost), `size` (sm/md/lg), `full-width`, `type` |
| `<x-text-input>` | Text inputs | `type`, `label`, `hint` |
| `<x-multi-select>` | Multi-select dropdown | `options`, `selected`, `label`, `placeholder`, `on-change` |
| `<x-chat.*>` | Chat messages | `user-message`, `assistant-message`, `thinking-block`, `tool-block`, `empty-response`, `cost-badge` |
| `<x-icon.*>` | SVG icons | `lightbulb`, `cog`, `chevron-down`, `info`, `menu`, `x`, `chevron-left` |

### Examples

```blade
{{-- Modal --}}
<x-modal show="showMyModal" title="My Modal" max-width="lg">
    <p>Modal content here</p>
    <x-button variant="primary" @click="showMyModal = false">Close</x-button>
</x-modal>

{{-- Button variants --}}
<x-button variant="primary" type="submit">Save</x-button>
<x-button variant="secondary">Cancel</x-button>
<x-button variant="danger" onclick="return confirm('Sure?')">Delete</x-button>

{{-- Text input with label --}}
<x-text-input type="password" label="API Key" x-model="apiKey" placeholder="sk-..." />

{{-- Chat messages (inside Alpine x-for loop) --}}
<x-chat.user-message variant="desktop" />
<x-chat.assistant-message variant="mobile" />

{{-- Multi-select (uses Alpine variables from parent scope) --}}
<x-multi-select
    options="availableOptions"
    selected="selectedItems"
    label="Select Items"
    placeholder="Click to select..."
    on-change="saveSettings"
/>
```

### Component Location

Components live in `resources/views/components/`:
- `modal.blade.php` - Reusable modal wrapper
- `button.blade.php` - Button with variants
- `text-input.blade.php` - Styled text input
- `multi-select.blade.php` - Multi-select dropdown with tags
- `chat/` - Chat message components
- `icon/` - SVG icon components

### Guidelines

1. **Use components for reusable UI** - Prefer `<x-button>` over inline button HTML
2. **Pass Alpine.js state via show prop** - Modals use `show="alpineVariable"` for visibility
3. **Chat components access parent scope** - They expect `msg` variable from x-for loop
4. **Add new icons to `components/icon/`** - Keep SVGs as components for consistency

## Debug Logger

A global debug logging system for tracing frontend behavior. Logs to both browser console and a viewable panel.

### Usage

```javascript
// Global helper (works anywhere after Alpine initializes)
debugLog('message', { optional: 'data' });

// From Alpine component
$store.debug.log('message', data);
```

### Opening the Panel

- **Mobile**: Menu drawer → "Debug Log" button
- **Any page**: `<button @click="$store.debug.toggle()">Debug</button>`

### Adding to a Page

```blade
{{-- Basic usage --}}
<x-debug-panel />

{{-- With page-specific state display --}}
<x-debug-panel>
    <x-slot:stateDisplay>
        <div class="px-4 py-2 font-mono text-xs">
            myVar=<span x-text="myVar"></span>
        </div>
    </x-slot:stateDisplay>
</x-debug-panel>
```

### When Claude Uses Debug Logging

If Claude adds debug logging to investigate an issue, they will ask you to:
1. Open the debug panel
2. Reproduce the issue
3. Tap "Copy" and paste the logs back to Claude

## Git Workflow

- **Never commit directly to main** - always use feature branches and pull requests
- Create feature branches for changes
- **Do not create PRs automatically** - only when explicitly requested
- Never commit `.env` or credential files
- **Use regular merge commits** (`gh pr merge --merge`), not squash merges
