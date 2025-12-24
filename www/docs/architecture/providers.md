# Provider System Architecture

## TLDR

Providers are AI backends that power conversations. PocketDev supports 5 providers with a single `Provider` enum as the source of truth.

**Two types:**
- **CLI Providers** (Claude Code, Codex) - Have native tools, PocketDev adds memory/tool management
- **API Providers** (Anthropic, OpenAI, OpenAI Compatible) - PocketDev provides all tools

---

## The Provider Enum

`App\Enums\Provider` is the single source of truth for provider identifiers:

```php
enum Provider: string
{
    case Anthropic = 'anthropic';
    case OpenAI = 'openai';
    case ClaudeCode = 'claude_code';
    case Codex = 'codex';
    case OpenAICompatible = 'openai_compatible';

    public function label(): string { ... }
    public function isCliProvider(): bool { ... }
    public function isApiProvider(): bool { ... }

    public static function values(): array { ... }
    public static function options(): array { ... }
    public static function cliProviders(): array { ... }
    public static function apiProviders(): array { ... }
}
```

### Key Methods

| Method | Purpose | Example |
|--------|---------|---------|
| `->value` | Get string value | `'claude_code'` |
| `->label()` | Get display name | `'Claude Code'` |
| `->isCliProvider()` | Check if CLI-based | `true` for ClaudeCode, Codex |
| `->isApiProvider()` | Check if API-based | `true` for Anthropic, OpenAI |
| `::values()` | All string values | `['anthropic', 'openai', ...]` |
| `::tryFrom($str)` | String to enum | `Provider::ClaudeCode` |

### Usage Examples

```php
use App\Enums\Provider;

// Type-safe provider reference
$provider = Provider::ClaudeCode;

// Get string for database/API
$value = Provider::ClaudeCode->value; // 'claude_code'

// Check provider type
if ($provider->isCliProvider()) {
    // Exclude file_ops tools
}

// Display in UI
echo $provider->label(); // 'Claude Code'

// Parse from string (e.g., from database)
$provider = Provider::tryFrom($agent->provider);
if ($provider?->isCliProvider()) { ... }

// Iterate all providers
foreach (Provider::cases() as $p) {
    echo $p->value . ': ' . $p->label();
}
```

---

## Provider Types

### CLI Providers

CLI providers run as command-line processes with their own native tools.

| Provider | CLI | Native Tools | Model Selection |
|----------|-----|--------------|-----------------|
| Claude Code | `claude` | Bash, Read, Write, Edit, Glob, Grep, Task, WebFetch, etc. | Via Claude CLI config |
| Codex | `codex` | Similar to Claude Code | Via Codex CLI config |

**Characteristics:**
- PocketDev spawns a CLI process
- Native tools are NOT provided by PocketDev
- PocketDev tools (memory, tool management) are injected via system prompt
- Tools appear as artisan commands to run via the CLI's Bash tool

**System Prompt Injection:**
```markdown
# PocketDev Tools

The following tools are available as artisan commands. Use your Bash tool to execute them.

## Memory System

### memory:create

Use MemoryCreate to create new memory objects.
...
```

### API Providers

API providers are HTTP APIs that require all tool definitions from PocketDev.

| Provider | API | Tools From | Model Selection |
|----------|-----|------------|-----------------|
| Anthropic | Anthropic API | All PocketDev tools | Per-conversation |
| OpenAI | OpenAI API | All PocketDev tools | Per-conversation |
| OpenAI Compatible | Any OpenAI-compatible API | All PocketDev tools | Per-conversation |

**Characteristics:**
- PocketDev makes HTTP requests to the API
- All tools (including file ops) provided as function definitions
- Tool calls return to PocketDev for execution
- System prompt includes tool instructions

**Tool Definition Format (Anthropic):**
```json
{
  "name": "MemoryCreate",
  "description": "Create a new memory object",
  "input_schema": {
    "type": "object",
    "properties": {
      "structure": { "type": "string" },
      "name": { "type": "string" },
      "data": { "type": "object" }
    },
    "required": ["structure", "name", "data"]
  }
}
```

---

## Tool Selection by Provider

### The Rule

```php
public function getDefaultTools(string $provider): Collection
{
    $providerEnum = Provider::tryFrom($provider);

    // CLI providers: exclude file_ops (they have native equivalents)
    if ($providerEnum?->isCliProvider()) {
        return $tools->filter(fn($t) => $t->category !== 'file_ops');
    }

    // API providers: include everything
    return $tools;
}
```

### What Each Provider Gets

| Category | CLI Providers | API Providers |
|----------|---------------|---------------|
| `memory` | Yes | Yes |
| `tools` | Yes | Yes |
| `file_ops` | **No** (native) | Yes |
| `custom` (user tools) | Yes | Yes |

### Why This Design?

1. **No Duplication** - CLI providers have native Bash/Read/Write, don't add ours
2. **Full Capability** - API providers need our file operations
3. **Simple Rule** - Category-based, not per-tool configuration
4. **Automatic** - New tools get correct behavior based on category

---

## Provider Implementation Classes

Each provider has an implementation in `app/Services/Providers/`:

```
app/Services/Providers/
├── AnthropicProvider.php      # Anthropic API
├── OpenAIProvider.php         # OpenAI API
├── OpenAICompatibleProvider.php # Generic OpenAI-compatible APIs
├── ClaudeCodeProvider.php     # Claude CLI wrapper
└── CodexProvider.php          # Codex CLI wrapper
```

### Provider Interface

All providers implement `AIProviderInterface`:

```php
interface AIProviderInterface
{
    public function getProviderType(): string;
    public function streamChat(Conversation $conversation, ...): Generator;
    public function getModels(): array;
    public function isAvailable(): bool;
}
```

### Provider Factory

`ProviderFactory` creates provider instances:

```php
class ProviderFactory
{
    private array $providers = [
        'anthropic' => AnthropicProvider::class,
        'openai' => OpenAIProvider::class,
        'claude_code' => ClaudeCodeProvider::class,
        'codex' => CodexProvider::class,
        'openai_compatible' => OpenAICompatibleProvider::class,
    ];

    public function create(string $type): AIProviderInterface
    {
        return app($this->providers[$type]);
    }
}
```

---

## System Prompt Building

### For CLI Providers

The `SystemPromptBuilder` injects PocketDev tools as artisan commands:

```php
public function buildForCliProvider(Conversation $conversation, string $provider): string
{
    $sections = [];

    // Core system prompt (customizable)
    $sections[] = $this->systemPromptService->get();

    // PocketDev tools (memory, tool management, user tools)
    $pocketDevToolPrompt = $this->toolSelector->buildSystemPrompt($provider);
    if (!empty($pocketDevToolPrompt)) {
        $sections[] = $pocketDevToolPrompt;
    }

    // Working directory context
    $sections[] = $this->buildContextSection($conversation);

    return implode("\n\n", array_filter($sections));
}
```

### For API Providers

Tools are passed as function definitions in the API request, and instructions go in the system prompt.

---

## Agent Configuration

Agents store per-provider settings:

```php
class Agent extends Model
{
    protected $fillable = [
        'name',
        'provider',              // Provider enum value
        'model',                 // Model ID
        'anthropic_thinking_budget',    // Anthropic-specific
        'openai_reasoning_effort',      // OpenAI-specific
        'claude_code_thinking_tokens',  // Claude Code-specific
        'codex_reasoning_effort',       // Codex-specific
        'allowed_tools',         // null = all, or array of slugs
        'system_prompt',         // Custom prompt addition
    ];

    public function getProviderEnum(): ?Provider
    {
        return Provider::tryFrom($this->provider);
    }

    public function getProviderDisplayName(): string
    {
        $provider = Provider::tryFrom($this->provider);
        return $provider?->label() ?? ucfirst($this->provider);
    }
}
```

---

## Adding a New Provider

1. **Add to Provider enum:**
```php
enum Provider: string
{
    // ...existing...
    case NewProvider = 'new_provider';

    public function label(): string
    {
        return match ($this) {
            // ...existing...
            self::NewProvider => 'New Provider',
        };
    }

    public function isCliProvider(): bool
    {
        return match ($this) {
            self::ClaudeCode, self::Codex, self::NewProvider => true,
            default => false,
        };
    }
}
```

2. **Create provider class:**
```php
// app/Services/Providers/NewProviderProvider.php
class NewProviderProvider implements AIProviderInterface
{
    public function getProviderType(): string
    {
        return Provider::NewProvider->value;
    }

    // Implement other methods...
}
```

3. **Register in ProviderFactory:**
```php
private array $providers = [
    // ...existing...
    'new_provider' => NewProviderProvider::class,
];
```

4. **Add to AIServiceProvider:**
```php
$providers = [
    // ...existing...
    'new_provider' => $app->make(NewProviderProvider::class),
];
```

---

## Deprecated: Model Constants

The following constants are deprecated in favor of the `Provider` enum:

```php
// DEPRECATED - don't use these
Agent::PROVIDER_ANTHROPIC    // Use Provider::Anthropic->value
Agent::PROVIDER_OPENAI       // Use Provider::OpenAI->value
Agent::PROVIDER_CLAUDE_CODE  // Use Provider::ClaudeCode->value
Agent::PROVIDER_CODEX        // Use Provider::Codex->value
```

These remain for backwards compatibility but will be removed in a future version.

---

## Summary

| Aspect | Design Choice |
|--------|---------------|
| Provider identifiers | Single `Provider` enum |
| CLI vs API | `isCliProvider()` method on enum |
| Tool filtering | Category-based in `ToolSelector` |
| System prompt | Different builders for CLI vs API |
| Configuration | Per-provider fields in Agent model |
