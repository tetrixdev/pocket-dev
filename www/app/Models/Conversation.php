<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        'response_level',
        // Claude Code session management
        'claude_session_id',
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
        return $query->whereIn('status', [self::STATUS_IDLE, self::STATUS_PROCESSING]);
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
     * Returns the appropriate reasoning settings based on the conversation's provider type.
     * - Anthropic: uses budget_tokens (explicit token allocation)
     * - OpenAI: uses effort (none/low/medium/high)
     * - OpenAI Compatible: uses effort (none/low/medium/high) - may be ignored by some servers
     * - Claude Code: uses thinking_tokens (via MAX_THINKING_TOKENS env var)
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
            default => [],
        };
    }
}
