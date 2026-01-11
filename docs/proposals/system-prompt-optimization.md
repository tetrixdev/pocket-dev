# System Prompt Optimization Proposal

## The Core Question

> "What should the AI write for itself in the future AI prompt?"

This is the fundamental question. The system prompt has two audiences:
1. **Current AI** - What it needs to do its job now
2. **Future AI** - What the current AI writes that becomes part of future prompts

The most critical optimization isn't reducing tokens—it's ensuring the AI writes **high-quality, self-documenting content** that future AI instances can use effectively.

---

## Current Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     SYSTEM PROMPT ASSEMBLY                       │
├─────────────────────────────────────────────────────────────────┤
│  SystemPromptBuilder.build() / buildForCliProvider()            │
│                                                                  │
│  1. Core Prompt ─────────────────── resources/defaults/system-prompt.md
│  2. Tool Instructions ───────────── ToolRegistry.getInstructions()
│     ├─ Built-in Tools ───────────── app/Tools/*.php ($instructions)
│     └─ User Tools ───────────────── pocket_tools.system_prompt (DB)
│  3. Memory Section ──────────────── ToolSelector.buildMemorySection()
│     ├─ Schema Info ──────────────── MemorySchemaService.listTables()
│     └─ Table Descriptions ───────── schema_registry.description (DB)
│  4. Additional Prompt ───────────── storage/pocketdev/additional-system-prompt.md
│  5. Agent Instructions ──────────── agents.system_prompt (DB)
│  6. Working Directory ───────────── Dynamic
│  7. Environment ─────────────────── credentials, packages
└─────────────────────────────────────────────────────────────────┘
```

### What AI Writes → Future Prompts

| AI Creates | Storage | Appears In Prompt As |
|------------|---------|----------------------|
| Tool (tool:create) | `pocket_tools.system_prompt` | Tool instructions section |
| Memory Table (memory:schema:create-table) | `schema_registry.description` | Memory section |
| Column Descriptions | PostgreSQL COMMENT | Memory section (column list) |

**Key Insight**: The `system_prompt` field for tools and `description` field for tables ARE the documentation. There's no separate docs—what the AI writes IS what future AI reads.

---

## Identified Issues

### Issue 1: Duplicated Guidance Across Tools

**The Problem**: The same explanations appear in 7+ tools:

```
Memory Tools with duplicated content:
├─ MemoryQueryTool       ─ schema param, system tables, embed search
├─ MemoryInsertTool      ─ schema param, auto-embedding, embed_fields
├─ MemoryUpdateTool      ─ schema param, auto-embedding, read-before-write
├─ MemoryDeleteTool      ─ schema param
├─ MemorySchemaCreateTableTool ─ schema param, embed_fields, column descriptions
├─ MemorySchemaExecuteTool     ─ schema param
└─ MemorySchemaListTablesTool  ─ schema param
```

**Repeated Content**:
1. `--schema` parameter is required (7 tools)
2. System tables explanation (3 places)
3. embed_fields behavior (3 tools)
4. Schema naming convention (5+ places)

**Token Waste**: ~500-800 tokens of repetition

### Issue 2: Hardcoded Memory Intro

**Location**: `ToolSelector.php` lines 536-542

```php
$introContent = "# Memory\n\n";
$introContent .= "PocketDev provides a PostgreSQL-based memory system...";
// etc.
```

**Also appears in**:
- Individual tool instructions
- docs/memory-system.md
- config/tool-groups.php

### Issue 3: Tool Groups Config Underutilized

**Current** (`config/tool-groups.php`):
```php
'memory' => [
    'system_prompt_active' => 'All memory tools require the `--schema` parameter...',
]
```

**This is only 1 line!** This could hold all the shared memory guidance.

### Issue 4: "Meta-Instructions" Missing

When AI creates a tool or table, it needs guidance on:
- What makes a good `system_prompt` for a tool
- What makes a good `description` for a table
- How to write for future AI consumption

**Currently**: This is embedded deep in `MemorySchemaCreateTableTool.instructions` (~50 lines about table descriptions).

**Should be**: Elevated to a core concept, applicable to both tools and memory structures.

---

## Proposal 1: Centralized Memory Guidance

### Current State
Each tool has its own copy of memory guidance in `$instructions`.

### Proposed State
Move shared guidance to `config/tool-groups.php`:

```php
'memory' => [
    'name' => 'Memory Tools',
    'system_prompt_active' => <<<'PROMPT'
## Memory System Overview

**Schema Parameter**: All memory tools require `--schema=<name>`. Use the short name from available schemas (e.g., "default", not "memory_default").

**Table Naming**: Tables use `{schema}.tablename` format in SQL (e.g., `memory_default.characters`).

**System Tables** (per schema):
- `schema_registry` - Table metadata and embeddable_fields
- `embeddings` - Vector storage for semantic search

**Auto-Embedding**: Fields listed in `embed_fields` are automatically embedded on insert/update. You don't need to manually generate embeddings.

**Extensions Available**:
- `pg_trgm` - Fuzzy text search (use `name % 'Gandolf'`)
- PostGIS - Spatial queries
PROMPT,
],
```

Then **trim** individual tool instructions to only tool-specific content.

**Estimated savings**: 400-600 tokens
**Benefit**: Single source of truth, easier maintenance

---

## Proposal 2: Meta-Instructions for AI-Written Content

### The Problem
When AI creates a tool (`tool:create`) or table (`memory:schema:create-table`), it writes content that becomes part of the future system prompt. But guidance on HOW to write this is:
- Buried in individual tool instructions
- Only for tables, not tools
- Verbose and example-heavy

### Proposed Solution
Add a "Meta-Instructions" section to the core prompt or as a dedicated section:

```markdown
# Writing for Future AI

When you create tools or memory structures, you're writing documentation that future AI instances will read. Quality here compounds—good docs enable good future work.

## Tool System Prompts

When creating a tool with `tool:create --system_prompt=...`:

1. **State what it does** (1-2 sentences)
2. **Show example invocation** with realistic parameters
3. **Describe parameters** - what each means, valid values
4. **Note any important behaviors** - side effects, errors, etc.

Bad: "This tool does stuff with files"
Good: "Converts DOCX files to markdown. Extracts images to a media/ subdirectory.

## CLI Example
php artisan tool:run docx-convert -- --input=/path/to/doc.docx --output=/tmp/result.md

## Parameters
- input: Path to DOCX file (required)
- output: Output path for markdown (optional, defaults to stdout)"

## Memory Table Descriptions

When creating a table with `memory:schema:create-table --description=...`:

1. **Purpose** - What data, why it matters
2. **Typical queries** - How it's usually accessed
3. **Relationships** - Foreign keys, related tables
4. **Example insert** - Show typical data shape

The description IS the documentation. Column descriptions matter too—they tell future AI what each field means.

## The Test

Ask: "If I knew nothing about this codebase, would this description let me use this tool/table correctly?"
```

**Placement Options**:
1. Core system prompt (always visible)
2. Dedicated section after tools
3. Part of the Memory Tools group intro

---

## Proposal 3: Tool Instruction Structure Standard

### Current State
Tool instructions vary widely in structure and verbosity:
- Some have `## When to Use` sections
- Some have `## CLI Example` sections
- Some have `## Notes` sections
- No consistent format

### Proposed Standard

```markdown
[1-2 sentence description of what this tool does]

## When to Use
- [Scenario 1]
- [Scenario 2]

## Parameters
- param1: Description [type] *(required)*
- param2: Description [type]

## CLI Example
```bash
php artisan [command]
```

## Notes
- [Important behavior 1]
- [Important behavior 2]
```

**Benefits**:
- Predictable structure helps AI parse quickly
- Easy to template for new tools
- Consistent user experience

---

## Proposal 4: Hierarchy Optimization

### Current Token Distribution (Estimated)

| Section | ~Tokens | Notes |
|---------|---------|-------|
| Core Prompt | ~300 | Minimal, good |
| Tool Instructions | ~3000 | 24 tools, many verbose |
| Memory Section | ~2000 | Depends on tables |
| Additional Prompt | ~500 | User-controlled |
| Agent + Context | ~200 | Dynamic |
| **Total** | **~6000** | |

### Optimization Targets

1. **Tool Instructions**: Reduce by 500-800 tokens via centralization
2. **Memory Section**: Already dynamic, but intro could be trimmed
3. **Don't touch**: Core prompt, working directory, environment (already minimal)

---

## Proposal 5: Self-Referential Documentation

### The Insight
The system prompt contains instructions for tools that CREATE parts of the system prompt. This is inherently self-referential.

### Current Flow
```
AI reads: tool:create instructions
AI writes: pocket_tools.system_prompt
Future AI reads: that system_prompt as part of prompt
```

### Optimization
Make this explicit. The `tool:create` instructions should reference that:
- What you write becomes part of future system prompts
- Write for AI consumption, not human developers
- Be concise but complete

Similarly for `memory:schema:create-table`:
- The description you write appears in future prompts
- Column descriptions appear in future prompts
- This IS the documentation

---

## Proposal 6: Lazy Loading for Verbose Docs

### The Problem
Some tools have extensive examples and edge cases that are rarely needed. Loading all of it every time wastes tokens.

### Possible Solutions

**Option A: Tiered Instructions**
```php
class Tool {
    public ?string $instructions;        // Always included (~5-10 lines)
    public ?string $extendedInstructions; // Only on request
}
```

**Option B: Reference to Docs**
```markdown
## MemorySchemaCreateTable

Creates new memory tables. See `docs/memory-schema.md` for detailed column types and examples.

## Parameters
...
```

**Option C: Status Quo**
Accept the token cost for comprehensive in-prompt documentation.

**Recommendation**: Start with Option B for the most verbose tools, measure impact.

---

## Priority Matrix

| Proposal | Effort | Impact | Priority |
|----------|--------|--------|----------|
| 1. Centralized Memory Guidance | Medium | High | **1** |
| 2. Meta-Instructions | Low | High | **2** |
| 3. Tool Structure Standard | Medium | Medium | 3 |
| 4. Token Trimming | Low | Medium | 4 |
| 5. Self-Referential Docs | Low | Medium | 4 |
| 6. Lazy Loading | High | Low | 5 |

---

## Questions to Resolve

1. **Where should meta-instructions live?**
   - Core prompt? (Always visible, but makes core longer)
   - Tool groups config? (Contextual, but scattered)
   - Dedicated section? (Clean, but another section)

2. **How aggressive with trimming?**
   - Some verbose explanations ARE useful (e.g., embed_fields behavior)
   - Risk: Too terse = AI makes mistakes
   - Test: Does AI still use tools correctly after trimming?

3. **Should we template tool instructions?**
   - Pro: Consistency, easier maintenance
   - Con: Less flexibility for unique tools

4. **Memory table descriptions—how much guidance is enough?**
   - Current: Extensive (~100 lines in MemorySchemaCreateTableTool)
   - Could this be trimmed to 20 lines + examples?

---

## Proposal 7: Restructured Tool Groups

### Current State

```php
// config/tool-groups.php
'memory' => [
    'categories' => [CATEGORY_MEMORY_SCHEMA, CATEGORY_MEMORY_DATA],
    // Lumps together: schema tools, data tools, AND conversation tools
]
```

**Problems**:
1. Conversation tools (search, get-turns) are under "memory" but they're conceptually different
2. Schema tools (structural changes) and data tools (CRUD) have different use patterns
3. One big group = one big shared prompt, even when only using data tools

### Proposed Grouping

```
├─ Memory Schema
│   ├─ memory:schema:create-table
│   ├─ memory:schema:execute
│   └─ memory:schema:list-tables
│   └─ (shared prompt: schema design guidance, column types, embed_fields)
│
├─ Memory Data
│   ├─ memory:query
│   ├─ memory:insert
│   ├─ memory:update
│   └─ memory:delete
│   └─ (shared prompt: --schema required, auto-embedding, read-before-write)
│
├─ Conversation History
│   ├─ conversation:search
│   └─ conversation:get-turns
│   └─ (shared prompt: semantic search guidance, how to use results)
│
└─ Tool Management
    ├─ tool:create, tool:list, tool:run, etc.
    └─ (shared prompt: meta-instructions for writing good tool system_prompts)
```

### Benefits

1. **Conversation tools get their own context** - guidance on semantic search, not database CRUD
2. **Schema vs Data separation** - structural changes vs routine operations
3. **Smaller shared prompts** - only load what's relevant to enabled tools
4. **Clearer mental model** - each group has a distinct purpose

### Implementation

```php
// config/tool-groups.php
return [
    'memory-schema' => [
        'name' => 'Memory Schema',
        'sort_order' => 10,
        'categories' => [PocketTool::CATEGORY_MEMORY_SCHEMA],
        'system_prompt_active' => <<<'PROMPT'
## Schema Design

When creating tables, the `description` you write becomes part of the system prompt for future AI.

**Column Types**: TEXT, INTEGER, BOOLEAN, UUID, TIMESTAMP, JSONB, TEXT[], GEOGRAPHY(Point, 4326)
**Embed Fields**: List text fields to auto-embed for semantic search
**Column Descriptions**: Brief (WHAT it is), detailed format guidance goes in table description

For detailed column types and PostGIS/pg_trgm usage, see the tool-specific documentation.
PROMPT,
    ],

    'memory-data' => [
        'name' => 'Memory Data',
        'sort_order' => 11,
        'categories' => [PocketTool::CATEGORY_MEMORY_DATA],
        'system_prompt_active' => <<<'PROMPT'
## Data Operations

**Schema Required**: Use `--schema=<name>` with short name (e.g., "default", not "memory_default")
**Table Names in SQL**: `{schema}.tablename` format (e.g., `memory_default.characters`)
**Auto-Embedding**: Fields in `embed_fields` are embedded automatically on insert/update
**Read-Before-Write**: ALWAYS read text fields before updating to avoid data loss

## TEXT Field Templates

For structured text fields (backstory, description, notes), use markdown templates:
- Follow the template shown in the table's example insert
- Use ## headings for sections
- Keep structure consistent across all rows
PROMPT,
    ],

    'conversation-history' => [
        'name' => 'Conversation History',
        'sort_order' => 15,
        'categories' => [PocketTool::CATEGORY_CONVERSATION],  // New category
        'system_prompt_active' => <<<'PROMPT'
## Searching Past Conversations

Use semantic search to find when topics were discussed. Pass natural language queries (sentences, not keywords).

**Good**: "How do we handle authentication in the API"
**Bad**: "auth API" (keywords lose semantic meaning)

Use `conversation:get-turns` after searching to retrieve full context for promising matches.
PROMPT,
    ],

    'tool-management' => [
        'name' => 'Tool Management',
        'sort_order' => 20,
        'categories' => [PocketTool::CATEGORY_TOOLS],
        'system_prompt_active' => <<<'PROMPT'
## Writing Tool System Prompts

When creating tools, the `system_prompt` you write becomes part of the system prompt for future AI.

**Required elements:**
1. What it does (1-2 sentences)
2. Example CLI invocation
3. Parameter descriptions

**The test:** "Would a future AI with no context know how to use this tool correctly?"
PROMPT,
    ],
];
```

---

## Proposal 8: Field Type Handling & Markdown Templates

### The Problem

TEXT fields in memory tables often contain structured content (backstory, description, goals). Without guidance:
- AI writes unstructured prose
- Format varies between inserts/updates
- Future AI struggles to parse or extend content

### The Solution: Split Guidance by Responsibility

| What | Where | Who Sees It |
|------|-------|-------------|
| **What the field IS** | Column description | Everyone (including read-only) |
| **How to format it** | Table description (example insert) | Everyone |
| **The rule to follow templates** | Insert/Update tool instructions | Only read-write agents |

### Example: Characters Table

**Column description** (brief, universal):
```
"backstory": "Character history and origin. Uses ## section headers."
```

**Table description** (includes template via example):
```markdown
## entities

All characters in the game world: PCs, NPCs, creatures.

**Typical queries:** Find by name, get entities at location
**Relationships:** entity_locations, entity_relationships, entity_factions

**Example insert:**
```json
{
  "name": "Kael Dunrow",
  "backstory": "## Origin\nBorn to farmers in the north.\n\n## Defining Moments\nFramed for an attack...\n\n## How They Got Here\nCame seeking the truth.",
  "personality": "## Demeanor\nGruff and direct.\n\n## Speech\nShort sentences.\n\n## Values\nLoyalty above all."
}
```

**Tool instruction** (Insert/Update only):
```markdown
## TEXT Field Templates

For structured text fields, follow the markdown template shown in the table's example insert:
- Use the same ## headings
- Keep sections in the same order
- Omit empty sections rather than writing "None" or "N/A"
```

### Why This Works

1. **Read-only agents** see the example in table description—enough to understand the data
2. **Read-write agents** also see the rule in tool instructions—know they must follow it
3. **No wasted tokens** on write instructions for readers
4. **Templates are shown, not just described**—concrete examples beat abstract rules

### Quality vs. Optimization Decision

**My recommendation: Prioritize quality. Accept some "waste" for read-only agents.**

Reasons:
1. At 24% context usage, we have headroom
2. Read-only agents benefit from seeing good examples (helps them understand data)
3. The "waste" is small—one example insert per table, not per column
4. Bad data quality compounds forever; extra tokens are temporary

**What NOT to do:**
- Don't put formatting rules in every column description (too verbose, read-only waste)
- Don't skip examples in table descriptions (readers lose context)
- Don't remove tool instructions for read-write (they need the explicit rule)

---

## Next Steps

1. **Review this proposal** - What resonates? What's missing?
2. **Pick starting point** - Proposal 7 (regrouping) or 8 (field templates)?
3. **Prototype** - Make changes to one area, test with real usage
4. **Measure** - Count tokens before/after, test AI behavior
5. **Iterate** - Refine based on results

---

## Appendix: File Locations

| Component | Path |
|-----------|------|
| System prompt assembly | `app/Services/SystemPromptBuilder.php` |
| Tool instructions (built-in) | `app/Tools/*.php` (each has `$instructions`) |
| Tool groups config | `config/tool-groups.php` |
| Memory section builder | `app/Services/ToolSelector.php` |
| Core prompt default | `resources/defaults/system-prompt.md` |
| Tool model (user tools) | `app/Models/PocketTool.php` |
