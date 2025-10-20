<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claude Code CLI Path
    |--------------------------------------------------------------------------
    |
    | The path to the Claude Code CLI binary. By default, it assumes `claude`
    | is in your PATH. You can specify a full path if needed.
    |
    */

    'cli_path' => env('CLAUDE_CLI_PATH', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | The default Claude model to use for queries. Available options:
    | - claude-sonnet-4-5-20250929 (latest, recommended)
    | - claude-opus-4-20250514
    |
    */

    'default_model' => env('CLAUDE_DEFAULT_MODEL', 'claude-sonnet-4-5-20250929'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Tools
    |--------------------------------------------------------------------------
    |
    | The tools that Claude Code is allowed to use. Available tools:
    | - Read: Read files
    | - Write: Write files
    | - Edit: Edit existing files
    | - Bash: Execute bash commands
    | - Grep: Search file contents
    | - Glob: Find files by pattern
    |
    */

    'allowed_tools' => [
        'Read',
        'Write',
        'Edit',
        'Bash',
        'Grep',
        'Glob',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Mode
    |--------------------------------------------------------------------------
    |
    | How to handle permissions for file operations:
    | - 'prompt': Ask user for each operation (default)
    | - 'acceptEdits': Auto-accept file edits
    | - 'acceptAll': Auto-accept all operations (use with caution)
    |
    */

    'permission_mode' => env('CLAUDE_PERMISSION_MODE', 'acceptEdits'),

    /*
    |--------------------------------------------------------------------------
    | Max Turns
    |--------------------------------------------------------------------------
    |
    | Maximum number of conversation turns per query. Set to null for unlimited.
    |
    */

    'max_turns' => env('CLAUDE_MAX_TURNS', 50),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for Claude Code operations. Set to null for no timeout.
    |
    */

    'timeout' => env('CLAUDE_TIMEOUT', 300), // 5 minutes

    /*
    |--------------------------------------------------------------------------
    | Working Directory
    |--------------------------------------------------------------------------
    |
    | Default working directory for Claude Code operations.
    | This is where Claude Code will start and where relative paths resolve from.
    |
    | Set to '/' to allow access to both /workspace and /pocketdev-source.
    | Claude Code restricts file access to the working directory and subdirectories.
    |
    */

    'working_directory' => env('CLAUDE_WORKING_DIR', '/'),

    /*
    |--------------------------------------------------------------------------
    | Accessible Directories
    |--------------------------------------------------------------------------
    |
    | List of directories that Claude Code commonly needs to access.
    | This is for documentation purposes - Claude Code can access any directory
    | the www-data user has permissions for.
    |
    | Primary: /workspace (default working directory for user projects)
    | Secondary: /pocketdev-source (PocketDev source code)
    |
    */

    'accessible_directories' => [
        'workspace' => '/workspace',
        'source' => '/pocketdev-source',
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Storage
    |--------------------------------------------------------------------------
    |
    | Where to store Claude Code sessions:
    | - 'database': Store in database (default)
    | - 'file': Store in files
    | - 'redis': Store in Redis
    |
    */

    'session_driver' => env('CLAUDE_SESSION_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Voice Input
    |--------------------------------------------------------------------------
    |
    | Configuration for voice input transcription.
    |
    */

    'voice' => [
        'enabled' => env('CLAUDE_VOICE_ENABLED', true),
        'provider' => env('CLAUDE_VOICE_PROVIDER', 'openai'), // 'openai' or 'browser'
        'openai_api_key' => env('OPENAI_API_KEY'),
        'openai_model' => env('OPENAI_WHISPER_MODEL', 'whisper-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Enable detailed logging of Claude Code operations for debugging.
    |
    */

    'logging' => [
        'enabled' => env('CLAUDE_LOGGING_ENABLED', true),
        'channel' => env('CLAUDE_LOG_CHANNEL', 'stack'),
        'level' => env('CLAUDE_LOG_LEVEL', 'info'),
    ],

];
