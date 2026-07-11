# Tool Groups Implementation Plan

## Overview

Implement a config-based tool grouping system that:
1. Reduces token usage by sharing common instructions at group level
2. Provides fallback prompts when groups are disabled
3. Uses existing `category` column - no schema changes needed

## Design Decision: Config + Existing Category

**Why this approach?**
- No database migration needed - use existing `category` column
- Groups defined in config for easy iteration
- Categories remain distinct for filtering (memory_schema vs memory_data)
- Config can map multiple categories to one group prompt

## Data Model

### Config File: `config/tool-groups.php`

```php
return [
    'memory' => [
        'name' => 'Memory System',
        'sort_order' => 10,
        'categories' => ['memory_schema', 'memory_data'],  // Maps multiple categories
        'system_prompt_active' => <<<'PROMPT'
## Memory System

PocketDev provides a PostgreSQL-based memory system for persistent storage.

**Schema naming**: Tables use `{schema}.tablename` format (e.g., `memory_default.characters`)

**System tables** (per schema):
- `schema_registry` - table metadata and embed field config
- `embeddings` - vector storage for semantic search (never SELECT embedding column directly)
PROMPT,
        'system_prompt_inactive' => <<<'PROMPT'
Memory tools are disabled. Ask the user to enable them if you need persistent storage.
PROMPT,
    ],

    'tool-management' => [
        'name' => 'Tool Management',
        'sort_order' => 20,
        'categories' => ['tools'],
        'system_prompt_active' => null,
        'system_prompt_inactive' => null,
    ],

    'system' => [
        'name' => 'System',
        'sort_order' => 30,
        'categories' => ['system'],
        'system_prompt_active' => null,
        'system_prompt_inactive' => null,
    ],

    'conversation' => [
        'name' => 'Conversation History',
        'sort_order' => 40,
        'categories' => ['conversation'],
        'system_prompt_active' => null,
        'system_prompt_inactive' => null,
    ],
];
```

### No Database Changes

The existing `category` column already has the values we need:
- `memory_schema` - schema tools (CreateTable, Execute, ListTables)
- `memory_data` - data tools (Query, Insert, Update, Delete)
- `tools` - tool management
- `system` - system package
- `conversation` - conversation search
- `custom` - user-created tools (ungrouped)

## Category to Group Mapping

| Category | Group | Tools |
|----------|-------|-------|
| `memory_schema` | `memory` | CreateTable, Execute, ListTables |
| `memory_data` | `memory` | Query, Insert, Update, Delete |
| `tools` | `tool-management` | Create, Update, Delete, List, Show, Run |
| `system` | `system` | SystemPackage |
| `conversation` | `conversation` | Search, GetTurns |
| `custom` | (ungrouped) | User-created tools |

## System Prompt Generation Flow

```
# PocketDev Tools
[CLI invocation guide if applicable]

For each group (sorted by sort_order):
    group_categories = group.categories
    enabled_tools = tools where category IN group_categories AND enabled

    if enabled_tools.count > 0:
        if group.system_prompt_active:
            output group.system_prompt_active

        # Sub-group by category within the group
        for each category in group_categories:
            category_tools = enabled_tools.where(category)
            if category_tools.isNotEmpty():
                output "### {category_display_name}"
                for each tool:
                    output tool prompt
    else if group.system_prompt_inactive:
        output group.system_prompt_inactive

# Handle ungrouped tools (category = 'custom' or not in any group)
ungrouped = enabled tools not in any group
if ungrouped.count > 0:
    output "## Custom Tools"
    for each tool:
        output tool prompt
```

## Implementation Phases

### Phase 1: Config File

Create `config/tool-groups.php` with group definitions.

**Memory Group Active Prompt** (~80 tokens):
```markdown
## Memory System

PocketDev provides a PostgreSQL-based memory system for persistent storage.

**Schema naming**: Tables use `{schema}.tablename` format (e.g., `memory_default.characters`)

**System tables** (per schema):
- `schema_registry` - table metadata and embed field config
- `embeddings` - vector storage for semantic search (never SELECT embedding column directly)
```

**Memory Group Inactive Prompt** (~20 tokens):
```markdown
Memory tools are disabled. Ask the user to enable them if you need persistent storage.
```

### Phase 2: Refactor ToolSelector

Update `buildSystemPrompt()` to use group-based generation:

```php
public function buildSystemPrompt(...): string
{
    $sections = [];
    $sections[] = "# PocketDev Tools\n";

    if ($isCliProvider) {
        $sections[] = $this->buildInvocationGuide();
    }

    $groups = collect(config('tool-groups'))->sortBy('sort_order');
    $enabledTools = $this->getEnabledTools($workspace);

    foreach ($groups as $slug => $group) {
        $groupCategories = $group['categories'] ?? [];
        $groupTools = $enabledTools->filter(
            fn($t) => in_array($t->category, $groupCategories)
        );

        if ($groupTools->isNotEmpty()) {
            // Output group intro if defined
            if ($group['system_prompt_active'] ?? null) {
                $sections[] = $group['system_prompt_active'];
            }

            // Output tools, sub-grouped by category
            foreach ($groupCategories as $category) {
                $categoryTools = $groupTools->where('category', $category);
                if ($categoryTools->isNotEmpty()) {
                    $sections[] = $this->formatCategoryTools($category, $categoryTools, $isCliProvider);
                }
            }
        } elseif ($group['system_prompt_inactive'] ?? null) {
            $sections[] = $group['system_prompt_inactive'];
        }
    }

    // Ungrouped tools (custom category or not in any group)
    $allGroupedCategories = collect($groups)->flatMap(fn($g) => $g['categories'] ?? [])->all();
    $ungrouped = $enabledTools->filter(
        fn($t) => !in_array($t->category, $allGroupedCategories)
    );

    if ($ungrouped->isNotEmpty()) {
        $sections[] = "## Custom Tools\n";
        $sections[] = $this->formatGroupTools($ungrouped, $isCliProvider);
    }

    return implode("\n", $sections);
}
```

### Phase 3: Simplify Tool Prompts

Now that shared concepts are in the group intro, simplify individual tool prompts:

**Before (in each tool):**
> Memory schema short name (e.g., "default", "my_campaign"). Required - check your available schemas in the system prompt.

**After:**
> Schema name (e.g., "default"). See available schemas above.

### Phase 4: Cleanup

1. Remove dead `getCategoryInstructions()` method
2. Test prompt generation

## File Changes Summary

| File | Change |
|------|--------|
| `config/tool-groups.php` | **New file** - group definitions |
| `app/Services/ToolSelector.php` | Refactor `buildSystemPrompt()` |
| `app/Tools/Memory*.php` | Simplify `--schema` param descriptions |

## Token Savings Estimate

**Group intro**: ~80 tokens (once, instead of concepts scattered across 7 tools)

**Simplified param descriptions**: ~15 tokens × 7 tools = ~105 tokens saved

**Total estimated savings**: ~150-200 tokens per conversation with memory enabled

More modest than originally estimated, but:
- Zero database changes
- Cleaner architecture
- Easy to iterate on group prompts

## Testing Checklist

- [ ] Config file loads correctly
- [ ] System prompt shows group header + shared intro
- [ ] Tools still grouped by category within groups
- [ ] Disabled groups show inactive prompt
- [ ] Ungrouped tools (custom) appear under "Custom Tools"
- [ ] Existing functionality unchanged

## Future Enhancements

1. **User-created groups**: Add `tool_groups` table for user groups, merge with config
2. **Per-tool group override**: Add `tool_group` column to override category-based grouping
3. **UI for group management**: Settings page to edit group prompts
