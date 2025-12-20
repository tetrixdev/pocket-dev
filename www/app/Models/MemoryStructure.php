<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MemoryStructure extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'schema',
        'icon',
        'color',
    ];

    protected $casts = [
        'schema' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (MemoryStructure $structure) {
            if (empty($structure->slug)) {
                $structure->slug = Str::slug($structure->name);
            }
        });
    }

    public function objects(): HasMany
    {
        return $this->hasMany(MemoryObject::class, 'structure_id');
    }

    /**
     * Get the fields that should be embedded based on x-embed markers in the schema.
     *
     * @return array<string> Field paths that have x-embed: true
     */
    public function getEmbeddableFields(): array
    {
        $fields = [];
        $properties = $this->schema['properties'] ?? [];

        foreach ($properties as $fieldName => $fieldDef) {
            if (!empty($fieldDef['x-embed'])) {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /**
     * Get the required fields from the schema.
     *
     * @return array<string>
     */
    public function getRequiredFields(): array
    {
        return $this->schema['required'] ?? [];
    }

    /**
     * Get the field definitions from the schema.
     *
     * @return array<string, array>
     */
    public function getFieldDefinitions(): array
    {
        return $this->schema['properties'] ?? [];
    }
}
