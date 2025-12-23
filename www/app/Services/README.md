# Services Directory

This directory contains application services that encapsulate business logic and provide reusable functionality across controllers, jobs, and other parts of the application.

## Services Overview

### AI Provider Services (`Providers/`)

Provider implementations for different AI backends:

- **`AnthropicProvider`** - Direct Anthropic API integration
- **`OpenAIProvider`** - OpenAI API integration (including GPT and o1 models)
- **`OpenAICompatibleProvider`** - Generic OpenAI-compatible API integration
- **`ClaudeCodeProvider`** - Claude Code CLI integration (subprocess-based)
- **`CodexProvider`** - OpenAI Codex CLI integration

### Tool Services

- **`ToolRegistry`** - Registers and manages PocketDev tools for API providers
- **`ToolSelector`** - Selects appropriate tools based on provider and context
- **`NativeToolService`** - Manages native CLI tool configuration (Claude Code, Codex)

### System Services

- **`SystemPromptBuilder`** - Constructs system prompts with tool instructions
- **`SystemPromptService`** - Manages system prompt templates and customization
- **`ProviderFactory`** - Creates provider instances based on configuration
- **`StreamManager`** - Manages SSE streaming for real-time responses

### Other Services

- **`ModelRepository`** - Provides model information and availability
- **`EmbeddingService`** - Text embedding generation for semantic search
- **`TranscriptionService`** - Audio transcription via Whisper API
- **`AppSettingsService`** - Application-wide settings management

## Key Patterns

### Dependency Injection

Services are resolved via Laravel's container:

```php
// In a controller or job
$service = app(NativeToolService::class);
$tools = $service->getToolsForProvider('claude_code');
```

### Provider Pattern

AI providers implement a common interface for streaming responses:

```php
$provider = ProviderFactory::create($conversation);
$provider->streamResponse($conversation, $messages, $options);
```

## Adding New Services

1. Create the service class in this directory
2. Add appropriate docblocks explaining purpose and usage
3. If the service needs configuration, add it to `config/` directory
4. Update this README with a brief description
