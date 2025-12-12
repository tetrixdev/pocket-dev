<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';
    public const ROLE_TOOL = 'tool'; // OpenAI uses this for tool results

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'input_tokens',
        'output_tokens',
        'cache_creation_tokens',
        'cache_read_tokens',
        'stop_reason',
        'model',
        'sequence',
        'created_at',
    ];

    protected $casts = [
        'content' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cache_creation_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Message $message) {
            if (empty($message->created_at)) {
                $message->created_at = now();
            }

            // Auto-assign sequence if not set
            if (empty($message->sequence)) {
                $message->sequence = $message->conversation->getNextSequence();
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    public function isSystem(): bool
    {
        return $this->role === self::ROLE_SYSTEM;
    }

    public function isTool(): bool
    {
        return $this->role === self::ROLE_TOOL;
    }

    public function getTotalTokens(): int
    {
        return ($this->input_tokens ?? 0) + ($this->output_tokens ?? 0);
    }

    /**
     * Get text content from the message.
     * Works with both Anthropic (content array) and OpenAI (content string) formats.
     */
    public function getTextContent(): string
    {
        $content = $this->content;

        // OpenAI: content is a string
        if (is_string($content)) {
            return $content;
        }

        // Anthropic: content is array of blocks
        if (is_array($content)) {
            $texts = [];
            foreach ($content as $block) {
                if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    $texts[] = $block['text'];
                }
            }
            return implode("\n", $texts);
        }

        return '';
    }
}
