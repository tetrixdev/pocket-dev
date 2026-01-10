<?php

use App\Models\PocketTool;

/**
 * Tool Groups Configuration
 *
 * Groups allow shared instructions to be injected once for related tools,
 * reducing token usage and providing consistent context.
 *
 * - system_prompt_active: Injected when ANY tool in this group is enabled
 * - system_prompt_inactive: Injected when NO tools in this group are enabled
 */
return [
    'memory' => [
        'name' => 'Memory Tools',
        'sort_order' => 10,
        'categories' => [PocketTool::CATEGORY_MEMORY_SCHEMA, PocketTool::CATEGORY_MEMORY_DATA],
        // Note: Memory schema info is now in a separate "Memory" section
        // These are just the tools for working with memory
        'system_prompt_active' => 'All memory tools require the `--schema` parameter. Always read before updating text fields to avoid data loss.',
        'system_prompt_inactive' => 'Memory tools are disabled. Ask the user to enable them if you need persistent storage.',
    ],

    'tool-management' => [
        'name' => 'Tool Management',
        'sort_order' => 20,
        'categories' => [PocketTool::CATEGORY_TOOLS],
        'system_prompt_active' => null,
        'system_prompt_inactive' => null,
    ],
];
