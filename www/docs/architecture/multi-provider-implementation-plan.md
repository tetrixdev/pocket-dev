# Multi-Provider Conversation Architecture

## Implementation Plan

**Branch:** `feature/multi-provider-conversations`
**Status:** Complete - All Phases Implemented
**Last Updated:** December 2025

---

## Overview

This document outlines the architectural changes needed to:
1. Store conversations in the database (not Claude CLI's `.jsonl` files)
2. Support multiple AI providers (Anthropic API, OpenAI, future providers)
3. Implement our own tool system (Read, Edit, Bash, Grep, Glob)
4. Track token usage and context window
5. Maintain backward compatibility with Claude Code CLI as a provider option

---

## Current Architecture Problems

| Problem | Impact |
|---------|--------|
| Conversations stored in Claude CLI's `.jsonl` files | No control, tied to CLI internals |
| System commands (`/clear`, `/compact`) create non-API messages | Can't reliably continue conversations |
| No token/context window tracking | Users can't see context usage |
| Tightly coupled to Claude Code | Can't support other providers |
| Claude Code's skills/hooks/commands are proprietary | Can't customize or extend for other providers |

---

## Target Architecture

> **Note:** This schema is evolving. We're implementing Anthropic first, then OpenAI
> in parallel to validate the abstraction doesn't overfit to one provider.

### Database Schema

#### `conversations` table

```sql
CREATE TABLE conversations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid UUID UNIQUE NOT NULL,              -- Public identifier
    provider_type VARCHAR(50) NOT NULL,     -- 'anthropic', 'openai', 'claude_code'
    model VARCHAR(100) NOT NULL,            -- Can change mid-conversation
    title VARCHAR(255) NULL,
    working_directory VARCHAR(500) NOT NULL,

    -- Token tracking (cumulative)
    total_input_tokens INT DEFAULT 0,
    total_output_tokens INT DEFAULT 0,
    -- Note: context_window_size comes from config, not stored here

    -- Status
    -- idle: waiting for user input
    -- processing: actively streaming/working
    -- archived: user archived (can unarchive)
    -- failed: error state, may need recovery
    status ENUM('idle', 'processing', 'archived', 'failed') DEFAULT 'idle',

    -- Timestamps
    last_activity_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    INDEX idx_provider_type (provider_type),
    INDEX idx_status (status),
    INDEX idx_last_activity (last_activity_at)
);
```

#### `messages` table

```sql
CREATE TABLE messages (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    conversation_id BIGINT NOT NULL,

    -- Message identity
    -- Note: OpenAI also has 'tool' role; Anthropic uses 'user' with tool_result content
    role ENUM('user', 'assistant', 'system', 'tool') NOT NULL,

    -- Content stored in NATIVE provider format (JSON)
    -- We store as-is from each provider, no normalization
    -- See "Provider Message Formats" section below for differences
    content JSON NOT NULL,

    -- Token tracking (per message)
    input_tokens INT NULL,
    output_tokens INT NULL,
    cache_creation_tokens INT NULL,
    cache_read_tokens INT NULL,

    -- Metadata
    stop_reason VARCHAR(50) NULL,       -- 'end_turn', 'tool_use', 'max_tokens', etc.
    model VARCHAR(100) NULL,            -- Model that generated this (for assistant)

    -- Ordering
    sequence INT NOT NULL,              -- Order within conversation

    created_at TIMESTAMP,

    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    INDEX idx_conversation_sequence (conversation_id, sequence)
);
```

#### Provider Message Formats

Messages are stored in **native provider format**. The `provider_type` on the conversation
tells us how to interpret the `content` JSON.

| Aspect | Anthropic | OpenAI |
|--------|-----------|--------|
| Roles | `user`, `assistant` | `user`, `assistant`, `system`, `tool` |
| Content | Array of blocks: `[{type, ...}]` | String (or null with tool_calls) |
| Tool calls | In content: `{type: "tool_use", id, name, input}` | Separate: `tool_calls: [{id, function: {name, arguments}}]` |
| Tool results | `role: "user"` + `{type: "tool_result"}` | `role: "tool"` + `tool_call_id` |
| Arguments | Parsed object | JSON string |

**Why native format?** Converting between formats is lossy. Storing native means
we can always reconstruct exact API requests for conversation continuation.

#### `tool_executions` table (optional, for debugging/audit)

```sql
CREATE TABLE tool_executions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    message_id BIGINT NOT NULL,          -- The assistant message with tool_use

    tool_use_id VARCHAR(100) NOT NULL,   -- From API: toolu_xxx
    tool_name VARCHAR(100) NOT NULL,     -- 'Read', 'Edit', 'Bash', etc.
    tool_input JSON NOT NULL,            -- Input parameters
    tool_output TEXT NULL,               -- Execution result
    is_error BOOLEAN DEFAULT FALSE,

    execution_time_ms INT NULL,
    created_at TIMESTAMP,

    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
);
```

### Provider Interface

```php
<?php

namespace App\Contracts;

interface AIProviderInterface
{
    /**
     * Send a message and stream the response
     */
    public function streamMessage(
        Conversation $conversation,
        string $prompt,
        array $options = []
    ): StreamResponse;

    /**
     * Get available models for this provider
     */
    public function getModels(): array;

    /**
     * Get context window size for a model
     */
    public function getContextWindow(string $model): int;

    /**
     * Get the provider identifier
     */
    public function getProviderType(): string;

    /**
     * Check if provider is configured and available
     */
    public function isAvailable(): bool;
}
```

### Provider Implementations

#### 1. `AnthropicProvider` (Direct API)

```php
<?php

namespace App\Services\Providers;

class AnthropicProvider implements AIProviderInterface
{
    private string $apiKey;
    private HttpClient $client;

    public function streamMessage(
        Conversation $conversation,
        string $prompt,
        array $options = []
    ): StreamResponse {
        // Build messages array from conversation
        $messages = $this->buildMessagesFromConversation($conversation);

        // Add new user message
        $messages[] = ['role' => 'user', 'content' => $prompt];

        // Make streaming API call
        return $this->client->post('https://api.anthropic.com/v1/messages', [
            'model' => $conversation->model,
            'max_tokens' => $options['max_tokens'] ?? 8192,
            'stream' => true,
            'messages' => $messages,
            'tools' => $this->getToolDefinitions(),
            'thinking' => $this->getThinkingConfig($options),
        ]);
    }

    public function getProviderType(): string
    {
        return 'anthropic';
    }

    // ...
}
```

#### 2. `ClaudeCodeProvider` (CLI Wrapper - for backward compatibility)

```php
<?php

namespace App\Services\Providers;

class ClaudeCodeProvider implements AIProviderInterface
{
    /**
     * Uses existing ClaudeCodeService but normalizes output
     * to Anthropic Messages API format
     */
    public function streamMessage(
        Conversation $conversation,
        string $prompt,
        array $options = []
    ): StreamResponse {
        // Start CLI process
        $process = $this->startCliProcess($prompt, $options);

        // Return a StreamResponse that:
        // 1. Reads CLI output
        // 2. Normalizes to Anthropic format
        // 3. Stores messages in database
        return new CliStreamResponse($process, $conversation);
    }
}
```

#### 3. `OpenAIProvider` (Future)

```php
<?php

namespace App\Services\Providers;

class OpenAIProvider implements AIProviderInterface
{
    // Maps OpenAI chat completions format to our internal format
    // Converts tool_calls to tool_use blocks, etc.
}
```

---

## Tool System

### Tool Interface

```php
<?php

namespace App\Contracts;

interface ToolInterface
{
    /**
     * Execute the tool and return result
     */
    public function execute(array $input, ExecutionContext $context): ToolResult;
}
```

### Abstract Base Tool

```php
<?php

namespace App\Tools;

abstract class Tool implements ToolInterface
{
    public string $name;                    // e.g., 'Read'
    public string $description;             // Brief, for API tools array
    public array $inputSchema;              // JSON Schema for parameters
    public ?string $instructions = null;    // Detailed, for system prompt (optional)

    /**
     * Convert to API tool definition format
     */
    public function toDefinition(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];
    }
}
```

### Tool Implementations

#### Read Tool

```php
<?php

namespace App\Tools;

class ReadTool extends Tool
{
    public string $name = 'Read';

    public string $description = 'Read the contents of a file. Returns the file content with line numbers.';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'file_path' => [
                'type' => 'string',
                'description' => 'Absolute path to the file to read',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Line number to start reading from (1-indexed)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of lines to read',
            ],
        ],
        'required' => ['file_path'],
    ];

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $path = $context->resolvePath($input['file_path']);

        if (!file_exists($path)) {
            return ToolResult::error("File not found: {$input['file_path']}");
        }

        $lines = file($path);
        $offset = ($input['offset'] ?? 1) - 1;
        $limit = $input['limit'] ?? count($lines);

        $output = [];
        foreach (array_slice($lines, $offset, $limit) as $i => $line) {
            $lineNum = $offset + $i + 1;
            $output[] = sprintf('%6d | %s', $lineNum, rtrim($line));
        }

        return ToolResult::success(implode("\n", $output));
    }
}
```

#### Edit Tool

```php
<?php

namespace App\Tools;

class EditTool extends Tool
{
    public string $name = 'Edit';

    public string $description = 'Performs exact string replacements in files.';

    public ?string $instructions = <<<'INSTRUCTIONS'
## Edit Tool Guidelines
- ALWAYS read a file before editing it
- old_string must be unique in the file, or use replace_all
- Preserve exact indentation from the source
- Use replace_all: true for renaming variables across the file
INSTRUCTIONS;

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'file_path' => [
                'type' => 'string',
                'description' => 'Absolute path to the file to edit',
            ],
            'old_string' => [
                'type' => 'string',
                'description' => 'The exact text to find and replace',
            ],
            'new_string' => [
                'type' => 'string',
                'description' => 'The replacement text',
            ],
            'replace_all' => [
                'type' => 'boolean',
                'description' => 'Replace all occurrences (default: false)',
                'default' => false,
            ],
        ],
        'required' => ['file_path', 'old_string', 'new_string'],
    ];

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $path = $context->resolvePath($input['file_path']);

        if (!file_exists($path)) {
            return ToolResult::error("File not found: {$input['file_path']}");
        }

        $content = file_get_contents($path);
        $oldString = $input['old_string'];
        $newString = $input['new_string'];
        $replaceAll = $input['replace_all'] ?? false;

        if ($replaceAll) {
            $count = substr_count($content, $oldString);
            if ($count === 0) {
                return ToolResult::error("String not found in file");
            }
            $newContent = str_replace($oldString, $newString, $content);
            file_put_contents($path, $newContent);
            return ToolResult::success("Replaced {$count} occurrences");
        }

        $pos = strpos($content, $oldString);
        if ($pos === false) {
            return ToolResult::error("String not found in file");
        }

        // Check uniqueness
        if (strpos($content, $oldString, $pos + 1) !== false) {
            return ToolResult::error(
                "old_string is not unique in the file. " .
                "Provide more surrounding context or use replace_all."
            );
        }

        $newContent = substr_replace(
            $content,
            $newString,
            $pos,
            strlen($oldString)
        );

        file_put_contents($path, $newContent);

        return ToolResult::success("File updated successfully");
    }
}
```

#### Bash Tool

```php
<?php

namespace App\Tools;

class BashTool extends Tool
{
    public string $name = 'Bash';

    public string $description = 'Execute a bash command for git, npm, docker, and terminal operations.';

    public ?string $instructions = <<<'INSTRUCTIONS'
## Bash Tool Guidelines
- Use for git, npm, docker, and other terminal operations
- Do NOT use for file operations - use Read, Edit, Write instead
- Commands timeout after 120 seconds by default (max 600)
INSTRUCTIONS;

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'command' => [
                'type' => 'string',
                'description' => 'The bash command to execute',
            ],
            'timeout' => [
                'type' => 'integer',
                'description' => 'Timeout in seconds (default: 120, max: 600)',
                'default' => 120,
            ],
        ],
        'required' => ['command'],
    ];

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $command = $input['command'];
        $timeout = min($input['timeout'] ?? 120, 600);

        // Security checks
        if ($this->containsDangerousPattern($command)) {
            return ToolResult::error("Command blocked for security reasons");
        }

        $process = Process::timeout($timeout)
            ->path($context->getWorkingDirectory())
            ->run($command);

        $output = $process->output() . $process->errorOutput();

        if (strlen($output) > 30000) {
            $output = substr($output, 0, 30000) . "\n\n[Output truncated]";
        }

        if ($process->successful()) {
            return ToolResult::success($output ?: "(no output)");
        }

        return ToolResult::error("Exit code {$process->exitCode()}: {$output}");
    }

    private function containsDangerousPattern(string $command): bool
    {
        $dangerous = ['rm -rf /', 'mkfs', ':(){:|:&};:'];
        foreach ($dangerous as $pattern) {
            if (str_contains($command, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
```

#### Grep Tool

```php
<?php

namespace App\Tools;

class GrepTool extends Tool
{
    public string $name = 'Grep';

    public string $description = 'Search for patterns in files using regex.';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'pattern' => [
                'type' => 'string',
                'description' => 'Regex pattern to search for',
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Directory or file to search in',
            ],
            'glob' => [
                'type' => 'string',
                'description' => 'File pattern filter (e.g., "*.php")',
            ],
            'output_mode' => [
                'type' => 'string',
                'enum' => ['content', 'files_with_matches', 'count'],
                'description' => 'What to return',
                'default' => 'files_with_matches',
            ],
        ],
        'required' => ['pattern'],
    ];

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $args = ['rg', '--json'];

        if ($input['output_mode'] === 'files_with_matches') {
            $args[] = '-l';
        } elseif ($input['output_mode'] === 'count') {
            $args[] = '-c';
        }

        if (isset($input['glob'])) {
            $args[] = '--glob';
            $args[] = $input['glob'];
        }

        $args[] = $input['pattern'];
        $args[] = $input['path'] ?? $context->getWorkingDirectory();

        $process = Process::run($args);

        return ToolResult::success($process->output() ?: "No matches found");
    }
}
```

#### Glob Tool

```php
<?php

namespace App\Tools;

class GlobTool extends Tool
{
    public string $name = 'Glob';

    public string $description = 'Find files matching a glob pattern.';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'pattern' => [
                'type' => 'string',
                'description' => 'Glob pattern (e.g., "**/*.php", "src/**/*.ts")',
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Base directory to search from',
            ],
        ],
        'required' => ['pattern'],
    ];

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $basePath = $input['path'] ?? $context->getWorkingDirectory();
        $pattern = $input['pattern'];

        $finder = new Finder();
        $finder->in($basePath)->name($pattern)->files();

        $files = [];
        foreach ($finder as $file) {
            $files[] = [
                'path' => $file->getRealPath(),
                'mtime' => $file->getMTime(),
            ];
        }

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        $output = array_map(fn($f) => $f['path'], $files);

        return ToolResult::success(implode("\n", $output) ?: "No files found");
    }
}
```

### Tool Registry

```php
<?php

namespace App\Services;

class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    public function getDefinitions(): array
    {
        return array_map(fn(Tool $tool) => $tool->toDefinition(), $this->tools);
    }

    public function getInstructions(): string
    {
        $instructions = [];
        foreach ($this->tools as $tool) {
            if ($tool->instructions !== null) {
                $instructions[] = $tool->instructions;
            }
        }
        return implode("\n\n", $instructions);
    }

    public function execute(
        string $name,
        array $input,
        ExecutionContext $context
    ): ToolResult {
        $tool = $this->get($name);

        if (!$tool) {
            return ToolResult::error("Unknown tool: {$name}");
        }

        return $tool->execute($input, $context);
    }
}
```

---

## Streaming Architecture

### Generic Stream Events

Each provider converts their native streaming events to a generic `StreamEvent` format.
The frontend only knows about `StreamEvent`, not provider-specific formats.

```php
<?php

namespace App\Streaming;

class StreamEvent
{
    public function __construct(
        public string $type,           // See types below
        public ?int $blockIndex = null,
        public ?string $content = null,
        public ?array $metadata = null, // tool_id, tool_name, tokens, etc.
    ) {}

    // Event types:
    // - 'thinking_start'  : Start of thinking block
    // - 'thinking_delta'  : Thinking content chunk
    // - 'thinking_stop'   : End of thinking block
    // - 'text_start'      : Start of text block
    // - 'text_delta'      : Text content chunk
    // - 'text_stop'       : End of text block
    // - 'tool_use_start'  : Tool invocation started (metadata: tool_id, tool_name)
    // - 'tool_use_delta'  : Tool input chunk
    // - 'tool_use_stop'   : Tool invocation complete
    // - 'tool_result'     : Tool execution result (metadata: tool_id, is_error)
    // - 'usage'           : Token usage update (metadata: input_tokens, output_tokens)
    // - 'done'            : Stream complete (metadata: stop_reason)
    // - 'error'           : Error occurred (content: error message)

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'block_index' => $this->blockIndex,
            'content' => $this->content,
            'metadata' => $this->metadata,
        ], fn($v) => $v !== null);
    }
}
```

### Background Streaming & Reconnection

Streaming writes `StreamEvent` JSON lines to temp files, enabling:
- **Background processing**: Navigate away, stream continues
- **Reconnection**: Return to conversation, replay from last position
- **Recovery**: If connection drops, resume from where you left off

```
/tmp/conversation-{uuid}.jsonl
```

Each line is a JSON-encoded `StreamEvent`. Frontend can:
1. Start reading from beginning (new connection)
2. Resume from byte offset (reconnection)
3. Tail for new events (live streaming)

### Stream Response Handler

```php
<?php

namespace App\Services;

class ConversationStreamHandler
{
    public function stream(
        Conversation $conversation,
        string $prompt,
        AIProviderInterface $provider,
        ToolRegistry $tools
    ): Generator {
        // Save user message
        $userMessage = $this->saveUserMessage($conversation, $prompt);

        // Start streaming
        $response = $provider->streamMessage($conversation, $prompt, [
            'tools' => $tools->getDefinitions(),
        ]);

        $assistantContent = [];
        $pendingToolUses = [];

        foreach ($response->stream() as $event) {
            yield $event; // Forward to frontend

            // Accumulate content blocks
            if ($event['type'] === 'content_block_stop') {
                $assistantContent[] = $event['content_block'];

                if ($event['content_block']['type'] === 'tool_use') {
                    $pendingToolUses[] = $event['content_block'];
                }
            }

            // Handle message completion
            if ($event['type'] === 'message_stop') {
                // Save assistant message
                $assistantMessage = $this->saveAssistantMessage(
                    $conversation,
                    $assistantContent,
                    $event['usage']
                );

                // Execute tools if needed
                if (!empty($pendingToolUses)) {
                    $toolResults = $this->executeTools($pendingToolUses, $tools);

                    // Save tool results as user message
                    $this->saveToolResultMessage($conversation, $toolResults);

                    // Continue conversation with tool results
                    yield from $this->stream(
                        $conversation,
                        null, // No new prompt, just tool results
                        $provider,
                        $tools
                    );
                }
            }
        }
    }

    private function executeTools(array $toolUses, ToolRegistry $tools): array
    {
        $results = [];

        foreach ($toolUses as $toolUse) {
            $result = $tools->execute(
                $toolUse['name'],
                $toolUse['input'],
                new ExecutionContext($this->conversation->project_path)
            );

            $results[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolUse['id'],
                'content' => $result->getOutput(),
                'is_error' => $result->isError(),
            ];
        }

        return $results;
    }
}
```

---

## System Prompt

The system prompt teaches the model how to use our tools. This is critical for tool effectiveness.

```php
<?php

namespace App\Services;

class SystemPromptBuilder
{
    public function build(Conversation $conversation, array $tools): string
    {
        $prompt = <<<PROMPT
You are an AI coding assistant with access to tools for reading, editing, and exploring code.

# Tool Usage Guidelines

## Read Tool
- Use to read file contents before editing
- Supports offset and limit for large files
- Returns line numbers for reference

## Edit Tool
- ALWAYS read a file before editing it
- old_string must be unique in the file, or use replace_all
- Preserve exact indentation
- For renaming across a file, use replace_all: true

## Bash Tool
- Use for git, npm, terminal operations
- Do NOT use for file operations (use Read/Edit/Write instead)
- Commands timeout after 120 seconds by default

## Grep Tool
- Search for patterns in code
- Use output_mode: "files_with_matches" to find files
- Use output_mode: "content" to see matching lines

## Glob Tool
- Find files by pattern
- Results sorted by modification time

# Working Directory
Current project: {$conversation->project_path}

# Guidelines
- Read files before editing
- Make minimal, focused changes
- Don't add unnecessary features
- Preserve existing code style
PROMPT;

        return $prompt;
    }
}
```

---

## Implementation Status

### Phase 1: Database Foundation ✅ COMPLETE

1. ✅ Created migrations for `conversations`, `messages` tables
2. ✅ Created Eloquent models with relationships
3. ✅ Created base contracts and tool infrastructure

**Files created:**
- `database/migrations/2025_12_04_000001_create_conversations_table.php`
- `database/migrations/2025_12_04_000002_create_messages_table.php`
- `app/Models/Conversation.php`
- `app/Models/Message.php`
- `app/Contracts/AIProviderInterface.php`
- `app/Contracts/ToolInterface.php`
- `app/Tools/Tool.php`
- `app/Tools/ToolResult.php`
- `app/Tools/ExecutionContext.php`
- `app/Streaming/StreamEvent.php`

### Phase 2: Anthropic Provider & Streaming ✅ COMPLETE

1. ✅ Implemented `AnthropicProvider` with direct API calls
2. ✅ Real-time SSE streaming via proc_open
3. ✅ Created `ConversationStreamHandler` for tool execution loop
4. ✅ Created `SystemPromptBuilder` for dynamic system prompts
5. ✅ Created configuration file

**Files created:**
- `config/ai.php`
- `app/Services/Providers/AnthropicProvider.php`
- `app/Services/ConversationStreamHandler.php`
- `app/Services/SystemPromptBuilder.php`
- `app/Services/ToolRegistry.php`

### Phase 3: Tool System ✅ COMPLETE

1. ✅ Implemented all core tools: Read, Edit, Write, Bash, Grep, Glob
2. ✅ Each tool has input schema, description, and optional instructions

**Files created:**
- `app/Tools/ReadTool.php`
- `app/Tools/EditTool.php`
- `app/Tools/WriteTool.php`
- `app/Tools/BashTool.php`
- `app/Tools/GrepTool.php`
- `app/Tools/GlobTool.php`

### Phase 4: Service Provider & DI ✅ COMPLETE

1. ✅ Created `AIServiceProvider` for dependency injection
2. ✅ Created `ProviderFactory` for provider selection
3. ✅ Registered in bootstrap/providers.php

**Files created:**
- `app/Providers/AIServiceProvider.php`
- `app/Services/ProviderFactory.php`

**Files modified:**
- `bootstrap/providers.php`

### Phase 5: Frontend & Controller ✅ COMPLETE

1. ✅ Created ConversationController for v2 API
2. ✅ Added v2 API routes under `/api/v2/` prefix
3. ✅ Created V2StreamHandler JavaScript utility
4. ✅ Kept existing `/api/claude` routes for backward compatibility

**Files created:**
- `app/Http/Controllers/Api/ConversationController.php`
- `resources/js/v2-streaming.js`

**Files modified:**
- `routes/api.php`

### Phase 6: Migration & Testing ✅ COMPLETE

1. ✅ Database migrations run (conversations, messages tables created)
2. ✅ All services wired via AIServiceProvider
3. ✅ Documentation updated with implementation status

---

## API Format Reference

### Anthropic Messages API Request

```json
{
    "model": "claude-sonnet-4-5-20250929",
    "max_tokens": 8192,
    "stream": true,
    "system": "You are a coding assistant...",
    "messages": [
        {"role": "user", "content": "Read the file app/Models/User.php"},
        {"role": "assistant", "content": [
            {"type": "tool_use", "id": "toolu_xxx", "name": "Read", "input": {"file_path": "/app/Models/User.php"}}
        ]},
        {"role": "user", "content": [
            {"type": "tool_result", "tool_use_id": "toolu_xxx", "content": "<?php..."}
        ]},
        {"role": "assistant", "content": [
            {"type": "text", "text": "The User model contains..."}
        ]}
    ],
    "tools": [
        {
            "name": "Read",
            "description": "Read file contents",
            "input_schema": {"type": "object", "properties": {...}}
        }
    ],
    "thinking": {
        "type": "enabled",
        "budget_tokens": 10000
    }
}
```

### Streaming Events

```
event: message_start
data: {"type":"message_start","message":{"id":"msg_xxx","type":"message","role":"assistant","content":[],"model":"claude-sonnet-4-5-20250929"}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"Let me analyze..."}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: content_block_start
data: {"type":"content_block_start","index":1,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"Based on..."}}

event: content_block_stop
data: {"type":"content_block_stop","index":1}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":150}}

event: message_stop
data: {"type":"message_stop"}
```

### Token Usage Fields

```json
{
    "usage": {
        "input_tokens": 1234,
        "output_tokens": 567,
        "cache_creation_input_tokens": 100,
        "cache_read_input_tokens": 50
    }
}
```

---

## Configuration

### New Config File: `config/ai.php`

```php
<?php

return [
    'default_provider' => env('AI_PROVIDER', 'anthropic'),

    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'default_model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
            'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 8192),
        ],

        'claude_code' => [
            'binary_path' => env('CLAUDE_BINARY_PATH', 'claude'),
            'timeout' => env('CLAUDE_TIMEOUT', 300),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'default_model' => env('OPENAI_MODEL', 'gpt-4'),
        ],
    ],

    'thinking' => [
        'levels' => [
            0 => ['name' => 'Off', 'budget_tokens' => 0],
            1 => ['name' => 'Think', 'budget_tokens' => 4000],
            2 => ['name' => 'Think Hard', 'budget_tokens' => 10000],
            3 => ['name' => 'Think Harder', 'budget_tokens' => 20000],
            4 => ['name' => 'Ultrathink', 'budget_tokens' => 32000],
        ],
    ],

    'tools' => [
        'enabled' => ['Read', 'Write', 'Edit', 'Bash', 'Grep', 'Glob'],
        'timeout' => 120,
    ],

    'context_windows' => [
        'claude-sonnet-4-5-20250929' => 200000,
        'claude-opus-4-5-20251101' => 200000,
        'gpt-4' => 128000,
        'gpt-4o' => 128000,
    ],
];
```

---

## Open Questions

1. **Tool permissions**: Should we implement a permission system like Claude Code's `acceptEdits`?
2. **Sandboxing**: Do we need to sandbox tool execution? Currently relying on Docker isolation.
3. **Caching**: Should we implement prompt caching for long system prompts?
4. **Rate limiting**: How to handle API rate limits gracefully?
5. **Error recovery**: How to resume after a failed tool execution?

---

## References

- [Anthropic Messages API](https://platform.claude.com/docs/en/api/messages)
- [Anthropic Streaming](https://platform.claude.com/docs/en/api/messages-streaming)
- [Tool Use (AWS Bedrock)](https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-anthropic-claude-messages-tool-use.html)
- [Extended Thinking](https://docs.aws.amazon.com/bedrock/latest/userguide/claude-messages-extended-thinking.html)
- [Advanced Tool Use](https://www.anthropic.com/engineering/advanced-tool-use)
