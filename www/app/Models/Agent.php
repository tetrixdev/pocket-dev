<?php

namespace App\Models;

use App\Enums\Provider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Agent extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    // Provider constants - deprecated, use Provider enum instead
    /** @deprecated Use Provider::Anthropic->value instead */
    public const PROVIDER_ANTHROPIC = 'anthropic';
    /** @deprecated Use Provider::OpenAI->value instead */
    public const PROVIDER_OPENAI = 'openai';
    /** @deprecated Use Provider::ClaudeCode->value instead */
    public const PROVIDER_CLAUDE_CODE = 'claude_code';
    /** @deprecated Use Provider::Codex->value instead */
    public const PROVIDER_CODEX = 'codex';

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'description',
        'provider',
        'model',
        'anthropic_thinking_budget',
        'openai_reasoning_effort',
        'openai_compatible_reasoning_effort',
        'claude_code_thinking_tokens',
        'codex_reasoning_effort',
        'response_level',
        'allowed_tools',
        'inherit_workspace_tools',
        'inherit_workspace_schemas',
        'system_prompt',
        'is_default',
        'enabled',
    ];

    protected $casts = [
        'allowed_tools' => 'array',
        'inherit_workspace_tools' => 'boolean',
        'inherit_workspace_schemas' => 'boolean',
        'is_default' => 'boolean',
        'enabled' => 'boolean',
        'anthropic_thinking_budget' => 'integer',
        'claude_code_thinking_tokens' => 'integer',
        'response_level' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Agent $agent) {
            if (empty($agent->slug)) {
                $agent->slug = Str::slug($agent->name);
            }
        });

        // Ensure only one default per provider per workspace
        static::saving(function (Agent $agent) {
            if ($agent->is_default && $agent->isDirty('is_default')) {
                static::where('provider', $agent->provider)
                    ->where('workspace_id', $agent->workspace_id)
                    ->where('id', '!=', $agent->id)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Get the workspace this agent belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get conversations using this agent.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get memory databases this agent has access to.
     */
    public function memoryDatabases(): BelongsToMany
    {
        return $this->belongsToMany(MemoryDatabase::class, 'agent_memory_databases')
            ->withPivot(['permission'])
            ->withTimestamps();
    }

    /**
     * Get enabled memory databases for this agent.
     *
     * If inherit_workspace_schemas is true, returns all workspace-enabled schemas.
     * Otherwise, returns schemas that are both:
     * 1. Enabled in the agent's workspace
     * 2. Explicitly granted to the agent
     */
    public function getEnabledMemoryDatabases(): Collection
    {
        if (!$this->workspace) {
            return collect();
        }

        // If inheriting from workspace, return all workspace-enabled schemas
        if ($this->inheritsWorkspaceSchemas()) {
            return $this->workspace->enabledMemoryDatabases()->get();
        }

        // Otherwise, return only schemas explicitly granted to the agent
        // that are also enabled in the workspace
        $workspaceEnabledIds = $this->workspace
            ->enabledMemoryDatabases()
            ->pluck('memory_databases.id');

        return $this->memoryDatabases()
            ->whereIn('memory_databases.id', $workspaceEnabledIds)
            ->get();
    }

    /**
     * Check if this agent has access to a specific memory database.
     */
    public function hasMemoryDatabaseAccess(MemoryDatabase $db): bool
    {
        if (!$this->workspace || !$db->isEnabledInWorkspace($this->workspace)) {
            return false;
        }

        // If inheriting from workspace, has access to all workspace-enabled schemas
        if ($this->inheritsWorkspaceSchemas()) {
            return true;
        }

        return $this->memoryDatabases()
            ->where('memory_databases.id', $db->id)
            ->exists();
    }

    /**
     * Check if this agent can write to a specific memory database.
     */
    public function canWriteToMemoryDatabase(MemoryDatabase $db): bool
    {
        if (!$this->hasMemoryDatabaseAccess($db)) {
            return false;
        }

        // If inheriting from workspace, allow write (workspace-level permissions can be added later)
        if ($this->inheritsWorkspaceSchemas()) {
            return true;
        }

        // Otherwise check explicit permission
        $permission = $this->memoryDatabases()
            ->where('memory_databases.id', $db->id)
            ->first()
            ?->pivot
            ?->permission;

        return in_array($permission, ['write', 'admin'], true);
    }

    /**
     * Scope to filter by workspace.
     */
    public function scopeForWorkspace(Builder $query, string $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to only enabled agents.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to filter by provider.
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope to get default agent for a provider.
     *
     * @param string $provider The provider to filter by
     * @param string|null $workspaceId Optional workspace ID to scope the default to
     */
    public function scopeDefaultFor(Builder $query, string $provider, ?string $workspaceId = null): Builder
    {
        $query->where('provider', $provider)->where('is_default', true);

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        return $query;
    }

    /**
     * Get the reasoning value based on provider.
     */
    public function getReasoningValue(): mixed
    {
        return match ($this->provider) {
            Provider::Anthropic->value => $this->anthropic_thinking_budget ?? 0,
            Provider::OpenAI->value => $this->openai_reasoning_effort ?? 'none',
            Provider::OpenAICompatible->value => $this->openai_compatible_reasoning_effort ?? 'none',
            Provider::ClaudeCode->value => $this->claude_code_thinking_tokens ?? 0,
            Provider::Codex->value => $this->codex_reasoning_effort ?? 'none',
            default => null,
        };
    }

    /**
     * Get reasoning config array for API calls.
     */
    public function getReasoningConfig(): array
    {
        return match ($this->provider) {
            Provider::Anthropic->value => [
                'type' => 'anthropic',
                'budget_tokens' => $this->anthropic_thinking_budget ?? 0,
            ],
            Provider::OpenAI->value => [
                'type' => 'openai',
                'effort' => $this->openai_reasoning_effort ?? 'none',
            ],
            Provider::OpenAICompatible->value => [
                'type' => 'openai_compatible',
                'effort' => $this->openai_compatible_reasoning_effort ?? 'none',
            ],
            Provider::ClaudeCode->value => [
                'type' => 'claude_code',
                'thinking_tokens' => $this->claude_code_thinking_tokens ?? 0,
            ],
            Provider::Codex->value => [
                'type' => 'codex',
                'effort' => $this->codex_reasoning_effort ?? 'none',
            ],
            default => ['type' => 'none'],
        };
    }

    /**
     * Check if this agent inherits tools from workspace.
     */
    public function inheritsWorkspaceTools(): bool
    {
        return $this->inherit_workspace_tools ?? true;
    }

    /**
     * Check if this agent inherits schemas from workspace.
     */
    public function inheritsWorkspaceSchemas(): bool
    {
        return $this->inherit_workspace_schemas ?? false;
    }

    /**
     * Check if all tools are allowed.
     * True if inheriting from workspace OR if allowed_tools is null.
     */
    public function allowsAllTools(): bool
    {
        return $this->inheritsWorkspaceTools() || $this->allowed_tools === null;
    }

    /**
     * Check if a specific tool is allowed.
     *
     * If inheriting from workspace: checks if tool is enabled in workspace.
     * If specific tools selected: checks if tool is in allowed_tools list.
     */
    public function allowsTool(string $toolSlug): bool
    {
        // If inheriting from workspace, check workspace settings
        if ($this->inheritsWorkspaceTools()) {
            if (!$this->workspace) {
                return true; // No workspace = allow all (fallback)
            }
            return $this->workspace->isToolEnabled($toolSlug);
        }

        // If specific tools selected, check the list
        if ($this->allowed_tools === null) {
            return true; // null means all tools allowed
        }

        return in_array($toolSlug, $this->allowed_tools, true);
    }

    /**
     * Get display name for the provider.
     */
    public function getProviderDisplayName(): string
    {
        $provider = Provider::tryFrom($this->provider);
        return $provider?->label() ?? ucfirst($this->provider);
    }

    /**
     * Get all available providers.
     *
     * @return array<string>
     */
    public static function getProviders(): array
    {
        return Provider::values();
    }

    /**
     * Get the Provider enum for this agent.
     */
    public function getProviderEnum(): ?Provider
    {
        return Provider::tryFrom($this->provider);
    }
}
