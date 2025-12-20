<?php

namespace App\Tools;

use App\Models\MemoryObject;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;

/**
 * Update an existing memory object.
 */
class MemoryUpdateTool extends Tool
{
    public string $name = 'MemoryUpdate';

    public string $description = 'Update an existing memory object by ID.';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'id' => [
                'type' => 'string',
                'description' => 'The UUID of the object to update.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'New name for the object. Optional.',
            ],
            'data' => [
                'type' => 'object',
                'description' => 'Fields to update in the data. Will be merged with existing data.',
            ],
            'replace_data' => [
                'type' => 'boolean',
                'description' => 'If true, replace all data instead of merging. Default: false.',
            ],
            'parent_id' => [
                'type' => 'string',
                'description' => 'New parent object ID. Use null to remove parent.',
            ],
        ],
        'required' => ['id'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryUpdate to modify existing memory objects.

## Examples

Update specific fields (merge):
```json
{
  "id": "object-uuid",
  "data": {
    "level": 6,
    "new_ability": "Rage"
  }
}
```

Replace all data:
```json
{
  "id": "object-uuid",
  "replace_data": true,
  "data": {
    "completely": "new data"
  }
}
```

Update name and parent:
```json
{
  "id": "object-uuid",
  "name": "New Name",
  "parent_id": "new-parent-uuid"
}
```

## Notes
- By default, data is merged (existing fields preserved, new fields added/updated)
- Use replace_data: true to completely replace data
- Embeddings are automatically regenerated for changed embeddable fields
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $id = $input['id'] ?? '';
        $name = $input['name'] ?? null;
        $data = $input['data'] ?? null;
        $replaceData = $input['replace_data'] ?? false;
        $parentId = array_key_exists('parent_id', $input) ? $input['parent_id'] : 'NOT_SET';

        if (empty($id)) {
            return ToolResult::error('id is required');
        }

        $object = MemoryObject::find($id);
        if (!$object) {
            return ToolResult::error("Object '{$id}' not found.");
        }

        // Validate parent if provided
        if ($parentId !== 'NOT_SET' && $parentId !== null) {
            $parent = MemoryObject::find($parentId);
            if (!$parent) {
                return ToolResult::error("Parent object '{$parentId}' not found.");
            }
            // Prevent circular reference
            if ($parentId === $id) {
                return ToolResult::error("Object cannot be its own parent.");
            }
        }

        try {
            $changes = [];

            DB::transaction(function () use ($object, $name, $data, $replaceData, $parentId, &$changes) {
                if ($name !== null && $name !== $object->name) {
                    $changes[] = "name: {$object->name} -> {$name}";
                    $object->name = $name;
                }

                if ($data !== null) {
                    if ($replaceData) {
                        $changes[] = "data: replaced entirely";
                        $object->data = $data;
                    } else {
                        $merged = array_merge($object->data ?? [], $data);
                        $changedKeys = array_keys($data);
                        $changes[] = "data: updated fields [" . implode(', ', $changedKeys) . "]";
                        $object->data = $merged;
                    }
                }

                if ($parentId !== 'NOT_SET') {
                    $oldParent = $object->parent_id ?? 'none';
                    $newParent = $parentId ?? 'none';
                    if ($oldParent !== $newParent) {
                        $changes[] = "parent: {$oldParent} -> {$newParent}";
                        $object->parent_id = $parentId;
                    }
                }

                if (!empty($changes)) {
                    $object->save();
                    $object->refreshSearchableText();
                }
            });

            if (empty($changes)) {
                return ToolResult::success("No changes made to object '{$object->name}' ({$id}).");
            }

            // Regenerate embeddings
            $this->regenerateEmbeddings($object);

            $output = [
                "Updated {$object->structure_slug}: {$object->name}",
                "",
                "ID: {$id}",
                "",
                "Changes:",
            ];

            foreach ($changes as $change) {
                $output[] = "  - {$change}";
            }

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to update object: ' . $e->getMessage());
        }
    }

    private function regenerateEmbeddings(MemoryObject $object): void
    {
        try {
            $service = app(EmbeddingService::class);
            if ($service->isAvailable()) {
                $service->embedObject($object);
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to regenerate embeddings for object', [
                'object_id' => $object->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
