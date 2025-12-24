# PocketDev Philosophy & Design Principles

## TLDR

PocketDev follows these core principles:

1. **Single Source of Truth** - One place for each piece of information
2. **Category-Based Simplicity** - Simple rules over complex configurations
3. **Auto-Discovery Over Registration** - Convention over configuration
4. **Enums for Constants** - Type-safe, documented, with helper methods
5. **Minimal Viable Abstraction** - Don't abstract until you must

---

## Core Philosophy

### "Make the Simple Things Simple, Make the Complex Things Possible"

PocketDev is designed for developers who want to use AI assistants without fighting configuration. The default experience should "just work" while allowing deep customization for power users.

**In practice:**
- New conversations get sensible tool defaults
- CLI providers (Claude Code, Codex) automatically exclude file operations (they have native equivalents)
- API providers (Anthropic, OpenAI) automatically include all tools
- No manual tool configuration required for typical use

---

## Design Principles

### 1. Single Source of Truth

Every piece of information should have exactly one authoritative location.

**Examples:**

| Concept | Single Source | NOT scattered across |
|---------|---------------|---------------------|
| Provider identifiers | `App\Enums\Provider` | Multiple model constants |
| Tool definitions | PHP `Tool` classes | Database + config files |
| Category constants | `PocketTool::CATEGORY_*` | Hardcoded strings |

**Why:** Eliminates sync issues, makes refactoring safe, improves discoverability.

**Anti-pattern we fixed:**
```php
// BAD: Provider constants in multiple places
class Agent { const PROVIDER_CLAUDE_CODE = 'claude_code'; }
class PocketTool { const PROVIDER_CLAUDE_CODE = 'claude_code'; }

// GOOD: Single enum
enum Provider: string {
    case ClaudeCode = 'claude_code';
}
```

---

### 2. Category-Based Simplicity

Use simple categorical rules rather than per-item configuration.

**Examples:**

| Instead of | We use |
|------------|--------|
| `excluded_providers` per tool | `file_ops` category excluded for CLI providers |
| `native_equivalent` mappings | CLI providers have native file tools, period |
| Provider-specific tool lists | Category filtering in `ToolSelector` |

**The Rule:**
- CLI providers (Claude Code, Codex) → exclude `file_ops` category
- API providers (Anthropic, OpenAI) → include everything

**Why:**
- Fewer edge cases to maintain
- New tools automatically get correct behavior
- Easy to understand and debug

**Implementation:**
```php
public function getDefaultTools(string $provider): Collection
{
    $providerEnum = Provider::tryFrom($provider);

    if ($providerEnum?->isCliProvider()) {
        return $tools->filter(fn($t) => $t->category !== 'file_ops');
    }

    return $tools;
}
```

---

### 3. Auto-Discovery Over Registration

Classes should be found automatically, not manually registered.

**Examples:**

| What | How |
|------|-----|
| Tool classes | Symfony Finder scans `app/Tools/*Tool.php` |
| Artisan commands | Laravel's auto-discovery |
| User tools | Database query wrapped in `UserTool` |

**Why:**
- Adding a new tool = create a file (no other steps)
- No "forgot to register" bugs
- Impossible to have orphaned registrations

**Implementation:**
```php
// AIServiceProvider.php
private function discoverAndRegisterTools(ToolRegistry $registry): void
{
    $finder = new Finder();
    $finder->files()->in(app_path('Tools'))->name('*Tool.php');

    foreach ($finder as $file) {
        // Reflect and register if it's a Tool subclass
    }
}
```

---

### 4. Enums for Constants

Use PHP 8.1 enums instead of class constants for categorical values.

**Benefits:**
- Type safety (IDE catches typos)
- Helper methods attached to values
- Easy iteration over all cases
- Self-documenting code

**Example - Provider Enum:**
```php
enum Provider: string
{
    case Anthropic = 'anthropic';
    case OpenAI = 'openai';
    case ClaudeCode = 'claude_code';
    case Codex = 'codex';
    case OpenAICompatible = 'openai_compatible';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::ClaudeCode => 'Claude Code',
            // ...
        };
    }

    public function isCliProvider(): bool
    {
        return match ($this) {
            self::ClaudeCode, self::Codex => true,
            default => false,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

**Usage:**
```php
// Type-safe
$provider = Provider::ClaudeCode;

// Get string value for database/API
$value = Provider::ClaudeCode->value; // 'claude_code'

// Check categories
if ($provider->isCliProvider()) { ... }

// Display name
echo $provider->label(); // 'Claude Code'

// Iterate all
foreach (Provider::cases() as $p) { ... }
```

---

### 5. Minimal Viable Abstraction

Don't create abstractions until you have at least 3 concrete use cases.

**We removed these over-engineered concepts:**

| Removed | Why |
|---------|-----|
| `excluded_providers` | Category-based filtering is simpler |
| `native_equivalent` | Not actually used for any logic |
| `capability` | Categories serve the same purpose |
| `providers` in Tool class | All tools available for all providers |
| `isAvailableFor()` method | Replaced by category filtering |

**What we kept:**
- `category` - Actually used for filtering and display
- `source` - Distinguishes pocketdev vs user tools
- `enabled` - User can disable tools

---

## Tool System Philosophy

### Tools Are Classes, Not Configuration

Each tool is a PHP class that contains everything about that tool:

```php
class MemoryCreateTool extends Tool
{
    public string $name = 'MemoryCreate';
    public string $description = 'Create a new memory object';
    public string $category = 'memory';

    public ?string $instructions = <<<'MD'
    Use MemoryCreate to create new memory objects.

    ## Example
    {"structure": "character", "name": "Thorin", "data": {...}}
    MD;

    public array $inputSchema = [...];

    public function execute(array $input, ExecutionContext $ctx): ToolResult
    {
        // Implementation
    }

    public function getArtisanCommand(): ?string
    {
        return 'memory:create';
    }
}
```

**Why:**
- Everything about the tool in one place
- Tool instructions (for AI system prompt) live with the code
- Easy to understand a tool by reading one file
- Artisan commands are thin wrappers that delegate to tools

### User Tools Are First-Class Citizens

User-created bash script tools are wrapped in `UserTool` to behave exactly like PHP tools:

```php
class UserTool extends Tool
{
    public function __construct(PocketTool $pocketTool)
    {
        $this->name = $pocketTool->name;
        $this->description = $pocketTool->description;
        $this->instructions = $pocketTool->system_prompt;
        // ...
    }

    public function execute(array $input, ExecutionContext $ctx): ToolResult
    {
        // Run bash script with TOOL_* environment variables
    }
}
```

**Why:**
- `ToolRegistry` and `ToolSelector` work with `Tool` interface
- No special cases for "database tools" vs "code tools"
- AI sees them identically

---

## Provider Philosophy

### CLI Providers Are Special

Claude Code and Codex are CLI-based providers with their own native tools. PocketDev respects this:

1. **Don't duplicate native capabilities** - No `file_ops` tools for CLI providers
2. **Inject via system prompt** - PocketDev tools become artisan commands
3. **Let the CLI do file operations** - It's better at it

### API Providers Need Everything

Anthropic and OpenAI APIs don't have native tools, so PocketDev provides them:

1. **Include file operations** - Read, Write, Edit, Glob, Grep, Bash
2. **Include memory tools** - Same as CLI providers
3. **Inject as function definitions** - Using the provider's tool format

### Provider Enum Is the Source of Truth

```php
// Check if provider has native tools
Provider::ClaudeCode->isCliProvider() // true
Provider::Anthropic->isCliProvider()  // false

// Get display name
Provider::ClaudeCode->label() // "Claude Code"

// All provider values
Provider::values() // ['anthropic', 'openai', 'claude_code', ...]
```

---

## Memory System Philosophy

### PostgreSQL Is Enough

We don't use a separate vector database. PostgreSQL + pgvector provides:

- **Transactional integrity** - Data and embeddings update atomically
- **Hybrid queries** - SQL filters + vector similarity in one query
- **Single backup** - One database to manage
- **Sufficient scale** - pgvector handles millions of vectors

### Schemas Are User-Defined

Users create structures (schemas) that define what objects look like:

```json
{
  "name": "Character",
  "schema": {
    "type": "object",
    "properties": {
      "class": { "type": "string" },
      "backstory": { "type": "string", "x-embed": true }
    }
  }
}
```

**Why:**
- AI can create schemas for any use case
- No migrations needed for schema changes
- JSONB handles flexible data

### Relationships Are IDs

Relationships between objects are stored as UUID fields in the data:

```json
{
  "name": "Magic Sword",
  "owner_id": "uuid-of-character",
  "location_id": "uuid-of-place"
}
```

**Why:**
- Simple to query with JSONB operators
- No join tables to maintain
- AI understands ID references intuitively

---

## What We Avoid

### Over-Configuration

**Bad:** Every tool has `excluded_providers`, `native_equivalent`, `capability`, `providers` fields.

**Good:** Tools have a `category`. Categories determine behavior.

### Scattered Constants

**Bad:** Provider strings defined in Agent, PocketTool, ToolSelector, and controllers.

**Good:** Single `Provider` enum, referenced everywhere.

### Manual Registration

**Bad:** Adding a tool requires updating a config file, seeder, and service provider.

**Good:** Create `app/Tools/MyTool.php` and it works.

### Premature Abstraction

**Bad:** Building a complex plugin system before having plugins.

**Good:** Simple `Tool` base class. Add complexity when needed.

---

## Summary

PocketDev's philosophy can be summarized as:

1. **Keep it simple** - Simple rules beat complex configuration
2. **One truth** - Each concept has one authoritative location
3. **Convention** - Follow patterns, auto-discover, minimize boilerplate
4. **Type safety** - Enums over strings, interfaces over magic
5. **Pragmatism** - Build what's needed, not what might be needed
