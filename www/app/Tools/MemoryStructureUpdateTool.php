<?php

namespace App\Tools;

use App\Models\MemoryEmbedding;
use App\Models\MemoryObject;
use App\Models\MemoryStructure;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Update an existing memory structure.
 */
class MemoryStructureUpdateTool extends Tool
{
    public string $name = 'MemoryStructureUpdate';

    public string $description = 'Update an existing memory structure (name, description, schema, icon, color).';

    public string $category = 'memory';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'The structure slug to update.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'New structure name.',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'New description.',
            ],
            'schema' => [
                'type' => 'object',
                'description' => 'New JSON Schema (use with caution - may affect existing objects).',
            ],
            'icon' => [
                'type' => 'string',
                'description' => 'New icon name.',
            ],
            'color' => [
                'type' => 'string',
                'description' => 'New hex color.',
            ],
            'regenerate_embeddings' => [
                'type' => 'boolean',
                'description' => 'Regenerate embeddings for all objects (use after changing x-embed fields).',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryStructureUpdate to modify an existing memory structure.

## CLI Example

```bash
php artisan memory:structure:update --slug=character --name="Player Character" --description="A playable character"
```

## Safe Updates
These changes have no impact on existing objects:
- name, description, icon, color

## Schema Updates (Use with Caution)
- Adding fields: Safe. Existing objects will have null values.
- Removing fields: Data in existing objects is orphaned but preserved.
- Changing types: May cause validation issues when updating existing objects.
- Changing x-embed: Use regenerate_embeddings to update vectors.

## Example

Update name and description:
```json
{
  "slug": "character",
  "name": "Player Character",
  "description": "A playable character in the game"
}
```

Update schema and regenerate embeddings:
```json
{
  "slug": "character",
  "schema": {
    "type": "object",
    "properties": {
      "class": {"type": "string"},
      "backstory": {"type": "string", "x-embed": true}
    }
  },
  "regenerate_embeddings": true
}
```
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $slug = $input['slug'] ?? '';

        if (empty($slug)) {
            return ToolResult::error('slug is required');
        }

        $structure = MemoryStructure::where('slug', $slug)->first();

        if (!$structure) {
            return ToolResult::error("Structure '{$slug}' not found");
        }

        $updates = [];
        $warnings = [];

        // Collect safe updates
        if (isset($input['name']) && $input['name'] !== '') {
            $updates['name'] = $input['name'];
        }

        if (isset($input['description'])) {
            $updates['description'] = $input['description'];
        }

        if (isset($input['icon'])) {
            $updates['icon'] = $input['icon'];
        }

        if (isset($input['color'])) {
            $updates['color'] = $input['color'];
        }

        // Handle schema updates with warnings
        if (isset($input['schema']) && is_array($input['schema'])) {
            $newSchema = $input['schema'];

            // Validate schema structure
            if (!isset($newSchema['type']) || !isset($newSchema['properties'])) {
                return ToolResult::error('Invalid JSON Schema: must include "type" and "properties"');
            }

            $objectCount = MemoryObject::where('structure_id', $structure->id)->count();

            if ($objectCount > 0) {
                $analysis = $this->analyzeSchemaChanges($structure->schema, $newSchema);

                if (!empty($analysis['removed_fields'])) {
                    $warnings[] = "Removed fields: " . implode(', ', $analysis['removed_fields']) . " (data orphaned)";
                }

                if (!empty($analysis['type_changes'])) {
                    $warnings[] = "Type changes: " . implode(', ', $analysis['type_changes']);
                }

                if ($analysis['embed_changes']) {
                    $warnings[] = "x-embed changes detected. Use regenerate_embeddings to update vectors.";
                }

                $warnings[] = "{$objectCount} existing object(s) may need updates.";
            }

            $updates['schema'] = $newSchema;
        }

        if (empty($updates)) {
            return ToolResult::error('No updates provided. Use name, description, schema, icon, or color.');
        }

        // Perform the update
        $structure->update($updates);

        // Handle embedding regeneration if requested
        $embeddingsRegenerated = 0;
        if (!empty($input['regenerate_embeddings'])) {
            $embeddingsRegenerated = $this->regenerateEmbeddings($structure);
        }

        $output = ["Updated structure: {$structure->name} ({$structure->slug})"];
        $output[] = "";
        $output[] = "Updated fields: " . implode(', ', array_keys($updates));

        if ($embeddingsRegenerated > 0) {
            $output[] = "Regenerated embeddings for {$embeddingsRegenerated} object(s).";
        }

        if (!empty($warnings)) {
            $output[] = "";
            $output[] = "Warnings:";
            foreach ($warnings as $warning) {
                $output[] = "  - {$warning}";
            }
        }

        return ToolResult::success(implode("\n", $output));
    }

    private function analyzeSchemaChanges(array $oldSchema, array $newSchema): array
    {
        $analysis = [
            'removed_fields' => [],
            'added_fields' => [],
            'type_changes' => [],
            'embed_changes' => false,
        ];

        $oldProperties = $oldSchema['properties'] ?? [];
        $newProperties = $newSchema['properties'] ?? [];

        // Find removed fields
        foreach ($oldProperties as $field => $def) {
            if (!isset($newProperties[$field])) {
                $analysis['removed_fields'][] = $field;
            }
        }

        // Find added fields and type changes
        foreach ($newProperties as $field => $def) {
            if (!isset($oldProperties[$field])) {
                $analysis['added_fields'][] = $field;
            } else {
                $oldType = $oldProperties[$field]['type'] ?? null;
                $newType = $def['type'] ?? null;
                if ($oldType !== $newType) {
                    $analysis['type_changes'][] = "{$field}: {$oldType} → {$newType}";
                }

                $oldEmbed = !empty($oldProperties[$field]['x-embed']);
                $newEmbed = !empty($def['x-embed']);
                if ($oldEmbed !== $newEmbed) {
                    $analysis['embed_changes'] = true;
                }
            }
        }

        // Check for new x-embed fields
        foreach ($newProperties as $field => $def) {
            if (!empty($def['x-embed']) && !isset($oldProperties[$field])) {
                $analysis['embed_changes'] = true;
            }
        }

        return $analysis;
    }

    private function regenerateEmbeddings(MemoryStructure $structure): int
    {
        $count = 0;
        $embeddableFields = $structure->getEmbeddableFields();

        try {
            $embeddingService = app(EmbeddingService::class);

            MemoryObject::where('structure_id', $structure->id)
                ->chunk(100, function ($objects) use ($embeddableFields, $embeddingService, &$count) {
                    foreach ($objects as $object) {
                        $newEmbeddings = [];

                        foreach ($embeddableFields as $fieldPath) {
                            $content = $object->getField($fieldPath);

                            if (!empty($content) && is_string($content)) {
                                $contentHash = MemoryEmbedding::hashContent($content);
                                $embedding = $embeddingService->embed($content);

                                if ($embedding !== null) {
                                    $newEmbeddings[] = [
                                        'field_path' => $fieldPath,
                                        'content_hash' => $contentHash,
                                        'embedding' => $embedding,
                                    ];
                                }
                            }
                        }

                        if (!empty($newEmbeddings)) {
                            DB::transaction(function () use ($object, $newEmbeddings) {
                                MemoryEmbedding::where('object_id', $object->id)->delete();

                                foreach ($newEmbeddings as $embeddingData) {
                                    MemoryEmbedding::create([
                                        'object_id' => $object->id,
                                        'field_path' => $embeddingData['field_path'],
                                        'content_hash' => $embeddingData['content_hash'],
                                        'embedding' => $embeddingData['embedding'],
                                    ]);
                                }
                            });
                            $count++;
                        }
                    }
                });
        } catch (\Exception $e) {
            Log::warning('Failed to regenerate embeddings', ['error' => $e->getMessage()]);
        }

        return $count;
    }

    public function getArtisanCommand(): ?string
    {
        return 'memory:structure:update';
    }
}
