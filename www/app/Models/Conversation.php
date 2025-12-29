<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Conversation extends Model
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'agent_id',
        'provider_type',
        'model',
        'title',
        'working_directory',
        'total_input_tokens',
        'total_output_tokens',
        'status',
        'last_activity_at',
        // Provider-specific reasoning settings
        'anthropic_thinking_budget',
        'openai_reasoning_effort',
        'openai_compatible_reasoning_effort',
        'claude_code_thinking_tokens',
        'codex_reasoning_effort',
        'response_level',
        // Claude Code session management
        'claude_session_id',
        // Codex session management
        'codex_session_id',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'total_input_tokens' => 'integer',
        'total_output_tokens' => 'integer',
        'anthropic_thinking_budget' => 'integer',
        'claude_code_thinking_tokens' => 'integer',
        'response_level' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Conversation $conversation) {
            if (empty($conversation->uuid)) {
                $conversation->uuid = (string) Str::uuid();
            }
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('sequence');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function startProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'last_activity_at' => now(),
        ]);
    }

    public function completeProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_IDLE,
            'last_activity_at' => now(),
        ]);
    }

    public function markFailed(): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'last_activity_at' => now(),
        ]);
    }

    public function archive(): void
    {
        $this->update(['status' => self::STATUS_ARCHIVED]);
    }

    public function unarchive(): void
    {
        $this->update(['status' => self::STATUS_IDLE]);
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function addTokenUsage(int $inputTokens, int $outputTokens): void
    {
        $this->increment('total_input_tokens', $inputTokens);
        $this->increment('total_output_tokens', $outputTokens);
    }

    /**
     * Get the next sequence number for a message in this conversation.
     *
     * Uses a database transaction with row locking to prevent race conditions
     * when multiple messages are created concurrently for the same conversation.
     *
     * LIMITATION: The sequence is calculated inside a transaction, but the actual
     * message insert happens outside this method (by the caller). This means the
     * lock is released before the insert completes. While this creates a potential
     * race condition window, the unique constraint on (conversation_id, sequence)
     * will catch any duplicate sequences and cause an error. For typical usage
     * patterns (single client per conversation), this is sufficient.
     */
    public function getNextSequence(): int
    {
        return DB::transaction(function () {
            // Lock the conversation row to prevent concurrent sequence assignment
            DB::table('conversations')
                ->where('id', $this->id)
                ->lockForUpdate()
                ->first();

            // Get the max sequence within the transaction
            return ($this->messages()->max('sequence') ?? 0) + 1;
        });
    }

    public function scopeActive($query)
    {
        // Include STATUS_FAILED so users can continue conversations after errors
        return $query->whereIn('status', [self::STATUS_IDLE, self::STATUS_PROCESSING, self::STATUS_FAILED]);
    }

    public function scopeArchived($query)
    {
        return $query->where('status', self::STATUS_ARCHIVED);
    }

    public function scopeForProvider($query, string $providerType)
    {
        return $query->where('provider_type', $providerType);
    }

    /**
     * Get provider-specific reasoning configuration.
     *
     * Returns reasoning settings stored directly on this conversation instance.
     * These settings are copied from the agent at conversation creation time
     * (see ConversationController::store) and remain fixed for the conversation's lifetime.
     *
     * This method does NOT check the agent relationship - it only reads the conversation's
     * own fields. This is intentional as conversations can exist without agents (legacy mode)
     * and conversation settings should not change if the agent is modified later.
     *
     * Provider-specific settings:
     * - Anthropic: budget_tokens (explicit token allocation)
     * - OpenAI: effort (none/low/medium/high)
     * - OpenAI Compatible: effort (none/low/medium/high) - may be ignored by some servers
     * - Claude Code: thinking_tokens (via MAX_THINKING_TOKENS env var)
     * - Codex: effort (none/low/medium/high) - for o-series model reasoning
     */
    public function getReasoningConfig(): array
    {
        return match ($this->provider_type) {
            'anthropic' => [
                'budget_tokens' => $this->anthropic_thinking_budget ?? 0,
            ],
            'openai' => [
                'effort' => $this->openai_reasoning_effort ?? 'none',
            ],
            'openai_compatible' => [
                'effort' => $this->openai_compatible_reasoning_effort ?? 'none',
            ],
            'claude_code' => [
                'thinking_tokens' => $this->claude_code_thinking_tokens ?? 0,
            ],
            'codex' => [
                'effort' => $this->codex_reasoning_effort ?? 'none',
            ],
            default => [],
        };
    }
}
