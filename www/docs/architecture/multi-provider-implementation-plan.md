# Multi-Provider Conversation Architecture

## Overview

**Branch:** `feature/multi-provider-conversations`
**Status:** Complete
**Last Updated:** December 2025

This document describes the architecture for database-backed, multi-provider AI conversations with streaming support and tool execution.

---

## Two Chat Interfaces

| Interface | URL | Backend | Description |
|-----------|-----|---------|-------------|
| **Original Chat** | `/` or `/session/{id}` | Claude Code CLI | Uses `ClaudeCodeService`, stores in `.jsonl` files, full Claude Code features |
| **Chat V2** | `/chat-v2` | Direct API | Database-backed, multi-provider, custom tool system |

### Which to Use?

- **Original Chat (`/`)**: Full Claude Code CLI experience with built-in tools, hooks, and skills
- **Chat V2 (`/chat-v2`)**: Direct API access, database-backed conversations, multi-provider support

---

## Architecture Overview

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Frontend       │────▶│  Controller      │────▶│  Background Job │
│  (Alpine.js)    │     │  (API routes)    │     │  (Queue)        │
└─────────────────┘     └──────────────────┘     └─────────────────┘
        │                       │                        │
        │                       │                        ▼
        │                       │               ┌─────────────────┐
        │                       │               │  AI Provider    │
        │                       │               │  (Anthropic/    │
        │                       │               │   OpenAI)       │
        │                       │               └─────────────────┘
        │                       │                        │
        ▼                       ▼                        ▼
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  SSE Stream     │◀────│  Redis           │◀────│  StreamManager  │
│  (Events)       │     │  (Pub/Sub +      │     │  (Event Buffer) │
└─────────────────┘     │   Lists)         │     └─────────────────┘
                        └──────────────────┘
```

---

## Database Schema

### `conversations` table

```sql
CREATE TABLE conversations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid UUID UNIQUE NOT NULL,              -- Public identifier (route key)
    provider_type VARCHAR(50) NOT NULL,     -- 'anthropic', 'openai'
    model VARCHAR(100) NOT NULL,
    title VARCHAR(255) NULL,
    working_directory VARCHAR(500) NOT NULL,

    -- Token tracking (cumulative)
    total_input_tokens INT DEFAULT 0,
    total_output_tokens INT DEFAULT 0,

    -- Status: idle, processing, archived, failed
    status VARCHAR(20) DEFAULT 'idle',

    last_activity_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    INDEX (provider_type),
    INDEX (status),
    INDEX (last_activity_at),
    INDEX (working_directory)
);
```

### `messages` table

```sql
CREATE TABLE messages (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    conversation_id BIGINT NOT NULL,

    role VARCHAR(20) NOT NULL,              -- 'user', 'assistant'
    content JSON NOT NULL,                  -- Provider-native format

    -- Token tracking
    input_tokens INT NULL,
    output_tokens INT NULL,
    cache_creation_tokens INT NULL,
    cache_read_tokens INT NULL,

    stop_reason VARCHAR(50) NULL,
    model VARCHAR(100) NULL,
    sequence INT NOT NULL,

    created_at TIMESTAMP,

    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    INDEX (conversation_id, sequence)
);
```

### `ai_models` table

Centralized model configuration with pricing and capabilities:

```sql
CREATE TABLE ai_models (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    provider VARCHAR(50) NOT NULL,          -- 'anthropic', 'openai'
    model_id VARCHAR(100) NOT NULL,         -- 'claude-sonnet-4-5-20250929'
    display_name VARCHAR(100) NOT NULL,
    context_window INT NOT NULL,
    max_output_tokens INT NULL,

    -- Pricing (per million tokens)
    input_price_per_million DECIMAL(10,4),
    output_price_per_million DECIMAL(10,4),
    cache_write_price_per_million DECIMAL(10,4) NULL,
    cache_read_price_per_million DECIMAL(10,4) NULL,

    -- Capabilities
    is_active BOOLEAN DEFAULT TRUE,
    supports_streaming BOOLEAN DEFAULT TRUE,
    supports_tools BOOLEAN DEFAULT TRUE,
    supports_vision BOOLEAN DEFAULT FALSE,
    supports_extended_thinking BOOLEAN DEFAULT FALSE,

    sort_order INT DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    UNIQUE (provider, model_id),
    INDEX (provider, is_active),
    INDEX (model_id)
);
```

### `settings` table

Key-value settings storage:

```sql
CREATE TABLE settings (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    key VARCHAR(255) UNIQUE NOT NULL,
    value TEXT NULL,                        -- JSON-encoded values
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## Provider Interface

```php
interface AIProviderInterface
{
    /**
     * Get the provider identifier (e.g., 'anthropic', 'openai').
     */
    public function getProviderType(): string;

    /**
     * Check if the provider is configured and available.
     */
    public function isAvailable(): bool;

    /**
     * Get available models for this provider.
     */
    public function getModels(): array;

    /**
     * Get context window size for a specific model.
     */
    public function getContextWindow(string $model): int;

    /**
     * Stream a message and yield StreamEvent objects.
     *
     * Messages should already be saved to the conversation before calling.
     * The provider reads messages from the conversation's database records.
     *
     * @return Generator<StreamEvent>
     */
    public function streamMessage(
        Conversation $conversation,
        array $options = []
    ): Generator;

    /**
     * Build the messages array for the API request.
     */
    public function buildMessagesFromConversation(Conversation $conversation): array;
}
```

### Implemented Providers

| Provider | Class | Status |
|----------|-------|--------|
| Anthropic | `AnthropicProvider` | ✅ Complete |
| OpenAI | `OpenAIProvider` | ✅ Complete (Responses API) |
| Claude Code CLI | `ClaudeCodeProvider` | 🔮 Reserved |

---

## Streaming Architecture

### Redis-Based Event Streaming

Streaming uses Redis for event buffering and real-time delivery:

```
stream:{uuid}:events    - Redis List of JSON-encoded StreamEvents
stream:{uuid}:status    - 'streaming' | 'completed' | 'failed'
stream:{uuid}:metadata  - JSON with start time, model, etc.
```

**Benefits:**
- **Background processing**: Navigate away, stream continues
- **Reconnection**: Return to conversation, replay from last position
- **Real-time updates**: Redis pub/sub for instant event delivery

### StreamManager

```php
class StreamManager
{
    // Start a new stream
    public function startStream(string $uuid, array $metadata = []): void;

    // Append event to stream (also publishes to subscribers)
    public function appendEvent(string $uuid, StreamEvent $event): void;

    // Mark stream as completed/failed
    public function completeStream(string $uuid): void;
    public function failStream(string $uuid, string $error): void;

    // Read buffered events (for reconnection)
    public function getEvents(string $uuid, int $fromIndex = 0): array;

    // Check status
    public function getStatus(string $uuid): ?string;
    public function isStreaming(string $uuid): bool;
}
```

### StreamEvent Types

```php
class StreamEvent
{
    public string $type;        // Event type
    public ?int $blockIndex;    // Content block index
    public ?string $content;    // Text content
    public ?array $metadata;    // Additional data

    // Event types:
    // - thinking_start, thinking_delta, thinking_stop
    // - text_start, text_delta, text_stop
    // - tool_use_start, tool_use_delta, tool_use_stop
    // - tool_result
    // - usage (token counts)
    // - done (stream complete)
    // - error
}
```

### Background Job: ProcessConversationStream

The streaming logic runs in a background queue job:

```php
class ProcessConversationStream implements ShouldQueue
{
    public int $timeout = 600;  // 10 minutes max
    public int $tries = 1;      // Don't retry failed streams

    public function handle(
        ProviderFactory $providerFactory,
        StreamManager $streamManager,
        ToolRegistry $toolRegistry,
        SystemPromptBuilder $systemPromptBuilder,
    ): void {
        // 1. Start stream in Redis
        // 2. Mark conversation as processing
        // 3. Save user message
        // 4. Stream with tool execution loop
        // 5. Save assistant response
        // 6. Mark complete or failed
    }
}
```

---

## Model Repository

Centralized model management with caching:

```php
class ModelRepository
{
    // Get all active models (cached 5 minutes)
    public function all(): Collection;

    // Get models for a provider
    public function forProvider(string $provider): Collection;

    // Find by model_id
    public function findByModelId(string $modelId): ?AiModel;

    // Get context window
    public function getContextWindow(string $modelId): int;

    // Calculate cost
    public function calculateCost(string $modelId, int $input, int $output): ?float;

    // Check capabilities
    public function supports(string $modelId, string $capability): bool;

    // Available providers
    public function getProviders(): array;
}
```

---

## Tool System

### Tool Interface

```php
interface ToolInterface
{
    public function execute(array $input, ExecutionContext $context): ToolResult;
}
```

### Implemented Tools

| Tool | Description |
|------|-------------|
| `ReadTool` | Read file contents with line numbers |
| `WriteTool` | Write/create files |
| `EditTool` | String replacement in files |
| `BashTool` | Execute shell commands |
| `GrepTool` | Search patterns with ripgrep |
| `GlobTool` | Find files by pattern |

### ToolRegistry

```php
class ToolRegistry
{
    public function register(Tool $tool): void;
    public function get(string $name): ?Tool;
    public function getDefinitions(): array;  // For API tools array
    public function execute(string $name, array $input, ExecutionContext $context): ToolResult;
}
```

### ExecutionContext

```php
class ExecutionContext
{
    public function __construct(string $workingDirectory);
    public function getWorkingDirectory(): string;
    public function resolvePath(string $path): string;
    public function isPathAllowed(string $path): bool;  // Security check
}
```

---

## API Endpoints

### V2 Conversation API

```
GET  /api/v2/providers                           - List available providers
POST /api/v2/conversations                       - Create conversation
GET  /api/v2/conversations                       - List conversations
GET  /api/v2/conversations/{uuid}                - Get with messages
DELETE /api/v2/conversations/{uuid}              - Delete conversation
GET  /api/v2/conversations/{uuid}/status         - Token usage & context
POST /api/v2/conversations/{uuid}/stream         - Start streaming (dispatches job)
GET  /api/v2/conversations/{uuid}/stream-status  - Check stream status
GET  /api/v2/conversations/{uuid}/stream-events  - SSE endpoint for events
POST /api/v2/conversations/{uuid}/archive        - Archive conversation
POST /api/v2/conversations/{uuid}/unarchive      - Unarchive conversation
```

### Settings API

```
GET  /api/v2/settings/chat-defaults              - Get default settings
POST /api/v2/settings/chat-defaults              - Update defaults
```

### Pricing API

```
GET  /api/pricing                                - List all model pricing
GET  /api/pricing/{modelId}                      - Get model pricing
POST /api/pricing/{modelId}                      - Update model pricing
```

---

## Configuration

### `config/ai.php`

```php
return [
    'default_provider' => env('AI_PROVIDER', 'anthropic'),

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
        'claude_code' => [
            'binary_path' => env('CLAUDE_BINARY_PATH', 'claude'),
            'timeout' => (int) env('CLAUDE_TIMEOUT', 300),
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

    'response' => [
        'levels' => [
            0 => ['name' => 'Short', 'tokens' => 4000],
            1 => ['name' => 'Normal', 'tokens' => 8192],
            2 => ['name' => 'Long', 'tokens' => 16000],
            3 => ['name' => 'Very Long', 'tokens' => 32000],
        ],
        'default_level' => 1,
    ],

    'tools' => [
        'enabled' => ['Read', 'Write', 'Edit', 'Bash', 'Grep', 'Glob'],
        'timeout' => (int) env('TOOL_TIMEOUT', 120),
        'max_output_length' => (int) env('TOOL_MAX_OUTPUT', 30000),
    ],

    // Context windows are managed in ai_models database table
    // This is a fallback for unknown models
    'context_windows' => [
        'default' => 128000,
    ],

    'streaming' => [
        'temp_path' => env('STREAM_TEMP_PATH', storage_path('app/streams')),
        'cleanup_after' => (int) env('STREAM_CLEANUP_AFTER', 3600),
    ],
];
```

---

## File Structure

```
app/
├── Contracts/
│   ├── AIProviderInterface.php
│   └── ToolInterface.php
├── Http/Controllers/Api/
│   ├── ConversationController.php
│   ├── PricingController.php
│   └── SettingsController.php
├── Jobs/
│   └── ProcessConversationStream.php
├── Models/
│   ├── AiModel.php
│   ├── Conversation.php
│   ├── Message.php
│   └── Setting.php
├── Providers/
│   └── AIServiceProvider.php
├── Services/
│   ├── ModelRepository.php
│   ├── ProviderFactory.php
│   ├── StreamManager.php
│   ├── SystemPromptBuilder.php
│   ├── ToolRegistry.php
│   └── Providers/
│       ├── AnthropicProvider.php
│       └── OpenAIProvider.php
├── Streaming/
│   ├── SseWriter.php
│   └── StreamEvent.php
└── Tools/
    ├── Tool.php
    ├── ToolResult.php
    ├── ExecutionContext.php
    ├── BashTool.php
    ├── EditTool.php
    ├── GlobTool.php
    ├── GrepTool.php
    ├── ReadTool.php
    └── WriteTool.php

config/
└── ai.php

database/
├── migrations/
│   ├── 2025_12_04_000001_create_conversations_table.php
│   ├── 2025_12_04_000002_create_messages_table.php
│   ├── 2025_12_07_132944_create_ai_models_table.php
│   └── 2025_12_07_215552_create_settings_table.php
└── seeders/
    └── AiModelSeeder.php

resources/views/
├── chat-v2.blade.php
└── partials/chat-v2/
    ├── sidebar.blade.php
    ├── mobile-layout.blade.php
    ├── input-desktop.blade.php
    ├── input-mobile.blade.php
    ├── modals.blade.php
    ├── modals/
    │   ├── quick-settings.blade.php
    │   ├── pricing-settings.blade.php
    │   ├── cost-breakdown.blade.php
    │   ├── openai-key.blade.php
    │   ├── shortcuts.blade.php
    │   └── error.blade.php
    └── messages/
        ├── assistant-message.blade.php
        ├── user-message.blade.php
        ├── thinking-block.blade.php
        ├── tool-block.blade.php
        └── empty-response.blade.php
```

---

## Implementation Notes

### Stream Buffer Size
Set to 64 bytes in `AnthropicProvider::streamRequest()` for smooth character-by-character streaming.

### nginx Configuration
`fastcgi_buffering off` required for SSE support.

### HTTP Client
Uses Guzzle for API calls (replaced curl due to API key escaping issues).

### Redis Requirements
- Redis pub/sub for real-time event delivery
- Redis lists for event buffering and reconnection support

---

## Known Issues

| Issue | Status | Description |
|-------|--------|-------------|
| Stream stops mid-response | 🔴 Investigating | Long responses may stop streaming partway through |

---

## Future Considerations

1. **Tool permissions**: Implement permission system like Claude Code's `acceptEdits`
2. **Sandboxing**: Consider additional tool execution sandboxing
3. **Rate limiting**: Handle API rate limits gracefully
4. **Error recovery**: Resume after failed tool execution
5. **Redis Streams**: Consider XREAD/XREADGROUP for better reliability than pub/sub

---

## References

- [Anthropic Messages API](https://docs.anthropic.com/en/api/messages)
- [Anthropic Streaming](https://docs.anthropic.com/en/api/messages-streaming)
- [OpenAI Responses API](https://platform.openai.com/docs/api-reference/responses)
