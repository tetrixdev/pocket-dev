<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default AI provider used for conversations.
    | Supported: "anthropic", "openai", "claude_code"
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

        'claude_code' => [
            'binary_path' => env('CLAUDE_BINARY_PATH', 'claude'),
            'timeout' => (int) env('CLAUDE_TIMEOUT', 300),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'default_model' => env('OPENAI_MODEL', 'gpt-4o'),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 8192),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thinking Configuration
    |--------------------------------------------------------------------------
    |
    | Extended thinking configuration for supported providers.
    |
    | - budget_tokens: How many tokens Claude can use for internal thinking
    | - response_tokens: How many tokens reserved for the actual response (separate setting)
    | - max_tokens is calculated as: budget_tokens + response_tokens
    |
    | Anthropic recommends budget_tokens be 40-60% of max_tokens.
    |
    */

    'thinking' => [
        // Thinking budget levels (how much Claude can "think")
        'levels' => [
            0 => ['name' => 'Off', 'budget_tokens' => 0],
            1 => ['name' => 'Think', 'budget_tokens' => 4000],
            2 => ['name' => 'Think Hard', 'budget_tokens' => 10000],
            3 => ['name' => 'Think Harder', 'budget_tokens' => 20000],
            4 => ['name' => 'Ultrathink', 'budget_tokens' => 32000],
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
    | Context Windows
    |--------------------------------------------------------------------------
    |
    | Maximum context window size for each model.
    |
    */

    'context_windows' => [
        // Anthropic
        'claude-sonnet-4-5-20250929' => 200000,
        'claude-opus-4-5-20251101' => 200000,
        'claude-3-5-sonnet-20241022' => 200000,
        'claude-3-opus-20240229' => 200000,
        'claude-3-haiku-20240307' => 200000,

        // OpenAI
        'gpt-4o' => 128000,
        'gpt-4o-mini' => 128000,
        'gpt-4-turbo' => 128000,
        'gpt-4' => 8192,
        'gpt-3.5-turbo' => 16385,

        // Default fallback
        'default' => 100000,
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
