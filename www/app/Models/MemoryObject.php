<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemoryObject extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'structure_id',
        'structure_slug',
        'name',
        'data',
        'searchable_text',
        'parent_id',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (MemoryObject $object) {
            // Denormalize structure_slug from structure
            if (empty($object->structure_slug) && $object->structure_id) {
                $structure = MemoryStructure::find($object->structure_id);
                if ($structure) {
                    $object->structure_slug = $structure->slug;
                }
            }
        });
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(MemoryStructure::class, 'structure_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MemoryObject::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MemoryObject::class, 'parent_id');
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(MemoryEmbedding::class, 'object_id');
    }

    /**
     * Get relationships where this object is the source.
     */
    public function outgoingRelationships(): HasMany
    {
        return $this->hasMany(MemoryRelationship::class, 'source_id');
    }

    /**
     * Get relationships where this object is the target.
     */
    public function incomingRelationships(): HasMany
    {
        return $this->hasMany(MemoryRelationship::class, 'target_id');
    }

    /**
     * Get a specific field value from the data JSONB.
     */
    public function getField(string $fieldPath): mixed
    {
        $parts = explode('.', $fieldPath);
        $value = $this->data;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Set a specific field value in the data JSONB.
     */
    public function setField(string $fieldPath, mixed $value): void
    {
        $parts = explode('.', $fieldPath);
        $data = $this->data ?? [];
        $current = &$data;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
            } else {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }

        $this->data = $data;
    }

    /**
     * Build searchable text from embeddable fields.
     */
    public function buildSearchableText(): string
    {
        $structure = $this->structure;
        if (!$structure) {
            return $this->name;
        }

        $parts = [$this->name];
        $embeddableFields = $structure->getEmbeddableFields();

        foreach ($embeddableFields as $fieldPath) {
            $value = $this->getField($fieldPath);
            if (is_string($value) && !empty($value)) {
                $parts[] = $value;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Update searchable text based on current data.
     */
    public function refreshSearchableText(): void
    {
        $this->searchable_text = $this->buildSearchableText();
        $this->saveQuietly();
    }
}
