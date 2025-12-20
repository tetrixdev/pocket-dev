# PocketDev Tool System Architecture

## Overview

PocketDev supports multiple AI providers, each with different tool capabilities. This document describes the unified tool system that manages tool availability, selection, and conflict resolution across all providers.

## Provider Landscape

### Supported Providers

| Provider | Built-in Tools | PocketDev Tools | User Tools |
|----------|----------------|-----------------|------------|
| **Claude Code** | Yes (Bash, Read, Edit, Glob, Grep, WebSearch, etc.) | Yes (Memory, Tool mgmt) | Yes |
| **Anthropic API** | No | Yes (all) | Yes |
| **OpenAI API** | No | Yes (all) | Yes |

### The Challenge

Claude Code has its own built-in tools that overlap with PocketDev's tools:
- Both have `Bash` for executing shell commands
- Both have `Read` for reading files
- Both have `Edit` for editing files
- Both have `Glob` for finding files
- Both have `Grep` for searching file contents

We need a system that:
1. Prevents duplicate/conflicting tools from being enabled
2. Uses the right tools for each provider
3. Allows user customization with guardrails
4. Keeps Memory and Tool management tools universal

---

## Tool Classification

### Three Dimensions

```
Tool
├── Source (where it comes from)
│   ├── native      → Built into the provider (Claude Code's tools)
│   ├── pocketdev   → PocketDev's PHP Tool classes
│   └── user        → User-created bash script tools
│
├── Scope (which providers can use it)
│   ├── universal   → All providers (Memory, Tool management)
│   └── restricted  → Specific providers only
│
└── Capability (what it does - for conflict detection)
    ├── bash        → Execute shell commands
    ├── file_read   → Read file contents
    ├── file_write  → Create/write files
    ├── file_edit   → Modify existing files
    ├── file_glob   → Find files by pattern
    ├── file_grep   → Search file contents
    ├── web_fetch   → Fetch web content
    ├── web_search  → Search the web
    ├── memory      → Memory system operations
    ├── tool_mgmt   → Tool management operations
    └── custom      → User-defined capability
```

### Tool Categories

#### 1. Native Tools (Claude Code Only)

These are built into Claude Code and cannot be modified:

| Tool | Capability | Notes |
|------|------------|-------|
| Bash | `bash` | Execute shell commands |
| Read | `file_read` | Read files |
| Write | `file_write` | Write files |
| Edit | `file_edit` | Edit files |
| MultiEdit | `file_edit` | Multi-file edit |
| Glob | `file_glob` | Find files |
| Grep | `file_grep` | Search contents |
| LS | `file_read` | List directories |
| Task | `agent` | Spawn sub-agents |
| WebFetch | `web_fetch` | Fetch URLs |
| WebSearch | `web_search` | Search web |
| NotebookRead | `file_read` | Read notebooks |
| NotebookEdit | `file_edit` | Edit notebooks |

#### 2. PocketDev Core Tools (File Operations)

These are PHP Tool classes that provide functionality for Anthropic/OpenAI:

| Tool | Class | Capability | Excluded From |
|------|-------|------------|---------------|
| Bash | `BashTool` | `bash` | Claude Code |
| Read | `ReadTool` | `file_read` | Claude Code |
| Write | `WriteTool` | `file_write` | Claude Code |
| Edit | `EditTool` | `file_edit` | Claude Code |
| Glob | `GlobTool` | `file_glob` | Claude Code |
| Grep | `GrepTool` | `file_grep` | Claude Code |

#### 3. PocketDev Universal Tools (Memory)

These work with ALL providers including Claude Code:

| Tool | Artisan Command | Capability |
|------|-----------------|------------|
| Memory Create | `memory:create` | `memory` |
| Memory Query | `memory:query` | `memory` |
| Memory Update | `memory:update` | `memory` |
| Memory Delete | `memory:delete` | `memory` |
| Memory Link | `memory:link` | `memory` |
| Memory Unlink | `memory:unlink` | `memory` |

#### 4. PocketDev Universal Tools (Tool Management)

These work with ALL providers including Claude Code:

| Tool | Artisan Command | Capability |
|------|-----------------|------------|
| Tool Create | `tool:create` | `tool_mgmt` |
| Tool Update | `tool:update` | `tool_mgmt` |
| Tool Delete | `tool:delete` | `tool_mgmt` |
| Tool List | `tool:list` | `tool_mgmt` |
| Tool Show | `tool:show` | `tool_mgmt` |
| Tool Enable | `tool:enable` | `tool_mgmt` |
| Tool Disable | `tool:disable` | `tool_mgmt` |
| Tool Run | `tool:run` | `tool_mgmt` |

#### 5. User Tools

Custom tools created by users or AI:

| Attribute | Description |
|-----------|-------------|
| Source | `user` |
| Storage | Database (script in `script` column) |
| Execution | Via `tool:run <slug>` |
| Scope | Universal (all providers) |
| Capability | `custom` (or user-defined) |

---

## Database Schema

### Table: `pocket_tools`

Stores metadata for PocketDev and User tools:

```sql
CREATE TABLE pocket_tools (
    id UUID PRIMARY KEY,

    -- Identity
    slug VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,

    -- Classification
    source VARCHAR(50) NOT NULL DEFAULT 'user',  -- 'pocketdev' | 'user'
    category VARCHAR(100) DEFAULT 'custom',       -- 'memory' | 'tools' | 'file_ops' | 'custom'
    capability VARCHAR(100),                      -- 'bash' | 'file_read' | 'memory' | etc.

    -- Provider Compatibility
    excluded_providers JSON,  -- ['claude_code'] = not available for CC

    -- For conflict detection with native tools
    native_equivalent VARCHAR(100),  -- 'Bash' | 'Read' | etc. (Claude Code tool name)

    -- AI Instructions
    system_prompt TEXT NOT NULL,
    input_schema JSON,

    -- User tools only
    script TEXT,  -- Bash script content (null for pocketdev tools)

    -- State
    enabled BOOLEAN DEFAULT TRUE,

    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Indexes
CREATE INDEX idx_pocket_tools_source ON pocket_tools(source);
CREATE INDEX idx_pocket_tools_category ON pocket_tools(category);
CREATE INDEX idx_pocket_tools_capability ON pocket_tools(capability);
CREATE INDEX idx_pocket_tools_enabled ON pocket_tools(enabled);
```

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

### ToolSelector Service

```php
class ToolSelector
{
    /**
     * Get tools available for a provider.
     */
    public function getAvailableTools(string $provider): Collection
    {
        return PocketTool::query()
            ->enabled()
            ->where(function ($q) use ($provider) {
                // Exclude tools that explicitly exclude this provider
                $q->whereNull('excluded_providers')
                  ->orWhereJsonDoesntContain('excluded_providers', $provider);
            })
            ->get();
    }

    /**
     * Get default enabled tools for a provider (new conversations).
     */
    public function getDefaultTools(string $provider): Collection
    {
        $tools = $this->getAvailableTools($provider);

        // For Claude Code: exclude tools with native equivalents
        if ($provider === 'claude_code') {
            return $tools->filter(fn($t) => empty($t->native_equivalent));
        }

        return $tools;
    }

    /**
     * Check if two tools conflict.
     */
    public function getConflict(string $slugA, string $slugB): ?ToolConflict
    {
        return ToolConflict::where([
            ['tool_a_slug', $slugA],
            ['tool_b_slug', $slugB],
        ])->orWhere([
            ['tool_a_slug', $slugB],
            ['tool_b_slug', $slugA],
        ])->first();
    }
}
```

### Provider-Specific Behavior

#### Claude Code Provider

```php
// 1. Native tools are handled by Claude Code itself
// 2. We inject PocketDev tool instructions via --append-system-prompt
// 3. Only non-native-equivalent tools are injected

$pocketDevTools = $toolSelector->getDefaultTools('claude_code');
// Returns: Memory tools, Tool management tools, User tools
// Excludes: Bash, Read, Edit, Glob, Grep (have native equivalents)

$systemPrompt = $this->buildToolInstructions($pocketDevTools);
// Build --append-system-prompt with memory/tool instructions
```

#### Anthropic/OpenAI Providers

```php
// All enabled PocketDev tools are registered as native tools
$tools = $toolSelector->getAvailableTools('anthropic');
// Returns: Bash, Read, Edit, Glob, Grep, Memory tools, Tool mgmt, User tools

foreach ($tools as $tool) {
    $registry->register($this->toolFactory->create($tool));
}
```

---

## System Prompt Injection

### For Claude Code

When Claude Code is the provider, we inject tool instructions for PocketDev-exclusive tools:

```
# PocketDev Tools

## Memory System

Use the following artisan commands to manage persistent memory:

### memory:create
[system_prompt from pocket_tools table]

### memory:query
[system_prompt from pocket_tools table]

... (other memory tools)

## Tool Management

### tool:create
[system_prompt from pocket_tools table]

... (other tool management commands)

## Custom Tools

### my-custom-tool
[system_prompt from pocket_tools table]

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

### PocketDev Tools (Core)

1. **Definition**: PHP Tool classes in `app/Tools/`
2. **Registration**: Registered in `pocket_tools` table via seeder
3. **Execution**:
   - Anthropic/OpenAI: Direct PHP execution via `Tool::execute()`
   - Claude Code: Via artisan commands (injected in system prompt)

### User Tools

1. **Creation**: Via `tool:create` command
2. **Storage**: Script stored in `pocket_tools.script`
3. **Execution**: Via `tool:run <slug>` (writes to temp file, executes, returns output)
4. **Modification**: Via `tool:update` command
5. **Deletion**: Via `tool:delete` command

---

## Implementation Files

### New Files

| File | Purpose |
|------|---------|
| `app/Services/ToolSelector.php` | Provider-aware tool selection |
| `app/Services/ToolFactory.php` | Create Tool instances from PocketTool models |
| `app/Models/ToolConflict.php` | Eloquent model for conflicts |
| `database/migrations/*_add_provider_fields_to_pocket_tools.php` | Schema updates |
| `database/migrations/*_create_tool_conflicts_table.php` | Conflicts table |
| `database/seeders/ToolConflictSeeder.php` | Seed conflict definitions |

### Modified Files

| File | Changes |
|------|---------|
| `app/Models/PocketTool.php` | Add scopes, relationships |
| `app/Providers/AIServiceProvider.php` | Use ToolSelector |
| `app/Services/ToolRegistry.php` | Provider-aware registration |
| `app/Jobs/ProcessConversationStream.php` | Use ToolSelector |
| `database/seeders/PocketToolSeeder.php` | Add source/provider fields |

---

## Visual Summary

```
┌─────────────────────────────────────────────────────────────────────┐
│                         TOOL SELECTION FLOW                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│   Provider: Claude Code                Provider: Anthropic/OpenAI   │
│   ┌─────────────────────┐              ┌─────────────────────┐      │
│   │ Native Tools        │              │ PocketDev Tools     │      │
│   │ ├── Bash           │              │ ├── Bash            │      │
│   │ ├── Read           │              │ ├── Read            │      │
│   │ ├── Edit           │              │ ├── Edit            │      │
│   │ ├── Glob           │              │ ├── Glob            │      │
│   │ ├── Grep           │              │ ├── Grep            │      │
│   │ └── ...            │              │ └── ...             │      │
│   └─────────────────────┘              └─────────────────────┘      │
│            +                                    +                    │
│   ┌─────────────────────┐              ┌─────────────────────┐      │
│   │ PocketDev Universal │              │ PocketDev Universal │      │
│   │ ├── Memory Tools    │              │ ├── Memory Tools    │      │
│   │ └── Tool Mgmt       │              │ └── Tool Mgmt       │      │
│   └─────────────────────┘              └─────────────────────┘      │
│            +                                    +                    │
│   ┌─────────────────────┐              ┌─────────────────────┐      │
│   │ User Tools          │              │ User Tools          │      │
│   │ └── Custom scripts  │              │ └── Custom scripts  │      │
│   └─────────────────────┘              └─────────────────────┘      │
│                                                                      │
│   Injected via:                        Registered as:               │
│   --append-system-prompt               Native tool calls            │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
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
