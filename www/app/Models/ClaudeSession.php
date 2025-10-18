<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaudeSession extends Model
{
    protected $fillable = [
        'title',
        'project_path',
        'messages',
        'context',
        'model',
        'turn_count',
        'status',
        'last_activity_at',
    ];

    protected $casts = [
        'messages' => 'array',
        'context' => 'array',
        'last_activity_at' => 'datetime',
    ];

    public function addMessage(string $role, mixed $content): void
    {
        $messages = $this->messages ?? [];
        $messages[] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => now()->toISOString(),
        ];
        $this->messages = $messages;
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
}
