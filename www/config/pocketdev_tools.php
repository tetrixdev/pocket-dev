<?php

/**
 * PocketDev Tools Configuration
 *
 * These are PocketDev's built-in tools that extend AI capabilities.
 * They are loaded from config (not seeders) for easier updates.
 *
 * Structure:
 * - native_equivalent: Tools that overlap with Claude Code/Codex (used as fallback)
 * - unique: Tools that are PocketDev-specific (memory, tool management)
 */

return [
    // Tools with native equivalents (fallback for Anthropic/OpenAI API)
    'native_equivalent' => [
        [
            'slug' => 'pocketdev-bash',
            'name' => 'Bash',
            'description' => 'Execute shell commands',
            'category' => 'file_ops',
            'native_equivalent' => 'Bash',
        ],
        [
            'slug' => 'pocketdev-read',
            'name' => 'Read',
            'description' => 'Read file contents',
            'category' => 'file_ops',
            'native_equivalent' => 'Read',
        ],
        [
            'slug' => 'pocketdev-write',
            'name' => 'Write',
            'description' => 'Write or create files',
            'category' => 'file_ops',
            'native_equivalent' => 'Write',
        ],
        [
            'slug' => 'pocketdev-edit',
            'name' => 'Edit',
            'description' => 'Edit files with search and replace',
            'category' => 'file_ops',
            'native_equivalent' => 'Edit',
        ],
        [
            'slug' => 'pocketdev-glob',
            'name' => 'Glob',
            'description' => 'Find files matching a pattern',
            'category' => 'file_ops',
            'native_equivalent' => 'Glob',
        ],
        [
            'slug' => 'pocketdev-grep',
            'name' => 'Grep',
            'description' => 'Search file contents with regex',
            'category' => 'file_ops',
            'native_equivalent' => 'Grep',
        ],
    ],

    // PocketDev-unique tools (no native equivalent)
    'unique' => [
        // Memory Structure Tools
        [
            'slug' => 'memory-structure-create',
            'name' => 'Memory Structure Create',
            'description' => 'Create a new memory structure (schema/template)',
            'category' => 'memory',
            'artisan_command' => 'memory:structure:create',
        ],
        [
            'slug' => 'memory-structure-get',
            'name' => 'Memory Structure Get',
            'description' => 'Get a memory structure by slug',
            'category' => 'memory',
            'artisan_command' => 'memory:structure:get',
        ],
        [
            'slug' => 'memory-structure-update',
            'name' => 'Memory Structure Update',
            'description' => 'Update an existing memory structure',
            'category' => 'memory',
            'artisan_command' => 'memory:structure:update',
        ],
        [
            'slug' => 'memory-structure-delete',
            'name' => 'Memory Structure Delete',
            'description' => 'Delete a memory structure',
            'category' => 'memory',
            'artisan_command' => 'memory:structure:delete',
        ],
        // Memory Object Tools
        [
            'slug' => 'memory-create',
            'name' => 'Memory Create',
            'description' => 'Create a new memory object',
            'category' => 'memory',
            'artisan_command' => 'memory:create',
        ],
        [
            'slug' => 'memory-query',
            'name' => 'Memory Query',
            'description' => 'Search and retrieve memory objects',
            'category' => 'memory',
            'artisan_command' => 'memory:query',
        ],
        [
            'slug' => 'memory-update',
            'name' => 'Memory Update',
            'description' => 'Update an existing memory object',
            'category' => 'memory',
            'artisan_command' => 'memory:update',
        ],
        [
            'slug' => 'memory-delete',
            'name' => 'Memory Delete',
            'description' => 'Delete a memory object',
            'category' => 'memory',
            'artisan_command' => 'memory:delete',
        ],
        // Tool Management Tools
        [
            'slug' => 'tool-create',
            'name' => 'Tool Create',
            'description' => 'Create a custom tool',
            'category' => 'tools',
            'artisan_command' => 'tool:create',
        ],
        [
            'slug' => 'tool-update',
            'name' => 'Tool Update',
            'description' => 'Update a custom tool',
            'category' => 'tools',
            'artisan_command' => 'tool:update',
        ],
        [
            'slug' => 'tool-delete',
            'name' => 'Tool Delete',
            'description' => 'Delete a custom tool',
            'category' => 'tools',
            'artisan_command' => 'tool:delete',
        ],
        [
            'slug' => 'tool-list',
            'name' => 'Tool List',
            'description' => 'List all available tools',
            'category' => 'tools',
            'artisan_command' => 'tool:list',
        ],
        [
            'slug' => 'tool-show',
            'name' => 'Tool Show',
            'description' => 'Show details of a tool',
            'category' => 'tools',
            'artisan_command' => 'tool:show',
        ],
        [
            'slug' => 'tool-run',
            'name' => 'Tool Run',
            'description' => 'Execute a custom tool',
            'category' => 'tools',
            'artisan_command' => 'tool:run',
        ],
    ],
];
