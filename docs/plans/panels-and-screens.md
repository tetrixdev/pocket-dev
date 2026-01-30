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

### State vs Peek: Critical Distinction

**State and Peek serve different purposes and have different formats.**

| Concept | Format | Contains | Purpose |
|---------|--------|----------|---------|
| **State** | JSON | Interaction inputs | Persistence, restoring UI |
| **Peek** | Markdown | Computed visible output | AI awareness |

**State example (JSON):**
```json
{
  "expanded": ["/workspace/src", "/workspace/src/Models"],
  "selected": "/workspace/src/Models/User.php",
  "scroll_position": 120
}
```

**Peek example (Markdown):**
```markdown
## File Explorer: /workspace

📁 src/ (expanded)
   📁 Models/ (expanded)
      📄 User.php (4.2 KB)
      📄 Post.php (2.1 KB)
   📄 helpers.php (0.5 KB)
📁 tests/ (collapsed)

*5 items visible, 1 collapsed*
```

**Why they're different:**

1. **State is inputs, peek is outputs** - State says "Models is expanded", peek shows what's IN Models
2. **Peek requires fresh data** - Files may have changed since panel opened
3. **Format for audience** - JSON for machines, markdown for AI context

**Peek computation:**
```
Peek = fetch_fresh_data() + apply_state() + format_as_markdown()
```

---

### Peek Implementation: Reuse Script Field

**Decision:** For `type=panel`, the `script` field generates peek output.

```
tools table:
├── type: 'script' → script runs as tool, returns output to AI
├── type: 'panel'  → blade_template renders visual, script generates peek
```

**How it works:**

1. System loads panel state from `panel_states` table
2. Passes state + params to script via environment variables
3. Script fetches fresh data, applies state, outputs markdown
4. Markdown returned to AI

**Environment variables available to peek script:**
```bash
PANEL_STATE='{"expanded":["/workspace/src"],"selected":null}'
PANEL_PARAMS='{"path":"/workspace"}'
PANEL_INSTANCE_ID='abc-123'
```

**Example peek script:**
```bash
#!/bin/bash
# Peek script for file-explorer panel

ROOT=$(echo "$PANEL_PARAMS" | jq -r '.path // "/workspace"')
EXPANDED=$(echo "$PANEL_STATE" | jq -r '.expanded // []')

echo "## File Explorer: $ROOT"
echo ""

# List directory contents
for item in "$ROOT"/*; do
    name=$(basename "$item")
    if [ -d "$item" ]; then
        # Check if this folder is expanded
        is_expanded=$(echo "$EXPANDED" | jq -r --arg p "$item" 'index($p) != null')
        if [ "$is_expanded" = "true" ]; then
            echo "📁 $name/ (expanded)"
            # Show children
            for child in "$item"/*; do
                child_name=$(basename "$child")
                [ -d "$child" ] && echo "   📁 $child_name/" || echo "   📄 $child_name"
            done
        else
            echo "📁 $name/ (collapsed)"
        fi
    else
        size=$(du -h "$item" 2>/dev/null | cut -f1)
        echo "📄 $name ($size)"
    fi
done
```

**Why bash script (not PHP)?**
- Consistent with tool scripts (AI already knows the pattern)
- Can call system commands directly (ls, git, etc.)
- Separation: Blade for visual, bash for text
- If complex logic needed, script can call `php artisan` commands

---

### Generic Infrastructure (No Per-Panel Backend Code)

**All panels use the same endpoints:**

```
POST /api/panel/{instance}/state   → Store state (generic controller)
GET  /api/panel/{instance}/peek    → Run peek script, return markdown
GET  /api/panel/{instance}/render  → Run blade, return HTML
DELETE /api/panel/{instance}       → Close panel, clean up state
```

**State update controller (generic for all panels):**
```php
class PanelStateController {
    public function update(Request $request, string $instanceId) {
        $state = PanelState::findOrFail($instanceId);
        $state->state = $request->json('state');
        $state->updated_at = now();
        $state->save();

        return response()->json(['ok' => true]);
    }
}
```

**Peek controller (runs the panel's script):**
```php
class PanelPeekController {
    public function peek(string $instanceId) {
        $panelState = PanelState::with('panel')->findOrFail($instanceId);
        $panel = $panelState->panel;

        // Set environment variables
        $env = [
            'PANEL_STATE' => json_encode($panelState->state),
            'PANEL_PARAMS' => json_encode($panelState->parameters),
            'PANEL_INSTANCE_ID' => $instanceId,
        ];

        // Run the peek script
        $process = Process::env($env)->run($panel->script);

        return response($process->output())
            ->header('Content-Type', 'text/markdown');
    }
}
```

**Key benefit:** Panel authors write blade + script. Zero custom backend code per panel.

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

### Decisions Made

- [x] **Unified tools + panels** - Same table, `type: 'script' | 'panel'`
- [x] **Peek via script field** - For panels, `script` generates peek markdown
- [x] **State stored server-side** - `panel_states` table with hybrid sync
- [x] **Generic endpoints** - No per-panel backend code
- [x] **State ≠ Peek** - State is JSON inputs, Peek is markdown output
- [x] **Auto peek on open** - Default yes, with parameter to disable (`--no-peek`)
- [x] **Peek detail level** - Should represent what user sees; panel author decides specifics
- [x] **No push updates** - AI peeks on demand, no real-time push of user interactions
- [x] **No rate limiting** - Trust AI behavior, no artificial limits on peek frequency
- [x] **No system truncation** - Panel authors control verbosity in their peek scripts

---

## Part 2: Sessions & Screen Architecture

### New Entity: Session

**Session** is a new entity between Workspace and Conversations.

```
Workspace
└── Session (NEW - groups related work)
    └── Screens (tabs within the session)
        ├── Chat 1 (conversation)
        ├── Chat 2 (another conversation)
        ├── File Explorer (panel)
        └── Git Diff (panel)
```

**Key concept:** A session can have multiple chat screens (conversations) AND multiple panel screens. Screens belong to the session, not to a conversation.

---

### UI Layout

```
┌─────────────┬─────────────────────────────────────────────────┐
│ Sessions    │ [Chat] [Files] [Git] [Chat 2] [+]    ← tabs     │
│ ─────────── ├─────────────────────────────────────────────────┤
│ > Auth Bug  │                                                 │
│   API Work  │            [Active Screen Content]              │
│   General   │                                                 │
│             │              (full viewport)                    │
│ [+ New]     │                                                 │
└─────────────┴─────────────────────────────────────────────────┘
     ↑                              ↑
  Sessions list              Tabs for screens
  (replaces old               within session
   conversations list)
```

**Mobile:**
- Same tabs at top (icons or abbreviated names)
- Swipe left/right as additional navigation method
- Sessions accessible via menu/drawer

**Desktop:**
- Sessions list in left sidebar
- Tabs at top of main area
- Click tabs to switch screens

---

### Screen Behaviors

| Behavior | Description |
|----------|-------------|
| Screen order | User can reorder via drag |
| Chat position | Can be anywhere (starts first, but movable) |
| Close panel | UI button (X on tab) |
| Close chat | Archives the conversation, tab disappears |
| Max screens | Unlimited |
| Screen size | Full viewport (like current chat) |
| State persists | Screens survive page refresh |

---

### Session Behaviors

| Behavior | Description |
|----------|-------------|
| Contains screens | Chats + panels grouped together |
| Multiple chats | A session can have multiple conversations |
| Persistence | Screens belong to session, persist until closed |
| Last active | Track which screen was last active per session |
| Archiving | Sessions can be archived (soft delete), still viewable |

---

### URL & Navigation

**URL stays at session level:**
```
/workspace/default/session/abc123
```

**Browser back/forward navigates between sessions, not screens:**
```
1. User in Session A, switches tabs (Chat → Files → Git)
2. User goes to Session B, switches tabs
3. User goes to external site
4. Press back → Session B (opens last active screen)
5. Press back → Session A (opens last active screen)
```

**No deep linking to specific screens** - each session opens to its last active screen.

---

### Adding New Screens

**[+] button in tabs area** shows options:
- "New Chat" → Creates new conversation, adds chat tab
- "Add Panel" → Shows available panels to open

**AI can also open panels:**
```
AI: /file-explorer --path=/workspace
→ New tab appears in current session
```

---

### Archiving

| Entity | Can Archive? | What Happens |
|--------|--------------|--------------|
| Session | Yes (soft delete) | Hidden from default list, can filter to see. All screens preserved. |
| Conversation | Yes | Chat tab closes, conversation data persists in archive. Can restore. |
| Panel | No | Just close it (X button), state is gone |

**Archived conversation in active session:**
- Tab disappears from session
- Conversation still exists in archived state
- Could add "Restore archived chats" option later

---

### Session State Schema

```sql
sessions table:
├── id: uuid
├── workspace_id: foreign key
├── name: string (user-editable, or auto-generated)
├── is_archived: boolean (default false)
├── last_active_screen_id: foreign key (nullable)
├── screen_order: json (array of screen IDs in display order)
├── created_at: timestamp
└── updated_at: timestamp

screens table:
├── id: uuid
├── session_id: foreign key
├── type: enum('chat', 'panel')
├── conversation_id: foreign key (for type='chat')
├── panel_slug: string (for type='panel')
├── panel_instance_id: string (for type='panel', links to panel_states)
├── parameters: json (for panels)
├── is_active: boolean
├── created_at: timestamp
└── updated_at: timestamp
```

---

### Decisions Made

- [x] **Session entity** - New object between workspace and conversations
- [x] **Multiple chats per session** - Yes, each chat is a screen tab
- [x] **Screen order** - User can reorder via drag
- [x] **Chat position** - Not fixed, can be anywhere
- [x] **Close panel** - UI button (X on tab)
- [x] **Max screens** - Unlimited
- [x] **Desktop nav** - Tabs at top
- [x] **Mobile nav** - Tabs/icons + swipe as bonus
- [x] **URL** - Session level only, back/forward between sessions
- [x] **Last active tracking** - Per session
- [x] **Archiving** - Sessions and conversations can archive, panels just close
- [x] **Adding screens** - [+] button with "New Chat" / "Add Panel"

---

## ~~Part 3: Rich Blocks~~ (Removed from Scope)

**Decision:** Rich Blocks removed from v1 scope.

**Reasoning:**
- Panels already provide interactive visualizations
- AI markdown (tables, code blocks) handles simple cases
- Significant complexity for marginal benefit
- Can revisit later if there's user demand

---

## Implementation Phases

### Phase 1: Extend Tools Table for Panels
- [ ] Add `type` enum ('script', 'panel') to tools table
- [ ] Add `blade_template` field for panels
- [ ] Panel rendering endpoint (`/panel/{slug}`)
- [ ] Peek endpoint (`/api/panel/{instance}/peek`)
- [ ] State sync endpoint (`/api/panel/{instance}/state`)

### Phase 2: Sessions Entity
- [ ] Create `sessions` table
- [ ] Create `screens` table
- [ ] Migrate existing conversations to default session
- [ ] Session CRUD (create, archive, restore)

### Phase 3: Screen UI
- [ ] Tabs at top for screens within session
- [ ] [+] button for "New Chat" / "Add Panel"
- [ ] Screen reordering (drag)
- [ ] Close panel (X button)
- [ ] Archive conversation (removes tab)

### Phase 4: Session UI
- [ ] Sessions list in sidebar (replaces conversations list)
- [ ] Session switching
- [ ] Last active screen tracking
- [ ] URL routing at session level

### Phase 5: Panel State & Peek
- [ ] `panel_states` table
- [ ] Hybrid state sync (Alpine → debounced server)
- [ ] Peek script execution
- [ ] Auto-peek on panel open

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
