<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryRelationship extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'source_id',
        'target_id',
        'relationship_type',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (MemoryRelationship $relationship) {
            if (empty($relationship->created_at)) {
                $relationship->created_at = now();
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MemoryObject::class, 'source_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(MemoryObject::class, 'target_id');
    }

    /**
     * Check if a relationship already exists between two objects.
     */
    public static function exists(string $sourceId, string $targetId, string $type): bool
    {
        return static::where('source_id', $sourceId)
            ->where('target_id', $targetId)
            ->where('relationship_type', $type)
            ->exists();
    }

    /**
     * Get the inverse relationship type if one is defined.
     * For example: 'owns' -> 'owned_by', 'contains' -> 'contained_in'
     */
    public static function getInverseType(string $type): ?string
    {
        $inverses = [
            'owns' => 'owned_by',
            'owned_by' => 'owns',
            'contains' => 'contained_in',
            'contained_in' => 'contains',
            'knows' => 'known_by',
            'known_by' => 'knows',
            'parent_of' => 'child_of',
            'child_of' => 'parent_of',
            'located_in' => 'location_of',
            'location_of' => 'located_in',
            'member_of' => 'has_member',
            'has_member' => 'member_of',
        ];

        return $inverses[$type] ?? null;
    }
}
