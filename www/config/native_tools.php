<?php

/**
 * Native Tools Configuration
 *
 * This file defines the native tools available in Claude Code and Codex.
 * These are built-in tools that the AI providers offer natively.
 *
 * Native PocketDev tools (file ops) are stored in the database as PocketTool
 * records with source='pocketdev' and serve as fallbacks for providers
 * that don't have native equivalents (e.g., Anthropic API, OpenAI API).
 *
 * To refresh this list, query the CLIs:
 * - Claude: claude --tools "default" --print --output-format json "List all your available tools..."
 * - Codex: codex exec --skip-git-repo-check "List all your available tools..."
 */

return [
    'claude_code' => [
        'version' => '1.0.37',
        'refreshed_at' => '2025-12-22',
        'tools' => [
            [
                'name' => 'Bash',
                'description' => 'Execute shell commands in the terminal',
                'enabled' => true,
            ],
            [
                'name' => 'Read',
                'description' => 'Read file contents from the filesystem',
                'enabled' => true,
            ],
            [
                'name' => 'Write',
                'description' => 'Write or create files on the filesystem',
                'enabled' => true,
            ],
            [
                'name' => 'Edit',
                'description' => 'Edit files with search and replace operations',
                'enabled' => true,
            ],
            [
                'name' => 'Glob',
                'description' => 'Find files matching a glob pattern',
                'enabled' => true,
            ],
            [
                'name' => 'Grep',
                'description' => 'Search file contents using regular expressions',
                'enabled' => true,
            ],
            [
                'name' => 'WebFetch',
                'description' => 'Fetch content from a URL',
                'enabled' => true,
            ],
            [
                'name' => 'WebSearch',
                'description' => 'Search the web for information',
                'enabled' => true,
            ],
            [
                'name' => 'Task',
                'description' => 'Launch a sub-agent to handle complex tasks',
                'enabled' => true,
            ],
            [
                'name' => 'TaskOutput',
                'description' => 'Retrieve output from a running or completed task',
                'enabled' => true,
            ],
            [
                'name' => 'TodoWrite',
                'description' => 'Create and manage a structured task list',
                'enabled' => true,
            ],
            [
                'name' => 'NotebookEdit',
                'description' => 'Edit Jupyter notebook cells',
                'enabled' => false, // Not commonly used in PocketDev
            ],
            [
                'name' => 'KillShell',
                'description' => 'Kill a running background shell',
                'enabled' => true,
            ],
            [
                'name' => 'AskUserQuestion',
                'description' => 'Ask the user a question during execution',
                'enabled' => false, // PocketDev has its own interaction model
            ],
            [
                'name' => 'Skill',
                'description' => 'Execute a skill within the conversation',
                'enabled' => true,
            ],
            [
                'name' => 'EnterPlanMode',
                'description' => 'Enter planning mode for complex tasks',
                'enabled' => true,
            ],
            [
                'name' => 'ExitPlanMode',
                'description' => 'Exit planning mode after writing a plan',
                'enabled' => true,
            ],
            [
                'name' => 'LSP',
                'description' => 'Interact with Language Server Protocol for code intelligence',
                'enabled' => true,
            ],
        ],
    ],

    'codex' => [
        'version' => '0.77.0',
        'refreshed_at' => '2025-12-22',
        'tools' => [
            [
                'name' => 'shell_command',
                'description' => 'Execute shell commands',
                'enabled' => true,
            ],
            [
                'name' => 'apply_patch',
                'description' => 'Apply file patches/diffs',
                'enabled' => true,
            ],
            [
                'name' => 'view_image',
                'description' => 'View image files',
                'enabled' => true,
            ],
            [
                'name' => 'update_plan',
                'description' => 'Update the execution plan',
                'enabled' => true,
            ],
            [
                'name' => 'list_mcp_resources',
                'description' => 'List available MCP resources',
                'enabled' => true,
            ],
            [
                'name' => 'list_mcp_resource_templates',
                'description' => 'List MCP resource templates',
                'enabled' => true,
            ],
            [
                'name' => 'read_mcp_resource',
                'description' => 'Read content from an MCP resource',
                'enabled' => true,
            ],
            [
                'name' => 'multi_tool_use.parallel',
                'description' => 'Execute multiple tools in parallel',
                'enabled' => true,
            ],
        ],
    ],
];
