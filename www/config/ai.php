<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default AI provider used for conversations.
    | Supported: "anthropic", "openai", "openai_compatible", "claude_code", "codex"
    |
    */

    'default_provider' => env('AI_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for each supported AI provider.
    |
    */

    'providers' => [
        'anthropic' => [
            // API key managed via UI (stored in database)
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'default_model' => 'claude-sonnet-4-5-20250929',
            'max_tokens' => 8192,
            'api_version' => '2023-06-01',
        ],

        'openai' => [
            // API key managed via UI (stored in database)
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'default_model' => 'gpt-5',
            'max_tokens' => 16384,
        ],

        'claude_code' => [
            // No API key needed - uses Claude Code CLI authentication (setup via `claude setup-token`)
            'default_model' => 'opus',
            // Available tools that can be enabled/disabled via UI
            // Empty array = all tools allowed (default behavior)
            'available_tools' => [
                'Read', 'Write', 'Edit', 'MultiEdit', 'Glob', 'Grep', 'LS',
                'Bash', 'Task', 'WebFetch', 'WebSearch', 'NotebookRead', 'NotebookEdit',
            ],
        ],

        'codex' => [
            // No API key needed - uses Codex CLI authentication (setup via `codex login`)
            'default_model' => 'gpt-5.2-codex',
        ],

        'openai_compatible' => [
            // OpenAI-compatible API for local LLMs (KoboldCpp, Ollama, LM Studio, etc.)
            // Settings configured via UI and stored in database (AppSettingsService)
            'base_url' => null,
            'api_key' => null, // Optional - many local servers don't require auth
            'default_model' => '', // Empty = use server default
            'max_tokens' => 8192,
            'context_window' => 32768, // Default context window for local LLMs
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reasoning Configuration (Provider-Specific)
    |--------------------------------------------------------------------------
    |
    | Each provider has different reasoning/thinking models:
    |
    | Anthropic: Uses explicit budget_tokens allocation
    | - budget_tokens: How many tokens Claude can use for internal thinking
    | - max_tokens is calculated as: budget_tokens + response_tokens
    | - Anthropic recommends budget_tokens be 40-60% of max_tokens
    |
    | OpenAI: Uses abstract effort levels + summary display
    | - effort: How hard to think (none/low/medium/high)
    | - summary: What to show user (concise/detailed/auto/null)
    | - Token usage is determined internally by the model
    |
    */

    'reasoning' => [
        // Anthropic: explicit token budgets
        'anthropic' => [
            'levels' => [
                ['name' => 'Off', 'budget_tokens' => 0],
                ['name' => 'Light', 'budget_tokens' => 4000],
                ['name' => 'Standard', 'budget_tokens' => 10000],
                ['name' => 'Deep', 'budget_tokens' => 20000],
                ['name' => 'Maximum', 'budget_tokens' => 32000],
            ],
        ],

        // OpenAI: effort levels (model decides token usage)
        // Summary is always 'auto' when reasoning is enabled
        'openai' => [
            'effort_levels' => [
                ['value' => 'none', 'name' => 'Off', 'description' => 'No reasoning (fastest)'],
                ['value' => 'low', 'name' => 'Light', 'description' => 'Quick reasoning'],
                ['value' => 'medium', 'name' => 'Standard', 'description' => 'Balanced reasoning'],
                ['value' => 'high', 'name' => 'Deep', 'description' => 'Thorough reasoning'],
            ],
        ],

        // Claude Code: uses MAX_THINKING_TOKENS environment variable internally
        // We expose similar levels to Anthropic for consistency
        'claude_code' => [
            'levels' => [
                ['name' => 'Off', 'thinking_tokens' => 0],
                ['name' => 'Light', 'thinking_tokens' => 4000],
                ['name' => 'Standard', 'thinking_tokens' => 10000],
                ['name' => 'Deep', 'thinking_tokens' => 20000],
                ['name' => 'Maximum', 'thinking_tokens' => 32000],
            ],
        ],

        // Codex: uses OpenAI-style effort levels
        'codex' => [
            'effort_levels' => [
                ['value' => 'none', 'name' => 'Off', 'description' => 'No reasoning (fastest)'],
                ['value' => 'low', 'name' => 'Light', 'description' => 'Quick reasoning'],
                ['value' => 'medium', 'name' => 'Standard', 'description' => 'Balanced reasoning'],
                ['value' => 'high', 'name' => 'Deep', 'description' => 'Thorough reasoning'],
            ],
        ],

        // OpenAI Compatible: Uses same effort levels as OpenAI
        // Most local LLMs will ignore this, but it's available for servers that support it
        'openai_compatible' => [
            'effort_levels' => [
                ['value' => 'none', 'name' => 'Off', 'description' => 'No reasoning'],
                ['value' => 'low', 'name' => 'Light', 'description' => 'Quick reasoning'],
                ['value' => 'medium', 'name' => 'Standard', 'description' => 'Balanced reasoning'],
                ['value' => 'high', 'name' => 'Deep', 'description' => 'Thorough reasoning'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Configuration
    |--------------------------------------------------------------------------
    |
    | Response budget levels (how long Claude's actual response can be).
    | This is independent of thinking level.
    | max_tokens = thinking_budget + response_budget
    |
    */

    'response' => [
        'levels' => [
            0 => ['name' => 'Short', 'tokens' => 4000],
            1 => ['name' => 'Normal', 'tokens' => 8192],
            2 => ['name' => 'Long', 'tokens' => 16000],
            3 => ['name' => 'Very Long', 'tokens' => 32000],
        ],
        'default_level' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the tool system.
    |
    */

    'tools' => [
        'enabled' => [
            'Read', 'Write', 'Edit', 'Bash', 'Grep', 'Glob',
            'MemoryQuery', 'MemoryCreate', 'MemoryUpdate', 'MemoryDelete', 'MemoryLink', 'MemoryUnlink',
        ],
        'timeout' => (int) env('TOOL_TIMEOUT', 120),
        'max_output_length' => (int) env('TOOL_MAX_OUTPUT', 30000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for generating vector embeddings.
    | Uses OpenAI's embedding API by default.
    |
    */

    'embeddings' => [
        // API key managed via UI (uses OpenAI key from database)
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'model' => 'text-embedding-3-small',
        'dimensions' => 1536,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Models
    |--------------------------------------------------------------------------
    |
    | Available models for each provider with pricing and capabilities.
    | Order determines display order (best/most capable first).
    |
    | Pricing is per 1 million tokens.
    | Cache pricing: Anthropic uses write (1.25x input) and read (0.1x input).
    | OpenAI doesn't support cache writes, only reads.
    |
    */

    'models' => [
        'anthropic' => [
            // Claude 4.5 models - ordered by capability (best first)
            // Source: https://docs.anthropic.com/en/docs/about-claude/models
            [
                'model_id' => 'claude-opus-4-5-20251101',
                'display_name' => 'Claude Opus 4.5',
                'context_window' => 200000,
                'max_output_tokens' => 64000,
                'input_price_per_million' => 5.00,
                'output_price_per_million' => 25.00,
                'cache_write_price_per_million' => 6.25,
                'cache_read_price_per_million' => 0.50,
            ],
            [
                'model_id' => 'claude-sonnet-4-5-20250929',
                'display_name' => 'Claude Sonnet 4.5',
                'context_window' => 200000,
                'max_output_tokens' => 64000,
                'input_price_per_million' => 3.00,
                'output_price_per_million' => 15.00,
                'cache_write_price_per_million' => 3.75,
                'cache_read_price_per_million' => 0.30,
            ],
            [
                'model_id' => 'claude-haiku-4-5-20251001',
                'display_name' => 'Claude Haiku 4.5',
                'context_window' => 200000,
                'max_output_tokens' => 64000,
                'input_price_per_million' => 1.00,
                'output_price_per_million' => 5.00,
                'cache_write_price_per_million' => 1.25,
                'cache_read_price_per_million' => 0.10,
            ],
        ],

        'openai' => [
            // GPT-5.1 Codex models - ordered by capability (best first)
            [
                'model_id' => 'gpt-5.1-codex-max',
                'display_name' => 'Codex 5.1 Max',
                'context_window' => 400000,
                'max_output_tokens' => 32768,
                'input_price_per_million' => 1.25,
                'output_price_per_million' => 10.00,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => 0.125,
            ],
            [
                'model_id' => 'gpt-5.1-codex-mini',
                'display_name' => 'Codex 5.1 Mini',
                'context_window' => 200000,
                'max_output_tokens' => 32768,
                'input_price_per_million' => 0.25,
                'output_price_per_million' => 2.00,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => 0.025,
            ],
        ],

        // Claude Code models (aliases supported by CLI)
        // Pricing is null since Claude Code uses subscription credits
        'claude_code' => [
            [
                'model_id' => 'opus',
                'display_name' => 'Claude Opus (via CLI)',
                'context_window' => 200000,
                'max_output_tokens' => 64000,
                'input_price_per_million' => null,
                'output_price_per_million' => null,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => null,
            ],
            [
                'model_id' => 'sonnet',
                'display_name' => 'Claude Sonnet (via CLI)',
                'context_window' => 200000,
                'max_output_tokens' => 64000,
                'input_price_per_million' => null,
                'output_price_per_million' => null,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => null,
            ],
            [
                'model_id' => 'haiku',
                'display_name' => 'Claude Haiku (via CLI)',
                'context_window' => 200000,
                'max_output_tokens' => 64000,
                'input_price_per_million' => null,
                'output_price_per_million' => null,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => null,
            ],
        ],

        // Codex models (via CLI)
        // Pricing is null since Codex CLI uses subscription credits
        // Source: https://developers.openai.com/codex/models/
        'codex' => [
            [
                'model_id' => 'gpt-5.2-codex',
                'display_name' => 'GPT-5.2 Codex',
                'context_window' => 400000,
                'max_output_tokens' => 32768,
                'input_price_per_million' => null,
                'output_price_per_million' => null,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => null,
            ],
            [
                'model_id' => 'gpt-5.1-codex-max',
                'display_name' => 'GPT-5.1 Codex Max',
                'context_window' => 400000,
                'max_output_tokens' => 32768,
                'input_price_per_million' => null,
                'output_price_per_million' => null,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => null,
            ],
            [
                'model_id' => 'gpt-5.1-codex-mini',
                'display_name' => 'GPT-5.1 Codex Mini',
                'context_window' => 200000,
                'max_output_tokens' => 32768,
                'input_price_per_million' => null,
                'output_price_per_million' => null,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for streaming responses.
    |
    */

    'streaming' => [
        // Temp file location for background streaming
        'temp_path' => env('STREAM_TEMP_PATH', storage_path('app/streams')),

        // How long to keep completed stream files (seconds)
        'cleanup_after' => (int) env('STREAM_CLEANUP_AFTER', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | System Prompt Configuration
    |--------------------------------------------------------------------------
    |
    | Path to file containing additional system prompt instructions.
    | This is appended to the core system prompt for project-specific guidance.
    |
    */

    'additional_system_prompt_file' => env('ADDITIONAL_SYSTEM_PROMPT_FILE', ''),
];
