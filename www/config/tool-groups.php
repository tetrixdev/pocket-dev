<?php

use App\Models\PocketTool;

/**
 * Tool Groups Configuration
 *
 * Groups allow shared instructions to be injected once for related tools,
 * reducing token usage and providing consistent context.
 *
 * Each group maps one or more categories to a shared prompt.
 * Tools are still organized by category within groups.
 */
return [
    'memory' => [
        'name' => 'Memory System',
        'sort_order' => 10,
        'categories' => [PocketTool::CATEGORY_MEMORY_SCHEMA, PocketTool::CATEGORY_MEMORY_DATA],
        'category_labels' => [
            PocketTool::CATEGORY_MEMORY_SCHEMA => 'Schema Management',
            PocketTool::CATEGORY_MEMORY_DATA => 'Data Operations',
        ],
        'system_prompt_active' => <<<'PROMPT'
## Memory System

PocketDev provides a PostgreSQL-based memory system for persistent storage.

**Schema naming**: Tables use `{schema}.tablename` format (e.g., `memory_default.characters`)

**System tables** (per schema):
- `schema_registry` - table metadata and embed field config
- `embeddings` - vector storage for semantic search (never SELECT the embedding column directly)

**Extensions available**: PostGIS (spatial), pg_trgm (fuzzy text search)
PROMPT,
        'system_prompt_inactive' => <<<'PROMPT'
Memory tools are disabled. Ask the user to enable them if you need persistent storage.
PROMPT,
    ],

    'tool-management' => [
        'name' => 'Tool Management',
        'sort_order' => 20,
        'categories' => [PocketTool::CATEGORY_TOOLS],
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
