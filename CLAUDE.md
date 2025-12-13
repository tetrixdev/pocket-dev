# CLAUDE.md

PocketDev is a **Docker-based Laravel development environment** with integrated Claude Code AI capabilities.

## Documentation

**Read `docs/README.md` first** - it has TLDR navigation to help you find relevant sections quickly. The docs/ folder is the single source of truth for architecture, implementation details, and design decisions.

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

## Critical Pitfalls

1. **Dual-container DOM pattern**: Chat interface has BOTH `#messages` (desktop) and `#messages-mobile` containers. JavaScript must update both.

2. **File permissions**: PHP runs as `www-data`. Files in `/var/www/.claude/` must be owned by `www-data:www-data`.

3. **Route order matters**: Specific routes (like `/claude/auth`) must come BEFORE wildcard routes (`/claude/{sessionId?}`).

4. **Claude CLI flags**: Use `--print --output-format json`, NOT `--json`.

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
```

### Component Location

Components live in `resources/views/components/`:
- `modal.blade.php` - Reusable modal wrapper
- `button.blade.php` - Button with variants
- `text-input.blade.php` - Styled text input
- `chat/` - Chat message components
- `icon/` - SVG icon components

### Guidelines

1. **Use components for reusable UI** - Prefer `<x-button>` over inline button HTML
2. **Pass Alpine.js state via show prop** - Modals use `show="alpineVariable"` for visibility
3. **Chat components access parent scope** - They expect `msg` variable from x-for loop
4. **Add new icons to `components/icon/`** - Keep SVGs as components for consistency

## Git Workflow

- Create feature branches for changes
- **Do not create PRs automatically** - only when explicitly requested
- Never commit `.env` or credential files
- **Use regular merge commits** (`gh pr merge --merge`), not squash merges
