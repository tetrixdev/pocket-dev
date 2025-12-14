<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default AI provider used for conversations.
    | Supported: "anthropic", "openai"
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
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'default_model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 8192),
            'api_version' => '2023-06-01',
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'default_model' => env('OPENAI_MODEL', 'gpt-5'),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 16384),
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
        'enabled' => ['Read', 'Write', 'Edit', 'Bash', 'Grep', 'Glob'],
        'timeout' => (int) env('TOOL_TIMEOUT', 120),
        'max_output_length' => (int) env('TOOL_MAX_OUTPUT', 30000),
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
];
