# PocketDev Tool System Architecture

## TLDR

Tools are PHP classes in `app/Tools/`. Auto-discovered at startup, no registration needed.

**Key concepts:**
- CLI providers (Claude Code, Codex) have native file tools → exclude `file_ops` category
- API providers (Anthropic, OpenAI) need all tools → include everything
- User tools are bash scripts stored in DB, wrapped as `UserTool` class

See also: [Provider System](architecture/providers.md) | [Philosophy](architecture/philosophy.md)

---

## Overview

PocketDev supports multiple AI providers, each with different tool capabilities. This document describes the unified tool system that manages tool availability, selection, and conflict resolution across all providers.

## Provider Landscape

### Supported Providers

| Provider | Type | Built-in Tools | PocketDev Tools |
|----------|------|----------------|-----------------|
| **Claude Code** | CLI | Yes (Bash, Read, Edit, etc.) | Memory, Tool mgmt, User tools |
| **Codex** | CLI | Yes (similar to Claude Code) | Memory, Tool mgmt, User tools |
| **Anthropic API** | API | No | All tools including file_ops |
| **OpenAI API** | API | No | All tools including file_ops |
| **OpenAI Compatible** | API | No | All tools including file_ops |

### The Design

CLI providers (Claude Code, Codex) have native file operation tools. PocketDev:
1. **Excludes `file_ops` category** for CLI providers (they have native equivalents)
2. **Includes all tools** for API providers (they need our file operations)
3. **Uses category-based filtering** instead of per-tool configuration

---

## Tool Classification

### Two Dimensions

```text
Tool
├── Source (where it comes from)
│   ├── native      → Built into the provider (Claude Code's tools)
│   ├── pocketdev   → PocketDev's PHP Tool classes
│   └── user        → User-created bash script tools
│
└── Scope (which providers can use it)
    ├── universal   → All providers (Memory, Tool management)
    └── restricted  → Specific providers only
```

### Tool Categories

#### 1. Native Tools (Claude Code Only)

These are built into Claude Code and cannot be modified:

| Tool | Description |
|------|-------------|
| Bash | Execute shell commands |
| Read | Read files |
| Write | Write files |
| Edit | Edit files |
| MultiEdit | Multi-file edit |
| Glob | Find files |
| Grep | Search contents |
| LS | List directories |
| Task | Spawn sub-agents |
| WebFetch | Fetch URLs |
| WebSearch | Search web |
| NotebookRead | Read notebooks |
| NotebookEdit | Edit notebooks |

#### 2. PocketDev File Operations (`file_ops` category)

These PHP Tool classes provide file operations for API providers:

| Tool | Class | Notes |
|------|-------|-------|
| Bash | `BashTool` | Execute shell commands |
| Read | `ReadTool` | Read file contents |
| Write | `WriteTool` | Write/create files |
| Edit | `EditTool` | Edit existing files |
| Glob | `GlobTool` | Find files by pattern |
| Grep | `GrepTool` | Search file contents |

**Important:** These are automatically excluded for CLI providers (Claude Code, Codex) because they have native equivalents. See [Provider System](architecture/providers.md) for details.

#### 3. PocketDev Universal Tools (Memory)

These work with ALL providers including Claude Code:

| Tool | Artisan Command |
|------|-----------------|
| Memory Structure Create | `memory:structure:create` |
| Memory Structure Get | `memory:structure:get` |
| Memory Structure Delete | `memory:structure:delete` |
| Memory Create | `memory:create` |
| Memory Query | `memory:query` |
| Memory Update | `memory:update` |
| Memory Delete | `memory:delete` |

**Note:** Relationships are stored as IDs in the data object (e.g., `owner_id`, `location_id`), not in a separate relationships table.

#### 4. PocketDev Universal Tools (Tool Management)

These work with ALL providers including Claude Code:

| Tool | Artisan Command |
|------|-----------------|
| Tool Create | `tool:create` |
| Tool Update | `tool:update` |
| Tool Delete | `tool:delete` |
| Tool List | `tool:list` |
| Tool Show | `tool:show` |
| Tool Run | `tool:run` |

#### 5. User Tools

Custom tools created by users or AI:

| Attribute | Description |
|-----------|-------------|
| Source | `user` |
| Storage | Database (script in `script` column) |
| Execution | Via `tool:run <slug>` |
| Scope | Universal (all providers) |

---

## Database Schema

### Table: `pocket_tools`

Stores **user-created tools only**. PocketDev built-in tools are PHP classes (not in database).

```sql
CREATE TABLE pocket_tools (
    id UUID PRIMARY KEY,

    -- Identity
    slug VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,

    -- Classification
    source VARCHAR(50) NOT NULL DEFAULT 'user',  -- Always 'user' now
    category VARCHAR(100) DEFAULT 'custom',       -- 'custom' for user tools

    -- AI Instructions
    system_prompt TEXT NOT NULL,
    input_schema JSON,

    -- Script
    script TEXT NOT NULL,  -- Bash script content

    -- State
    enabled BOOLEAN DEFAULT TRUE,

    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**Note:** The `source` column is 'user' for all database records. PocketDev tools are PHP classes that are auto-discovered, not stored in the database.

### Table: `tool_conflicts`

Defines which tools conflict with each other:

```sql
CREATE TABLE tool_conflicts (
    id SERIAL PRIMARY KEY,

    tool_a_slug VARCHAR(255) NOT NULL,  -- Can reference pocket_tools.slug or native tool name
    tool_b_slug VARCHAR(255) NOT NULL,

    conflict_type VARCHAR(50) NOT NULL,  -- 'equivalent' | 'incompatible'
    resolution_hint TEXT,

    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    UNIQUE(tool_a_slug, tool_b_slug)
);
```

**Conflict Types:**
- `equivalent`: Tools do the same thing (e.g., PocketDev Bash ≈ Native Bash)
- `incompatible`: Tools cannot work together (rare)

---

## Tool Selection Logic

### The Provider Enum

Tool filtering is based on the `App\Enums\Provider` enum:

```php
enum Provider: string
{
    case Anthropic = 'anthropic';
    case OpenAI = 'openai';
    case ClaudeCode = 'claude_code';
    case Codex = 'codex';
    case OpenAICompatible = 'openai_compatible';

    public function isCliProvider(): bool
    {
        return match ($this) {
            self::ClaudeCode, self::Codex => true,
            default => false,
        };
    }
}
```

### ToolSelector Service

```php
use App\Enums\Provider;

class ToolSelector
{
    /**
     * Get all tools from the registry.
     */
    public function getAllTools(): Collection
    {
        return collect($this->registry->all());
    }

    /**
     * Get default enabled tools for a provider.
     * CLI providers: excludes file_ops (they have native equivalents)
     * API providers: includes everything
     */
    public function getDefaultTools(string $provider): Collection
    {
        $tools = $this->getAllTools();
        $providerEnum = Provider::tryFrom($provider);

        if ($providerEnum?->isCliProvider()) {
            return $tools->filter(fn($t) => $t->category !== 'file_ops');
        }

        return $tools;
    }
}
```

### Provider-Specific Behavior

#### CLI Providers (Claude Code, Codex)

```php
// Native tools are handled by the CLI itself
// PocketDev tools are injected via system prompt as artisan commands

$pocketDevTools = $toolSelector->getDefaultTools(Provider::ClaudeCode->value);
// Returns: Memory tools, Tool management tools, User tools
// Excludes: file_ops category (Bash, Read, Edit, Glob, Grep)

$systemPrompt = $this->buildToolInstructions($pocketDevTools);
// Injected via --append-system-prompt
```

#### API Providers (Anthropic, OpenAI, OpenAI Compatible)

```php
// All tools are registered as function definitions
$tools = $toolSelector->getAvailableTools(Provider::Anthropic->value);
// Returns: ALL tools including file_ops

foreach ($tools as $tool) {
    $registry->register($tool);
}
```

---

## System Prompt Injection

### For CLI Providers

When a CLI provider (Claude Code, Codex) is used, PocketDev injects tool instructions for tools that don't have native equivalents:

```markdown
# PocketDev Tools

These PocketDev tools are invoked via PHP Artisan commands. Use your Bash tool to execute them.

## How to Invoke

Use the `pd` command (PocketDev wrapper) to run artisan commands from any directory:

**Built-in commands (memory, tool management):**
```bash
pd memory:query --schema=default --sql="SELECT * FROM memory_default.schema_registry"
pd memory:insert --schema=default --table=example --data='{"name":"Test"}'
pd tool:list
```

**User-created tools:**
```bash
pd tool:run <slug> -- --arg1=value1 --arg2=value2
```

**Important:** Only `tool:run` requires the `--` separator before tool arguments.

## System Tools

Built-in PocketDev tools.

### memory:query
[instructions from Tool class]

### memory:create
[instructions from Tool class]

... (other memory/tool management commands)

## Custom Tools

User-created tools invoked via `tool:run`.

### tool:run my-custom-tool
[instructions from PocketTool model]

... (user-created tools)
```

### For Anthropic/OpenAI

Tools are registered as native tools with their `description` and `input_schema`. The `system_prompt` field is optionally injected into the conversation system prompt for detailed instructions.

---

## Conflict Resolution

### Predefined Conflicts

```php
// Seeded in tool_conflicts table
$conflicts = [
    // PocketDev file ops conflict with Claude Code native tools
    ['pocketdev-bash', 'native:Bash', 'equivalent', 'Use native Bash for Claude Code'],
    ['pocketdev-read', 'native:Read', 'equivalent', 'Use native Read for Claude Code'],
    ['pocketdev-edit', 'native:Edit', 'equivalent', 'Use native Edit for Claude Code'],
    ['pocketdev-glob', 'native:Glob', 'equivalent', 'Use native Glob for Claude Code'],
    ['pocketdev-grep', 'native:Grep', 'equivalent', 'Use native Grep for Claude Code'],
];
```

### UI Behavior

When a user tries to enable a conflicting tool:

1. Show warning: "This tool conflicts with [other tool]"
2. Offer resolution: "Would you like to disable [other tool]?"
3. Prevent enabling both simultaneously

---

## Tool Lifecycle

### PocketDev Tools (PHP Classes)

1. **Definition**: Create PHP class in `app/Tools/` extending `Tool`
2. **Discovery**: Auto-discovered by `ToolRegistry` via Symfony Finder
3. **Registration**: No manual registration needed - just create the file
4. **Execution**:
   - API Providers: Direct PHP execution via `Tool::execute()`
   - CLI Providers: Via artisan commands (injected in system prompt)

```php
// Example: app/Tools/MyTool.php
class MyTool extends Tool
{
    public string $name = 'MyTool';
    public string $category = 'custom';

    public function execute(array $input, ExecutionContext $ctx): ToolResult
    {
        // Implementation
    }

    public function getArtisanCommand(): ?string
    {
        return 'my:tool';  // For CLI providers
    }
}
```

### User Tools (Database)

1. **Creation**: Via `tool:create` command or AI
2. **Storage**: Script stored in `pocket_tools.script`
3. **Wrapping**: `UserTool` class wraps `PocketTool` model
4. **Execution**: Via `tool:run <slug>` (writes to temp file, executes)
5. **Modification**: Via `tool:update` command
6. **Deletion**: Via `tool:delete` command

---

## Implementation Files

### Core Files

| File | Purpose |
|------|---------|
| `app/Enums/Provider.php` | Single source of truth for provider identifiers |
| `app/Tools/Tool.php` | Base class for all tools |
| `app/Tools/UserTool.php` | Wrapper for database-stored user tools |
| `app/Tools/*Tool.php` | Individual tool implementations |
| `app/Services/ToolRegistry.php` | Auto-discovers and registers Tool classes |
| `app/Services/ToolSelector.php` | Provider-aware tool filtering |
| `app/Models/PocketTool.php` | Eloquent model for user tools |
| `app/Models/ToolConflict.php` | Eloquent model for conflicts |
| `app/Providers/AIServiceProvider.php` | Wires up tool discovery |

---

## Visual Summary

```text
┌─────────────────────────────────────────────────────────────────────┐
│                         TOOL SELECTION FLOW                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│   CLI Providers                        API Providers                 │
│   (Claude Code, Codex)                 (Anthropic, OpenAI, etc.)    │
│   ┌─────────────────────┐              ┌─────────────────────┐      │
│   │ Native Tools        │              │ PocketDev file_ops  │      │
│   │ ├── Bash           │              │ ├── Bash            │      │
│   │ ├── Read           │              │ ├── Read            │      │
│   │ ├── Edit           │              │ ├── Edit            │      │
│   │ ├── Glob           │              │ ├── Glob            │      │
│   │ ├── Grep           │              │ ├── Grep            │      │
│   │ └── ...            │              │ └── ...             │      │
│   └─────────────────────┘              └─────────────────────┘      │
│            +                                    +                    │
│   ┌─────────────────────┐              ┌─────────────────────┐      │
│   │ PocketDev Tools     │              │ PocketDev Tools     │      │
│   │ ├── Memory Tools    │              │ ├── Memory Tools    │      │
│   │ ├── Tool Mgmt       │              │ ├── Tool Mgmt       │      │
│   │ └── User Tools      │              │ └── User Tools      │      │
│   └─────────────────────┘              └─────────────────────┘      │
│                                                                      │
│   Injected via:                        Registered as:               │
│   --append-system-prompt               Function definitions         │
│   (artisan commands)                   (direct tool calls)          │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

### Category-Based Filtering

```text
Provider::isCliProvider() === true?
    │
    ├── YES (Claude Code, Codex)
    │   └── Exclude category === 'file_ops'
    │       → Return: memory, tools, custom
    │
    └── NO (Anthropic, OpenAI, OpenAI Compatible)
        └── Include all categories
            → Return: memory, tools, file_ops, custom
```

---

## Future Considerations

### Per-User Tool Settings

Currently, tool settings are global. Future enhancement could add:

```sql
CREATE TABLE user_tool_settings (
    user_id UUID REFERENCES users(id),
    tool_slug VARCHAR(255),
    enabled BOOLEAN,
    PRIMARY KEY (user_id, tool_slug)
);
```

### Per-Conversation Tool Settings

Allow different tools per conversation:

```sql
ALTER TABLE conversations ADD COLUMN enabled_tools JSON;
```

### Tool Versioning

Track changes to user tools:

```sql
CREATE TABLE tool_versions (
    id UUID PRIMARY KEY,
    tool_id UUID REFERENCES pocket_tools(id),
    version INTEGER,
    script TEXT,
    system_prompt TEXT,
    created_at TIMESTAMP
);
```
