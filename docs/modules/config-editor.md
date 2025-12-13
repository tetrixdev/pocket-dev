# Config Editor Module

Administrative interface for editing Claude configuration files.

## Overview

**Controller:** `app/Http/Controllers/ConfigController.php`

**Layout:** `resources/views/layouts/config.blade.php`

**Routes prefix:** `/config/*`

## Configuration Categories

| Category | Files | Storage Location |
|----------|-------|------------------|
| Files | CLAUDE.md, settings.json, nginx.conf | Various (see below) |
| Agents | `*.md` files | `/home/appuser/.claude/agents/` |
| Commands | `*.md` files | `/home/appuser/.claude/commands/` |
| Hooks | JSON in settings.json | `/home/appuser/.claude/settings.json` |
| Skills | `*.md` files | `/home/appuser/.claude/skills/` |

## File Paths

| File | Container Path | Editable Via |
|------|----------------|--------------|
| CLAUDE.md | `/home/appuser/.claude/CLAUDE.md` | Text editor |
| settings.json | `/home/appuser/.claude/settings.json` | Text editor (JSON) |
| nginx.conf | `/etc/nginx-proxy-config/nginx.conf.template` | Text editor |

**Note:** All paths are in PHP container context (`/home/appuser/`).

## Routes

```
GET  /config                        → Redirects to /config/claude
GET  /config/claude                 → Edit CLAUDE.md
POST /config/claude                 → Save CLAUDE.md
GET  /config/settings               → Edit settings.json
POST /config/settings               → Save settings.json
GET  /config/nginx                  → Edit nginx.conf
POST /config/nginx                  → Save nginx.conf

GET  /config/agents                 → List agents
GET  /config/agents/create          → Create agent form
POST /config/agents                 → Store agent
GET  /config/agents/{filename}/edit → Edit agent form
PUT  /config/agents/{filename}      → Update agent
DELETE /config/agents/{filename}    → Delete agent

GET  /config/commands               → List commands
GET  /config/commands/create        → Create command form
POST /config/commands               → Store command
GET  /config/commands/{filename}/edit → Edit command form
PUT  /config/commands/{filename}    → Update command
DELETE /config/commands/{filename}  → Delete command

GET  /config/hooks                  → Edit hooks (JSON in settings.json)
POST /config/hooks                  → Save hooks

GET  /config/skills                 → List skills
GET  /config/skills/create          → Create skill form
POST /config/skills                 → Store skill
GET  /config/skills/{name}/edit     → Edit skill form
PUT  /config/skills/{filename}      → Update skill
DELETE /config/skills/{filename}    → Delete skill
```

## Controller Methods

### File Editing (CLAUDE.md, settings.json, nginx.conf)

```php
public function claude()
{
    $path = '/home/appuser/.claude/CLAUDE.md';
    $content = file_exists($path) ? file_get_contents($path) : '';
    return view('config.claude', compact('content'));
}

public function saveClaude(Request $request)
{
    $validated = $request->validate(['content' => 'required|string']);
    file_put_contents('/home/appuser/.claude/CLAUDE.md', $validated['content']);
    return redirect()->back()->with('success', 'CLAUDE.md saved');
}
```

### Agents/Commands/Skills CRUD

Standard Laravel resource pattern:

```php
public function agents()
{
    $agents = $this->scanDirectory('/home/appuser/.claude/agents', '*.md');
    return view('config.agents.index', compact('agents'));
}

public function storeAgent(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string|regex:/^[a-z0-9-]+$/',
        'content' => 'required|string',
    ]);

    $filename = $validated['name'] . '.md';
    $path = '/home/appuser/.claude/agents/' . $filename;

    if (file_exists($path)) {
        return redirect()->back()->withErrors(['name' => 'Agent already exists']);
    }

    file_put_contents($path, $validated['content']);
    return redirect()->route('config.agents')->with('success', 'Agent created');
}
```

### Hooks Editing

Hooks are stored in `settings.json` under the `hooks` key:

```php
public function hooks()
{
    $settings = $this->loadSettings();
    $hooks = $settings['hooks'] ?? [];
    return view('config.hooks', compact('hooks'));
}

public function saveHooks(Request $request)
{
    $validated = $request->validate(['hooks' => 'required|json']);

    $settings = $this->loadSettings();
    $settings['hooks'] = json_decode($validated['hooks'], true);
    $this->saveSettings($settings);

    return redirect()->back()->with('success', 'Hooks saved');
}
```

## View Structure

### Layout (`layouts/config.blade.php`)

Dual-layout pattern matching chat interface:
- Desktop: Fixed sidebar + scrollable content
- Mobile: Hamburger menu + sliding drawer

Uses Alpine.js for drawer state: `x-data="{ showMobileDrawer: false }"`

### Sidebar Composer

**File:** `app/Http/View/Composers/ConfigSidebarComposer.php`

Provides sidebar data to all config views:

```php
public function compose(View $view)
{
    $view->with([
        'agents' => $this->scanDirectory('/home/appuser/.claude/agents', '*.md'),
        'commands' => $this->scanDirectory('/home/appuser/.claude/commands', '*.md'),
        'skills' => $this->scanDirectory('/home/appuser/.claude/skills', '*.md'),
    ]);
}
```

Registered in `AppServiceProvider`:

```php
View::composer('layouts.config', ConfigSidebarComposer::class);
```

### Form Views

Each category has:
- `index.blade.php` - List view
- `form.blade.php` - Create/Edit form (shared)

Forms extend `layouts.config` and use `@section('content')`.

## Validation Rules

### Agent/Command Names

```php
'name' => 'required|string|regex:/^[a-z0-9-]+$/'
```

Lowercase letters, numbers, and hyphens only.

### Skill Fields

```php
'name' => 'required|string|regex:/^[a-z0-9-]+$/',
'description' => 'required|string',
'allowedTools' => 'nullable|string'  // Comma-separated
```

### JSON Content

```php
'content' => 'required|json'  // For settings.json, hooks
```

## Error Handling

- File not found: Returns empty content, allows creation
- Permission denied: Returns 500 with error message
- Validation errors: Flash to session, displayed via `@error` directive

## Notifications

Uses Laravel session flash messages:

```php
return redirect()->back()->with('success', 'Saved successfully');
return redirect()->back()->with('error', 'Failed to save');
```

Displayed via:
```blade
@if(session('success'))
    <div class="notification success">{{ session('success') }}</div>
@endif
```

Auto-hide after 3 seconds via CSS animation.

## File Permissions

The PHP container needs write access to the appuser home directory. This is handled by:

1. User-data volume mounted at `/home/appuser`
2. Entrypoint sets group permissions
3. Container runs as host user (via `USER_ID`/`GROUP_ID`)

**Source:** `docker-laravel/local/php/entrypoint.sh`
