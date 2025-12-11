# Provider-Specific Reasoning Settings

**Status:** Implemented
**Priority:** High
**Related PR:** [#16 - fix: Enable reasoning summaries for OpenAI Responses API](https://github.com/tetrixdev/pocket-dev/pull/16)

---

## Problem Statement

### Current Implementation

The application currently uses a **single, Anthropic-centric configuration** for reasoning/thinking:

```php
// config/ai.php
'thinking' => [
    'levels' => [
        0 => ['name' => 'Off', 'budget_tokens' => 0],
        1 => ['name' => 'Think', 'budget_tokens' => 4000],
        2 => ['name' => 'Think Hard', 'budget_tokens' => 10000],
        3 => ['name' => 'Think Harder', 'budget_tokens' => 20000],
        4 => ['name' => 'Ultrathink', 'budget_tokens' => 32000],
    ],
],
```

When using OpenAI, this `budget_tokens` value is **arbitrarily mapped** to OpenAI's `effort` parameter:

```php
// OpenAIProvider.php - CURRENT (PROBLEMATIC)
private function mapThinkingToEffort(int $tokens): string
{
    if ($tokens >= 20000) return 'high';
    if ($tokens >= 10000) return 'medium';
    return 'low';
}
```

### Why This Is Wrong

**Anthropic and OpenAI use fundamentally different reasoning models:**

| Aspect | Anthropic | OpenAI |
|--------|-----------|--------|
| **Control mechanism** | `budget_tokens` - explicit token allocation | `effort` - abstract effort level |
| **What it means** | "Use exactly N tokens for thinking" | "Try this hard" (model decides tokens) |
| **Token accounting** | Counts against `max_tokens` | Internal, separate budget |
| **Visibility control** | Thinking always visible when enabled | Separate `summary` parameter |

**OpenAI's `reasoning` object has TWO independent parameters:**

```json
{
  "reasoning": {
    "effort": "medium",      // How hard to think (none/low/medium/high)
    "summary": "auto"        // What to show user (concise/detailed/auto/omit)
  }
}
```

The current mapping is **semantically meaningless** - converting "10000 tokens" to "medium effort" doesn't translate to equivalent behavior because they're different concepts entirely.

### User Impact

1. **Misleading UI**: Labels like "Think Hard" and "Ultrathink" are Anthropic-specific and don't reflect what OpenAI actually does
2. **No fine-grained control**: Users can't independently control OpenAI's `effort` vs `summary`
3. **Inconsistent experience**: Same UI setting produces very different behavior across providers
4. **CodeRabbit flagged this as a Major issue** in PR #16

---

## Proposed Solution

### Design Principles

1. **Provider-specific settings** - Each provider gets its own reasoning configuration
2. **Stored per-conversation** - Settings persist when switching between conversations
3. **Sensible defaults** - New conversations inherit from saved defaults
4. **Clear UI** - Different controls shown based on selected provider

### Data Model Changes

#### New Database Columns

Add provider-specific reasoning columns to the `conversations` table:

```php
// Migration: add_reasoning_settings_to_conversations_table.php
Schema::table('conversations', function (Blueprint $table) {
    // Anthropic-specific
    $table->unsignedInteger('anthropic_thinking_budget')->nullable();

    // OpenAI-specific
    $table->string('openai_reasoning_effort', 20)->nullable();  // none/low/medium/high
    $table->string('openai_reasoning_summary', 20)->nullable(); // concise/detailed/auto/null

    // Shared
    $table->unsignedSmallInteger('response_level')->default(1);
});
```

#### Updated Conversation Model

```php
// app/Models/Conversation.php
protected $fillable = [
    // ... existing
    'anthropic_thinking_budget',
    'openai_reasoning_effort',
    'openai_reasoning_summary',
    'response_level',
];

protected $casts = [
    'anthropic_thinking_budget' => 'integer',
    'response_level' => 'integer',
];

// Helper to get reasoning config for the provider
public function getReasoningConfig(): array
{
    return match ($this->provider_type) {
        'anthropic' => [
            'budget_tokens' => $this->anthropic_thinking_budget ?? 0,
        ],
        'openai' => [
            'effort' => $this->openai_reasoning_effort ?? 'none',
            'summary' => $this->openai_reasoning_summary,  // null = don't show thinking
        ],
        default => [],
    };
}
```

### Configuration Changes

#### Updated `config/ai.php`

```php
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
    'openai' => [
        'effort_levels' => [
            ['value' => 'none', 'name' => 'Off', 'description' => 'No reasoning (fastest)'],
            ['value' => 'low', 'name' => 'Light', 'description' => 'Quick reasoning'],
            ['value' => 'medium', 'name' => 'Standard', 'description' => 'Balanced reasoning'],
            ['value' => 'high', 'name' => 'Deep', 'description' => 'Thorough reasoning'],
        ],
        'summary_options' => [
            ['value' => null, 'name' => 'Hidden', 'description' => 'Don\'t show thinking'],
            ['value' => 'concise', 'name' => 'Concise', 'description' => 'Brief summary'],
            ['value' => 'detailed', 'name' => 'Detailed', 'description' => 'Full summary'],
            ['value' => 'auto', 'name' => 'Auto', 'description' => 'Best available'],
        ],
    ],
],
```

### Provider Changes

#### AnthropicProvider (minimal changes)

```php
// Already works correctly, just update to read from conversation
public function streamMessage(Conversation $conversation, array $options = []): Generator
{
    $reasoningConfig = $conversation->getReasoningConfig();
    $budgetTokens = $reasoningConfig['budget_tokens'] ?? $options['budget_tokens'] ?? 0;

    // ... existing logic using $budgetTokens
}
```

#### OpenAIProvider (significant changes)

```php
private function streamResponsesApi(Conversation $conversation, array $options): Generator
{
    $reasoningConfig = $conversation->getReasoningConfig();

    // Build reasoning object only if effort is not 'none'
    $effort = $reasoningConfig['effort'] ?? 'none';
    if ($effort !== 'none') {
        $body['reasoning'] = [
            'effort' => $effort,
        ];

        // Add summary only if user wants to see thinking
        $summary = $reasoningConfig['summary'] ?? null;
        if ($summary !== null) {
            $body['reasoning']['summary'] = $summary;
        }
    }

    // ... rest of request
}
```

**Remove the `mapThinkingToEffort()` method entirely** - no more fake mapping.

### API Changes

#### GET /api/v2/providers (updated response)

```json
{
  "providers": {
    "anthropic": {
      "name": "Anthropic",
      "available": true,
      "models": { ... },
      "reasoning_config": {
        "type": "budget_tokens",
        "levels": [
          { "name": "Off", "budget_tokens": 0 },
          { "name": "Light", "budget_tokens": 4000 },
          ...
        ]
      }
    },
    "openai": {
      "name": "OpenAI",
      "available": true,
      "models": { ... },
      "reasoning_config": {
        "type": "effort_and_summary",
        "effort_levels": [
          { "value": "none", "name": "Off" },
          { "value": "low", "name": "Light" },
          ...
        ],
        "summary_options": [
          { "value": null, "name": "Hidden" },
          { "value": "auto", "name": "Auto" },
          ...
        ]
      }
    }
  }
}
```

#### POST /api/v2/conversations (create with settings)

```json
{
  "provider": "openai",
  "model": "gpt-5.1-codex-max",
  "openai_reasoning_effort": "medium",
  "openai_reasoning_summary": "auto",
  "response_level": 1
}
```

#### PATCH /api/v2/conversations/{uuid}/settings (update settings)

```json
{
  "openai_reasoning_effort": "high",
  "openai_reasoning_summary": "detailed"
}
```

#### GET/POST /api/v2/settings/chat-defaults (updated)

```json
{
  "provider": "openai",
  "model": "gpt-5.1-codex-max",
  "anthropic_thinking_budget": 10000,
  "openai_reasoning_effort": "medium",
  "openai_reasoning_summary": "auto",
  "response_level": 1
}
```

### Frontend Changes

#### Updated Alpine.js State

```javascript
// chat-v2.blade.php
return {
    // Provider-specific reasoning state
    anthropicThinkingBudget: 0,
    openaiReasoningEffort: 'none',
    openaiReasoningSummary: null,

    // Provider reasoning configs (loaded from API)
    providerReasoningConfig: {},

    // Remove old thinkingLevel/thinkingModes - replaced by provider-specific
}
```

#### Dynamic UI Based on Provider

```html
<!-- Anthropic: Token budget slider/buttons -->
<template x-if="provider === 'anthropic'">
    <div class="flex gap-2">
        <template x-for="level in providerReasoningConfig.anthropic?.levels">
            <button
                @click="anthropicThinkingBudget = level.budget_tokens; saveSettings()"
                :class="anthropicThinkingBudget === level.budget_tokens ? 'bg-purple-600' : 'bg-gray-700'"
                x-text="level.name"
            ></button>
        </template>
    </div>
</template>

<!-- OpenAI: Effort dropdown + Summary toggle -->
<template x-if="provider === 'openai'">
    <div class="flex gap-4">
        <!-- Effort Level -->
        <div>
            <label class="text-xs text-gray-400">Reasoning Effort</label>
            <select x-model="openaiReasoningEffort" @change="saveSettings()" class="bg-gray-700 rounded px-2 py-1">
                <template x-for="level in providerReasoningConfig.openai?.effort_levels">
                    <option :value="level.value" x-text="level.name"></option>
                </template>
            </select>
        </div>

        <!-- Summary (only shown if effort != none) -->
        <div x-show="openaiReasoningEffort !== 'none'">
            <label class="text-xs text-gray-400">Show Thinking</label>
            <select x-model="openaiReasoningSummary" @change="saveSettings()" class="bg-gray-700 rounded px-2 py-1">
                <template x-for="opt in providerReasoningConfig.openai?.summary_options">
                    <option :value="opt.value" x-text="opt.name"></option>
                </template>
            </select>
        </div>
    </div>
</template>
```

#### Loading Conversation Settings

```javascript
async loadConversation(uuid) {
    const response = await fetch(`/api/v2/conversations/${uuid}`);
    const data = await response.json();

    this.currentConversationUuid = uuid;
    this.provider = data.conversation.provider_type;
    this.model = data.conversation.model;
    this.messages = data.messages;

    // Load provider-specific settings
    this.anthropicThinkingBudget = data.conversation.anthropic_thinking_budget ?? 0;
    this.openaiReasoningEffort = data.conversation.openai_reasoning_effort ?? 'none';
    this.openaiReasoningSummary = data.conversation.openai_reasoning_summary ?? null;
    this.responseLevel = data.conversation.response_level ?? 1;

    this.updateModels();
}
```

#### New Conversation (from defaults)

```javascript
startNewConversation() {
    this.currentConversationUuid = null;
    this.messages = [];

    // Reset to saved defaults (loaded in fetchSettings)
    this.provider = this.defaultSettings.provider;
    this.model = this.defaultSettings.model;
    this.anthropicThinkingBudget = this.defaultSettings.anthropic_thinking_budget ?? 0;
    this.openaiReasoningEffort = this.defaultSettings.openai_reasoning_effort ?? 'none';
    this.openaiReasoningSummary = this.defaultSettings.openai_reasoning_summary ?? null;
    this.responseLevel = this.defaultSettings.response_level ?? 1;

    this.updateModels();
}
```

### ProcessConversationStream Changes

```php
// app/Jobs/ProcessConversationStream.php
private function buildOptions(Conversation $conversation): array
{
    $reasoningConfig = $conversation->getReasoningConfig();

    return [
        'system' => $this->systemPrompt,
        'tools' => $this->getTools(),
        'response_level' => $conversation->response_level ?? 1,
        // Pass provider-specific reasoning config
        ...$reasoningConfig,
    ];
}
```

---

## Implementation Steps

### Phase 1: Database & Backend

1. **Create migration** for new columns on `conversations` table
2. **Update Conversation model** with new fields and `getReasoningConfig()` helper
3. **Update config/ai.php** with provider-specific reasoning config
4. **Update OpenAIProvider** to use native effort/summary (remove `mapThinkingToEffort`)
5. **Update AnthropicProvider** to read from conversation config
6. **Update ProcessConversationStream** to pass provider-specific options
7. **Update API endpoints**:
   - `GET /api/v2/providers` - include reasoning config per provider
   - `POST /api/v2/conversations` - accept provider-specific fields
   - `PATCH /api/v2/conversations/{uuid}/settings` - new endpoint
   - `GET/POST /api/v2/settings/chat-defaults` - include all provider settings

### Phase 2: Frontend

1. **Update Alpine.js state** - replace `thinkingLevel` with provider-specific vars
2. **Update `fetchProviders()`** - store reasoning configs
3. **Update `fetchSettings()`** - load provider-specific defaults
4. **Update `loadConversation()`** - apply conversation's saved settings
5. **Update `startNewConversation()`** - reset to defaults
6. **Create provider-specific UI components**:
   - Anthropic: Token budget buttons (similar to current)
   - OpenAI: Effort dropdown + Summary dropdown
7. **Update `sendMessage()`** - include provider-specific settings in request
8. **Update `saveDefaultSettings()`** - save all provider settings

### Phase 3: Testing & Cleanup

1. **Test Anthropic flow** - ensure thinking works as before
2. **Test OpenAI flow** - verify effort/summary combinations work:
   - `effort: none` - no thinking block
   - `effort: medium, summary: null` - reasoning but no visible thinking
   - `effort: high, summary: auto` - full thinking block
3. **Test conversation switching** - settings persist correctly
4. **Test new conversation** - defaults applied correctly
5. **Remove deprecated code**:
   - `mapThinkingToEffort()` method
   - Old `thinking_level` references (if any stored)

---

## Migration Path

For existing conversations (if any):

```php
// In migration
DB::table('conversations')
    ->where('provider_type', 'anthropic')
    ->update(['anthropic_thinking_budget' => 0]);

DB::table('conversations')
    ->where('provider_type', 'openai')
    ->update([
        'openai_reasoning_effort' => 'none',
        'openai_reasoning_summary' => null,
    ]);
```

---

## Files to Modify

| File | Changes |
|------|---------|
| `database/migrations/xxx_add_reasoning_settings.php` | **NEW** - Add columns |
| `app/Models/Conversation.php` | Add fields, casts, `getReasoningConfig()` |
| `config/ai.php` | Replace `thinking.levels` with provider-specific config |
| `app/Services/Providers/OpenAIProvider.php` | Use native effort/summary, remove mapping |
| `app/Services/Providers/AnthropicProvider.php` | Read from conversation config |
| `app/Jobs/ProcessConversationStream.php` | Pass provider-specific options |
| `app/Http/Controllers/Api/ConversationController.php` | Handle new fields |
| `app/Http/Controllers/Api/SettingsController.php` | Store provider-specific defaults |
| `app/Http/Controllers/Api/ProviderController.php` | Return reasoning configs |
| `resources/views/chat-v2.blade.php` | Provider-specific UI |
| `docs/database/schema.md` | Document new columns |

---

## Open Questions

1. **Claude Code provider** - Does it need reasoning settings? (Currently uses CLI, not API)
2. **Model-specific defaults** - Some OpenAI models default to `effort: none`, others to `medium`. Should we handle this?
3. **UI complexity** - Is two dropdowns for OpenAI (effort + summary) too complex? Could simplify to presets.

---

## References

- [OpenAI Responses API - Reasoning](https://platform.openai.com/docs/guides/reasoning)
- [Anthropic Extended Thinking](https://docs.anthropic.com/en/docs/build-with-claude/extended-thinking)
- [PR #16 - CodeRabbit Review](https://github.com/tetrixdev/pocket-dev/pull/16)
