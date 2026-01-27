# Panels & Screens System - Planning Document

> **Status**: Brainstorming
> **Last Updated**: 2025-01-27

## Overview

PocketDev needs a generic extensibility system allowing users to create custom interactive views beyond the chat interface. This document explores the architecture for "Panels" (custom views) and how they integrate with the UI.

---

## Part 1: Panel Implementation Options

The core question: **How do panels get their data and render their UI?**

### Option A: Alpine.js + HTML Template + Bash API Script

**Architecture:**
```
┌─────────────────────────────────────────┐
│ Panel Definition                        │
├─────────────────────────────────────────┤
│ template: (Alpine.js + HTML)            │
│ api_script: (Bash script returning JSON)│
└─────────────────────────────────────────┘

┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│   Browser    │ ──── │  API Route   │ ──── │ Bash Script  │
│  Alpine.js   │ fetch│  /panel/X    │ exec │ returns JSON │
└──────────────┘      └──────────────┘      └──────────────┘
```

**Example panel definition:**
```json
{
  "slug": "file-explorer",
  "name": "File Explorer",
  "template": "<div x-data=\"fileExplorer()\" x-init=\"loadFiles()\">...</div>",
  "api_script": "#!/bin/bash\nfind \"$TOOL_PATH\" -maxdepth 2 -type f -o -type d | jq -R -s 'split(\"\\n\")'",
  "parameters": {
    "path": { "type": "string", "default": "/workspace/default" }
  }
}
```

**Pros:**
- Separation of concerns (view logic vs data fetching)
- API script is reusable (could be called from chat too)
- Familiar pattern (mirrors existing tools architecture)
- Template runs in browser - no PHP security concerns
- Hot reload friendly (template changes don't need PHP restart)

**Cons:**
- Two things to write and keep in sync (template + script)
- Need to define/wire up API endpoints
- More complex for simple cases ("just show me X")
- Can't use Blade directives or Laravel helpers
- AI needs to understand both Alpine AND bash scripting
- Async data loading adds complexity (loading states, error handling)

---

### Option B: Pure Blade File

**Architecture:**
```
┌─────────────────────────────────────────┐
│ Panel Definition                        │
├─────────────────────────────────────────┤
│ blade_template: (Full Blade + PHP)      │
└─────────────────────────────────────────┘

┌──────────────┐      ┌──────────────┐
│   Browser    │ ──── │  PHP/Blade   │ (queries DB, runs commands, renders HTML)
│              │ HTML │              │
└──────────────┘      └──────────────┘
```

**Example panel definition:**
```php
// Stored in database or as file
@php
    $path = $parameters['path'] ?? '/workspace/default';
    $files = collect(File::files($path))->map(fn($f) => [
        'name' => $f->getFilename(),
        'size' => $f->getSize(),
        'perms' => substr(sprintf('%o', $f->getPerms()), -4),
    ]);
@endphp

<div class="p-4">
    <h2 class="text-lg font-bold">Files in {{ $path }}</h2>
    <ul>
        @foreach($files as $file)
            <li>{{ $file['name'] }} ({{ $file['perms'] }})</li>
        @endforeach
    </ul>
</div>
```

**Pros:**
- Single file, simpler mental model
- Full power of Laravel (Eloquent, File facade, helpers, Carbon, etc.)
- Can query memory tables directly via DB facade
- All logic in one place - easier to reason about
- Familiar to anyone who knows Laravel
- Server-side rendering (faster initial paint, SEO if ever needed)
- Can still add Alpine.js for interactivity

**Cons:**
- PHP execution = theoretical security risk
  - Mitigated: User hosts their own instance, manages their own panels
  - Mitigated: Could sandbox with allowed function list if paranoid
- Blade compilation needs write access to storage/views
- Changes require clearing view cache (or auto-clear on panel update)
- Mixing data fetching and presentation (but Laravel devs do this all the time)
- AI needs Laravel/Blade knowledge (but it's very common)

---

### Option C: Blade File + Service Injection

**Architecture:**
```
┌─────────────────────────────────────────┐
│ Panel Definition                        │
├─────────────────────────────────────────┤
│ blade_template: (Blade, limited PHP)    │
│ data_provider: (PHP class or closure)   │
└─────────────────────────────────────────┘

Data provider runs first, passes $data to template.
Template has access to $data but limited @php usage.
```

**Example:**
```php
// Data provider (stored separately)
return function($parameters) {
    $path = $parameters['path'] ?? '/workspace/default';
    return [
        'files' => File::files($path),
        'path' => $path,
    ];
};

// Template (cleaner, mostly HTML)
<div class="p-4">
    <h2>Files in {{ $path }}</h2>
    @foreach($files as $file)
        <x-file-item :file="$file" />
    @endforeach
</div>
```

**Pros:**
- Cleaner separation than pure Blade
- Template stays mostly presentational
- Data provider is testable
- Could restrict template to "safe" Blade subset

**Cons:**
- Still two things to manage
- More complex than pure Blade
- The "safety" is mostly theater - user controls both parts anyway
- Adds abstraction without clear benefit for this use case

---

### Option D: Blade + Existing Artisan Commands

**Architecture:**
```
┌─────────────────────────────────────────┐
│ Panel Definition                        │
├─────────────────────────────────────────┤
│ blade_template: (Blade that calls       │
│                  existing artisan cmds) │
└─────────────────────────────────────────┘
```

**Example:**
```php
@php
    // Use existing memory system
    $entities = json_decode(Artisan::output(
        Artisan::call('memory:query', [
            '--schema' => 'default',
            '--sql' => 'SELECT name, importance FROM memory_default.entities WHERE is_alive = true'
        ])
    ));

    // Or existing tool
    $files = json_decode(shell_exec('php artisan tool:run file-list -- --path=' . escapeshellarg($path)));
@endphp

<div x-data="{ selected: null }">
    @foreach($entities as $entity)
        <div @click="selected = '{{ $entity->id }}'">{{ $entity->name }}</div>
    @endforeach
</div>
```

**Pros:**
- Leverages existing infrastructure (memory tools, custom tools)
- No new API layer needed
- Single file
- Consistent with how AI already interacts with the system

**Cons:**
- Artisan calls have overhead (process spawning)
- Awkward syntax for complex queries
- Still full PHP access (same "security" as Option B)
- Output parsing can be fragile

---

## Recommendation

**Option B (Pure Blade)** with these considerations:

1. **Simplicity wins**: One file, full Laravel power, familiar patterns
2. **Security is moot**: User controls their instance and their panels
3. **AI capability**: Claude knows Blade/Laravel very well
4. **Interactivity**: Alpine.js can be added in the Blade file as needed
5. **Existing patterns**: Current file preview modal already uses Blade

**Implementation approach:**
- Store panel Blade content in database (like tool scripts)
- Render via `Blade::render($template, $parameters)`
- Auto-clear compiled view on panel update
- Provide helpful injected variables: `$parameters`, `$user`, `$workspace`

**Example panel workflow:**
```
User: "Create a panel that shows all my D&D entities with their locations"

AI: Creates panel definition with:
- slug: "entity-map"
- name: "Entity Map"
- blade_template: (queries memory_default.entities, renders table with Alpine sorting)
- parameters: { importance: { type: "string", default: "all" } }

User: /entity-map --importance=major
→ Panel opens showing filtered entity list
```

---

## Part 2: Screen Architecture (Swipeable Multi-Screen)

### Concept

Instead of tabs or modals, screens are a **carousel of contexts**:

```
Mobile (swipe gesture):
┌─────────────────────────────────────────┐
│             ● ○ ○ ○                     │ ← dot indicators
├─────────────────────────────────────────┤
│                                         │
│            [Active Screen]              │
│                                         │
│         ← swipe left/right →            │
│                                         │
└─────────────────────────────────────────┘

Desktop (sidebar or tabs):
┌─────────┬───────────────────────────────┐
│ Screens │                               │
│ ─────── │                               │
│ ● Chat  │       [Active Screen]         │
│ ○ Files │                               │
│ ○ Git   │                               │
│ + Add   │                               │
└─────────┴───────────────────────────────┘
```

### Screen Behaviors

| Behavior | Description |
|----------|-------------|
| Chat is always first | Home screen, can't be closed |
| Add via slash command | `/file-explorer` opens as new screen |
| Close with X or command | `/close file-explorer` |
| Reorder via drag | User can arrange screen order |
| State persists | Screen state survives conversation switches |
| Parameters in URL | `?screen=file-explorer&path=/tmp` |

### Open Questions

- [ ] Maximum number of screens? (Probably 5-6 for sanity)
- [ ] How to handle screen that needs full viewport vs. one that's compact?
- [ ] Screen-to-screen communication? (e.g., click file in explorer → opens in editor screen)

---

## Part 3: Rich Blocks (Inline in Chat)

### Concept

Lightweight interactive elements embedded directly in chat responses. Not full panels, but richer than plain text.

```
Assistant: Here's the current status:

╭─ entity-table ──────────────────────────╮
│ Name          │ Class   │ HP           │
│───────────────│─────────│──────────────│
│ Kael          │ Fighter │ 44/44        │
│ Mara          │ —       │ 9/9          │
│ [+] Show 45 more...                    │
╰─────────────────────────────────────────╯

The party is currently...
```

### Block Types (Built-in)

| Type | Use Case |
|------|----------|
| `table` | Data tables with sorting/filtering |
| `file-tree` | Collapsible directory listing |
| `code` | Syntax highlighted, copy button (already exists?) |
| `diff` | Side-by-side or unified diff view |
| `mermaid` | Diagrams rendered from mermaid syntax |
| `image` | Image with lightbox on click |
| `json` | Collapsible JSON tree |

### Syntax Options

**Option 1: Fenced blocks with metadata**
````markdown
```block:table
| Name | Value |
|------|-------|
| Foo  | 123   |
```
````

**Option 2: HTML-like tags**
```markdown
<block type="file-tree" root="/workspace" depth="2" />
```

**Option 3: Directive syntax**
```markdown
:::table{sortable=true}
| Name | Value |
:::
```

### Difference from Panels

| Aspect | Rich Block | Panel |
|--------|-----------|-------|
| Location | Inline in chat message | Separate screen |
| Persistence | Scrolls away with chat | Stays until closed |
| Interactivity | Limited (expand, sort) | Full (forms, navigation) |
| Data | Static at render time | Can refresh/fetch |
| Creation | AI includes in response | Defined, then invoked |

---

## Part 4: Session/Focus Layer

### The Problem

Currently: `Workspace → Conversations`

User works on "Auth Bug" over 3 days:
- Day 1: Conversation exploring the issue
- Day 2: Conversation trying a fix
- Day 3: Follow-up conversation

These are disconnected. Plus they had a file explorer and git diff panel open - those don't persist.

### Proposed Hierarchy

```
Workspace ("default")
└── Focus ("Auth Bug Investigation")
    ├── Conversation: "Initial exploration" (Jan 25)
    ├── Conversation: "Trying the fix" (Jan 26)
    ├── Conversation: "Follow-up" (Jan 27) ← active
    ├── Screen: File Explorer @ /src/auth
    ├── Screen: Git Diff
    └── Screen: Notes
```

### Focus Behaviors

| Behavior | Description |
|----------|-------------|
| Groups conversations | Related chats stay together |
| Persists screens | Panel layout survives across conversations |
| Switchable | Dropdown or list to change focus |
| Archivable | Done with a focus? Archive it |
| Default focus | Quick chats go to "General" or auto-created |

### Naming Alternatives

| Name | Pros | Cons |
|------|------|------|
| Focus | Clear intent, "what are you focused on?" | Might imply singular attention |
| Session | Familiar term | Conflicts with auth sessions |
| Context | Accurate | Generic, overloaded term |
| Topic | Simple | Feels too casual |
| Project | Clear | Might conflict with workspace meaning |
| Thread | Common in chat apps | Already have conversations |

**Current preference**: "Focus" or "Context"

### Open Questions

- [ ] Should AI have context from ALL conversations in a focus, or just current?
- [ ] Focus tied to one workspace or can span multiple?
- [ ] Quick chat mode without explicit focus?

---

## Implementation Phases

### Phase 1: Panel Foundation
- [ ] Database schema for panels (slug, name, blade_template, parameters)
- [ ] Panel CRUD via artisan commands (`panel:create`, `panel:list`, etc.)
- [ ] Panel rendering endpoint (`/panel/{slug}`)
- [ ] Basic panel viewer (full-screen modal, like current file preview)
- [ ] Slash command to open panels (`/panel-name`)

### Phase 2: Multi-Screen UI
- [ ] Screen state management (which screens open, order)
- [ ] Mobile: Swipe navigation between screens
- [ ] Desktop: Sidebar or tab-based screen switching
- [ ] Screen persistence (survives page refresh)

### Phase 3: Rich Blocks
- [ ] Block syntax parsing in chat renderer
- [ ] Built-in block types (table, file-tree, code, diff)
- [ ] Block interactivity (expand/collapse, sort)

### Phase 4: Focus/Session Layer
- [ ] Database schema for focuses
- [ ] Focus-conversation relationship
- [ ] Focus-screen relationship
- [ ] Focus switcher UI
- [ ] (Optional) Cross-conversation context for AI

---

## Appendix: Example Panel Definitions

### File Explorer
```php
@php
    $path = $parameters['path'] ?? '/workspace/default';
    $items = collect(File::allFiles($path, true))
        ->take(100)
        ->map(fn($f) => [
            'path' => $f->getPathname(),
            'name' => $f->getFilename(),
            'dir' => $f->getPath(),
            'size' => $f->getSize(),
            'perms' => substr(sprintf('%o', $f->getPerms()), -4),
            'modified' => $f->getMTime(),
        ]);
@endphp

<div x-data="{
    items: {{ Js::from($items) }},
    expanded: {},
    toggle(dir) { this.expanded[dir] = !this.expanded[dir] }
}">
    <template x-for="item in items" :key="item.path">
        <div class="flex items-center gap-2 py-1 hover:bg-gray-100 dark:hover:bg-gray-800">
            <span class="text-gray-400 font-mono text-xs" x-text="item.perms"></span>
            <span x-text="item.name"></span>
            <span class="text-gray-400 text-xs" x-text="(item.size / 1024).toFixed(1) + ' KB'"></span>
        </div>
    </template>
</div>
```

### Git Status
```php
@php
    $root = $parameters['path'] ?? '/workspace/default';
    $status = shell_exec("cd " . escapeshellarg($root) . " && git status --porcelain");
    $lines = collect(explode("\n", trim($status)))->filter()->map(function($line) {
        return [
            'status' => substr($line, 0, 2),
            'file' => trim(substr($line, 3)),
        ];
    });
@endphp

<div class="p-4">
    <h2 class="text-lg font-bold mb-4">Git Status</h2>
    @forelse($lines as $line)
        <div class="flex gap-2 py-1 font-mono text-sm">
            <span class="w-8 {{ $line['status'][0] === 'M' ? 'text-yellow-500' : ($line['status'][0] === 'A' ? 'text-green-500' : 'text-red-500') }}">
                {{ $line['status'] }}
            </span>
            <span>{{ $line['file'] }}</span>
        </div>
    @empty
        <p class="text-gray-500">Working tree clean</p>
    @endforelse
</div>
```

### Memory Table Viewer
```php
@php
    $schema = $parameters['schema'] ?? 'default';
    $table = $parameters['table'] ?? 'entities';
    $limit = $parameters['limit'] ?? 50;

    $results = DB::connection('memory')
        ->table("memory_{$schema}.{$table}")
        ->limit($limit)
        ->get();

    $columns = $results->isNotEmpty()
        ? array_keys((array) $results->first())
        : [];
@endphp

<div x-data="{ sortBy: null, sortDir: 'asc' }" class="overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead>
            <tr>
                @foreach($columns as $col)
                    <th class="px-2 py-1 text-left cursor-pointer hover:bg-gray-100"
                        @click="sortBy = '{{ $col }}'; sortDir = sortDir === 'asc' ? 'desc' : 'asc'">
                        {{ $col }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($results as $row)
                <tr class="border-t">
                    @foreach($columns as $col)
                        <td class="px-2 py-1 max-w-xs truncate">
                            {{ is_array($row->$col) ? json_encode($row->$col) : Str::limit($row->$col, 50) }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
```
