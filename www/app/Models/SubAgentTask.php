<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubAgentTask extends Model
{
    use HasUuids;

    protected $table = 'subagent_tasks';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'parent_conversation_uuid',
        'child_conversation_uuid',
        'agent_id',
        'prompt',
        'is_background',
    ];

    protected $casts = [
        'is_background' => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function childConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'child_conversation_uuid', 'uuid');
    }

    public function parentConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'parent_conversation_uuid', 'uuid');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    // -------------------------------------------------------------------------
    // Derived methods (read from the child conversation live, no caching)
    // -------------------------------------------------------------------------

    /**
     * Get the current status of the subagent task.
     * Derived from the child conversation's status.
     */
    public function getStatus(): string
    {
        $conversation = $this->childConversation;
        if (!$conversation) {
            return 'pending';
        }
        return match ($conversation->status) {
            Conversation::STATUS_IDLE, Conversation::STATUS_ARCHIVED => 'completed',
            Conversation::STATUS_PROCESSING => 'running',
            Conversation::STATUS_FAILED => 'failed',
            default => 'pending',
        };
    }

    /**
     * Collect the output from all assistant messages in the child conversation.
     */
    public function collectOutput(): string
    {
        $conversation = $this->childConversation;
        if (!$conversation) {
            return '';
        }

        $messages = $conversation->messages()
            ->where('role', 'assistant')
            ->orderBy('sequence')
            ->get();

        $output = [];
        foreach ($messages as $message) {
            $content = $message->content;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                    $output[] = $block['text'];
                }
            }
        }

        return implode("\n\n", $output);
    }

    /**
     * Get error message if the task failed.
     */
    public function getError(): ?string
    {
        $conversation = $this->childConversation;
        if (!$conversation || $conversation->status !== Conversation::STATUS_FAILED) {
            return null;
        }

        $errorMessage = $conversation->messages()
            ->where('role', 'error')
            ->orderByDesc('sequence')
            ->first();

        if ($errorMessage) {
            $content = $errorMessage->content;
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text') {
                        return $block['text'];
                    }
                }
            }
            if (is_string($content)) {
                return $content;
            }
        }

        return 'Task failed (no error details available)';
    }
}
