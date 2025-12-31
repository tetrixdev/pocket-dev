<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationTurnEmbedding extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'turn_number',
        'chunk_number',
        'embedding',
        'content_preview',
        'content_hash',
        'failed_at',
        'created_at',
    ];

    protected $casts = [
        'turn_number' => 'integer',
        'chunk_number' => 'integer',
        // Note: embedding is stored as PostgreSQL vector type, not JSON array
        // Use raw DB queries for insert/update, not Eloquent
        'failed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
