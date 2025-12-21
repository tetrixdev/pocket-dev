<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryEmbedding extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'object_id',
        'field_path',
        'content_hash',
        'embedding',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(MemoryObject::class, 'object_id');
    }

    /**
     * Set the embedding vector.
     * Accepts an array of floats and converts to pgvector format.
     *
     * @param array<float> $vector
     */
    public function setEmbeddingAttribute(array $vector): void
    {
        // Convert array to pgvector string format: [0.1,0.2,0.3,...]
        $this->attributes['embedding'] = '[' . implode(',', $vector) . ']';
    }

    /**
     * Get the embedding vector as an array of floats.
     *
     * @return array<float>|null
     */
    public function getEmbeddingAttribute(): ?array
    {
        $value = $this->attributes['embedding'] ?? null;

        if ($value === null) {
            return null;
        }

        // Parse pgvector format: [0.1,0.2,0.3,...]
        $value = trim($value, '[]');
        if (empty($value)) {
            return [];
        }

        return array_map('floatval', explode(',', $value));
    }

    /**
     * Check if the content has changed by comparing hashes.
     */
    public function hasContentChanged(string $newHash): bool
    {
        return $this->content_hash !== $newHash;
    }

    /**
     * Generate a hash for the given content.
     */
    public static function hashContent(string $content): string
    {
        return hash('sha256', $content);
    }
}
