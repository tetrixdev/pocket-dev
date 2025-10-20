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
}
