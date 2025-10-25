<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClaudeSession extends Model
{
    protected $fillable = [
        'title',
        'project_path',
        'claude_session_id',
        'model',
        'turn_count',
        'status',
        'last_activity_at',
        'process_pid',
        'process_status',
        'last_message_index',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
    ];

    /**
     * Boot the model and generate UUID for claude_session_id if not set.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($session) {
            if (empty($session->claude_session_id)) {
                $session->claude_session_id = (string) Str::uuid();
            }
        });
    }

    public function incrementTurn(): void
    {
        $this->turn_count++;
        $this->last_activity_at = now();
        $this->save();
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }

    public function markFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('last_activity_at', '>=', now()->subDays($days));
    }

    /**
     * Start streaming process.
     */
    public function startStreaming(int $pid): void
    {
        $this->update([
            'process_pid' => $pid,
            'process_status' => 'streaming',
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Update process status.
     */
    public function updateProcessStatus(string $status): void
    {
        $this->update([
            'process_status' => $status,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Increment message index.
     */
    public function incrementMessageIndex(): void
    {
        $this->increment('last_message_index');
        $this->touch('last_activity_at');
    }

    /**
     * Mark streaming as completed.
     * Note: This only marks the PROCESS as completed, not the session.
     * The session remains 'active' for multiple turns of conversation.
     */
    public function completeStreaming(): void
    {
        $this->update([
            'process_status' => 'completed',
            'process_pid' => null,
            // Don't change session status - it should remain 'active' for multi-turn conversations
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Mark streaming as cancelled.
     */
    public function cancelStreaming(): void
    {
        $this->update([
            'process_status' => 'cancelled',
            'process_pid' => null,
        ]);
    }

    /**
     * Check if process is currently streaming.
     */
    public function isStreaming(): bool
    {
        return in_array($this->process_status, ['starting', 'streaming']);
    }
}
