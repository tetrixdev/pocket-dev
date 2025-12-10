<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'response_level',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'total_input_tokens' => 'integer',
        'total_output_tokens' => 'integer',
        'anthropic_thinking_budget' => 'integer',
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

    public function getNextSequence(): int
    {
        return ($this->messages()->max('sequence') ?? 0) + 1;
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
            default => [],
        };
    }
}
