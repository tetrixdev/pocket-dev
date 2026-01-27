# Panels & Screens System - Planning Document

> **Status**: In Progress
> **Last Updated**: 2025-01-27

## Overview

PocketDev needs a generic extensibility system allowing users to create custom interactive views beyond the chat interface. This document defines the architecture for "Panels" (custom views) and how they integrate with the UI.

---

## Part 1: Panel Architecture

Panels are **Pure Blade templates** stored in the database, giving full Laravel power for data access and rendering.

### Why Blade?

1. **Single file** - one thing to write and maintain
2. **Full Laravel power** - Eloquent, File facade, DB queries, helpers, Carbon, etc.
3. **Security is moot** - user hosts their own instance, controls what panels exist
4. **AI-friendly** - Claude knows Blade/Laravel extremely well
5. **Still interactive** - Alpine.js can be added for client-side state when needed
6. **Existing patterns** - current file preview modal already uses Blade

### Architecture

```
┌─────────────────────────────────────────┐
│ Panel Definition (in database)          │
├─────────────────────────────────────────┤
│ slug: "file-explorer"                   │
│ name: "File Explorer"                   │
│ blade_template: (Full Blade + PHP)      │
│ parameters: { path: { default: "..." }} │
└─────────────────────────────────────────┘

┌──────────────┐      ┌──────────────┐
│   Browser    │ ──── │  PHP/Blade   │ (queries DB, runs commands, renders HTML)
│              │ HTML │              │
└──────────────┘      └──────────────┘
```

### Implementation Approach

- Store panel Blade content in database (like tool scripts)
- Render via `Blade::render($template, $parameters)`
- Auto-clear compiled view on panel update
- Provide helpful injected variables: `$parameters`, `$user`, `$workspace`

### Example Panel Definition

```php
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

### Example Workflow

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

## Part 1b: Deep Dive - Unified Tools/Panels & State Management

### Decision: Unify Tools and Panels

Rather than creating a separate system, panels will be an extension of the existing tools system.

**Unified `tools` table:**

```
tools table (extended)
├── slug: string (unique identifier)
├── name: string (display name)
├── description: string (for AI context)
├── parameters: json (input schema)
├── type: enum('script', 'panel')      ← NEW
├── script: text (for type=script)
├── blade_template: text (for type=panel)  ← NEW
├── system_prompt: text (AI instructions)
└── ... existing fields
```

**Why unify?**
- One concept for users to learn
- Same invocation pattern (slash commands, AI tool calls)
- Same parameter handling
- Less code to maintain
- Clear mental model: "tools do things, some return text, some open panels"

**Frontend differentiation:**
- Script tools: current color (blue?)
- Panel tools: different color (purple?) to indicate visual output
- Both invoked the same way: `/tool-name --param=value`

---

### The Peek Concept: AI Awareness of Panel State

**Core idea:** When AI opens a panel, it immediately receives a "peek" - a text representation of what the user sees. User can also ask AI to peek at current state anytime.

#### How Peek Works

```
┌─────────────────────────────────────────────────────────────────┐
│ AI invokes: /file-explorer --path=/workspace                    │
├─────────────────────────────────────────────────────────────────┤
│ 1. Panel opens visually for user                                │
│ 2. AI receives peek response:                                   │
│                                                                 │
│    Panel "File Explorer" opened at /workspace                   │
│    ──────────────────────────────────────────                   │
│    📁 src/ (collapsed)                                          │
│    📁 tests/ (collapsed)                                        │
│    📄 README.md (2.3 KB)                                        │
│    📄 composer.json (1.1 KB)                                    │
│    ──────────────────────────────────────────                   │
│    4 items visible (2 directories, 2 files)                     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

#### Later: User Asks AI to Look Again

```
User: "What do you see in the file explorer now?"

AI invokes: /peek file-explorer
   OR: internal peek mechanism

AI receives:
    Panel "File Explorer" current state:
    ──────────────────────────────────────────
    📁 src/ (expanded)
    │  📁 Controllers/ (collapsed)
    │  📁 Models/ (expanded)
    │  │  📄 User.php (4.2 KB)
    │  │  📄 Post.php (2.1 KB)
    │  📄 helpers.php (0.5 KB)
    📁 tests/ (collapsed)
    📄 README.md (2.3 KB)
    📄 composer.json (1.1 KB)
    ──────────────────────────────────────────
    7 items visible (4 directories, 3 files)
```

**Key insight:** Peek shows only what's VISIBLE. Collapsed folders don't show contents. This matches what the user actually sees.

---

### State Management Deep Dive

Panels are interactive - users click, expand, navigate. This creates state that must be:
1. Persisted (survives page refresh)
2. Peekable (AI can read current state)
3. Fresh (reflects actual data, not stale cache)

#### What IS State?

| Panel Type | State Includes |
|------------|----------------|
| File Explorer | Expanded folders, scroll position, selected file |
| Git Diff | Which files expanded, view mode (unified/split) |
| Memory Table | Sort column, sort direction, filters, page number |
| Entity Map | Selected entity, zoom level, pan position |

#### Where Does State Live?

**Option A: Client-side only (Alpine.js / localStorage)**
```
Pros: Simple, no server round-trips
Cons: AI can't peek without asking browser, lost on different device
```

**Option B: Server-side (database)**
```
Pros: AI can peek anytime, survives device switches
Cons: Every interaction needs server round-trip, more complex
```

**Option C: Hybrid (client-side with sync)**
```
Pros: Fast interactions, AI can peek, survives refresh
Cons: More complex, potential sync conflicts
```

**Recommendation: Hybrid approach**

```
┌─────────────┐     user clicks     ┌─────────────┐
│   Browser   │ ──────────────────► │ Alpine.js   │ (instant visual update)
│             │                     │   State     │
└─────────────┘                     └──────┬──────┘
                                           │ debounced sync (500ms)
                                           ▼
                                    ┌─────────────┐
                                    │   Server    │
                                    │ panel_state │ (persisted, peekable)
                                    └─────────────┘
```

- User interactions update Alpine state immediately (fast)
- State syncs to server after 500ms debounce (reduces traffic)
- AI peeks read from server state (may be slightly behind)
- Page refresh restores from server state

#### State Schema

```sql
panel_states table:
├── id: uuid
├── user_id: foreign key
├── panel_slug: string
├── instance_id: string (for multiple instances of same panel)
├── parameters: json (the params used to open this instance)
├── state: json (current interaction state)
├── visible_summary: text (pre-computed peek text)
├── updated_at: timestamp
└── created_at: timestamp
```

**Example state for File Explorer:**
```json
{
  "expanded": ["/workspace/src", "/workspace/src/Models"],
  "selected": "/workspace/src/Models/User.php",
  "scroll_position": 120
}
```

---

### State Freshness: When Does Data Refresh?

**The Problem:**
Panel shows file tree at time T. At time T+5, AI creates new file. Panel still shows old tree.

**Scenarios:**

| Scenario | What Happens | User Experience |
|----------|--------------|-----------------|
| User opens panel | Fresh render | ✅ Sees current data |
| User expands folder | Fresh fetch for that folder | ✅ Sees current contents |
| User switches to panel | ??? | Could be stale |
| AI creates file | Panel doesn't know | ❌ Stale until refresh |
| User asks AI "what's in panel?" | AI peeks stale state | ❌ AI sees old data |

**Proposed Solution: Smart Refresh Triggers**

1. **On panel focus** (user switches to it): Check data age, refresh if > N seconds
2. **After AI tool calls**: If tool modified relevant data, trigger panel refresh
3. **Manual refresh button**: User can force refresh
4. **Periodic refresh**: Optional, for dashboards (every 30s?)

**Implementation idea - Tool hooks:**

```php
// When AI runs a tool that might affect panel data
class CreateFileToolHandler {
    public function handle($params) {
        // ... create file ...

        // Notify relevant panels to refresh
        PanelRefreshEvent::dispatch([
            'type' => 'file-explorer',
            'affected_paths' => [$params['path']],
        ]);
    }
}
```

Panel receives event via websocket/SSE, refreshes affected sections.

---

### Rendering Approaches for Interactive Panels

**Challenge:** Blade renders server-side, but interactivity needs client-side logic.

#### Approach 1: Full Pre-render + Alpine State

```php
@php
    // Server fetches ALL data
    $tree = buildFullFileTree($path, maxDepth: 5);
@endphp

<div x-data="{
    tree: {{ Js::from($tree) }},
    expanded: @json($state['expanded'] ?? [])
}">
    {{-- Alpine handles expand/collapse from pre-loaded data --}}
</div>
```

**Pros:** Fast interactions (no server calls for expand)
**Cons:** Large initial payload, data can go stale, deep trees are expensive

#### Approach 2: Lazy Loading via AJAX

```php
<div x-data="fileExplorer('{{ $path }}')" x-init="loadRoot()">
    {{-- Alpine fetches data on demand --}}
</div>

<script>
function fileExplorer(rootPath) {
    return {
        items: [],
        async loadRoot() {
            this.items = await fetch(`/api/panel/file-explorer/list?path=${rootPath}`).then(r => r.json());
        },
        async expand(path) {
            const children = await fetch(`/api/panel/file-explorer/list?path=${path}`).then(r => r.json());
            // merge into tree...
        }
    }
}
</script>
```

**Pros:** Fresh data on each expand, smaller initial load
**Cons:** Slower interactions (network latency), needs API endpoints

#### Approach 3: Hybrid with Intelligent Prefetch

```php
@php
    // Server renders first 2 levels
    $tree = buildFileTree($path, depth: 2);
@endphp

<div x-data="fileExplorer({{ Js::from($tree) }}, '{{ $path }}')">
    {{-- First 2 levels instant, deeper levels lazy-load --}}
</div>
```

**Pros:** Fast initial render, fresh data for deep navigation
**Cons:** More complex logic

**Recommendation:** Start with Approach 1 for simplicity, move to Approach 3 if performance issues arise.

---

### Peek Implementation Details

**How does AI actually "peek" at a panel?**

#### Option A: Panel defines `getPeekText()` method

Each panel includes a PHP function that generates text summary:

```php
// In panel blade template or separate file
@php
function getPeekText($state, $data) {
    $lines = ["📁 File Explorer at {$data['path']}"];
    foreach ($data['items'] as $item) {
        $prefix = in_array($item['path'], $state['expanded']) ? '▼' : '▶';
        $icon = $item['type'] === 'dir' ? '📁' : '📄';
        $lines[] = "{$prefix} {$icon} {$item['name']}";

        // If expanded, show children
        if (in_array($item['path'], $state['expanded'])) {
            foreach ($item['children'] ?? [] as $child) {
                $lines[] = "   {$icon} {$child['name']}";
            }
        }
    }
    return implode("\n", $lines);
}
@endphp
```

**Pros:** Full control over peek format
**Cons:** Panel author must implement, could be inconsistent

#### Option B: Generic state-to-text converter

System automatically converts panel state to text based on data type:

```php
class PanelPeeker {
    public function peek(Panel $panel, array $state): string {
        $data = $panel->getCurrentData($state);
        return $this->formatForAI($panel->type, $data, $state);
    }

    private function formatForAI($type, $data, $state): string {
        return match($type) {
            'file-tree' => $this->formatFileTree($data, $state),
            'table' => $this->formatTable($data, $state),
            'git-diff' => $this->formatDiff($data, $state),
            default => json_encode($data, JSON_PRETTY_PRINT),
        };
    }
}
```

**Pros:** Consistent formatting, panel author doesn't need to implement
**Cons:** Less flexible, might not capture all nuances

#### Option C: Hybrid - Generic with override

Default generic formatter, but panel can override with custom `getPeekText()`.

**Recommendation:** Option C - best of both worlds.

---

### Edge Cases & Considerations

#### 1. Multiple Instances of Same Panel

User opens two file explorers at different paths.

```
file-explorer @ /workspace/project-a
file-explorer @ /workspace/project-b
```

**Solution:** Each instance gets unique `instance_id`, state stored separately.

#### 2. Panel Opens, AI Disconnects

AI opened panel, then conversation ends. Panel still open but no AI to peek.

**Solution:** Panel is tied to user session, not conversation. Persists until user closes.

#### 3. Very Large State

File explorer with 10,000 expanded folders. State object becomes huge.

**Solution:**
- Limit state size (e.g., max 100 expanded items)
- Prune old state (collapse folders not interacted with recently)
- Warn user if state is getting large

#### 4. Concurrent Edits

User has panel open on two devices. Both modify state.

**Solution:** Last-write-wins with timestamp. Or: per-device state.

#### 5. Panel Errors

Panel blade template throws exception. What does AI see?

**Solution:**
- Peek returns error message: "Panel error: [exception message]"
- User sees friendly error in panel UI
- Log for debugging

#### 6. Sensitive Data in Peek

Panel shows secrets, API keys. AI peeks and now it's in conversation history.

**Solution:**
- Panel can mark fields as `sensitive: true`
- Peek redacts sensitive fields: `API_KEY: [redacted]`
- Or: panel author handles in `getPeekText()`

---

### Open Questions

- [ ] Should peek be automatic on panel open, or require explicit AI action?
- [ ] How detailed should peek text be? (full tree vs summary)
- [ ] Should panels be able to "push" updates to AI? (e.g., "user just clicked X")
- [ ] Rate limiting on peek? (prevent AI from peeking every second)
- [ ] Peek cost? (counts toward context window, could be expensive)

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
