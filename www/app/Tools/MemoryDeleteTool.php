<?php

namespace App\Tools;

use App\Models\MemoryObject;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;

/**
 * Delete a memory object.
 */
class MemoryDeleteTool extends Tool
{
    public string $name = 'MemoryDelete';

    public string $description = 'Delete a memory object by ID. Also removes its embeddings and relationships.';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'id' => [
                'type' => 'string',
                'description' => 'The UUID of the object to delete.',
            ],
            'cascade' => [
                'type' => 'boolean',
                'description' => 'If true, also delete child objects. Default: false.',
            ],
        ],
        'required' => ['id'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryDelete to remove memory objects.

## Examples

Delete single object:
```json
{
  "id": "object-uuid"
}
```

Delete with children:
```json
{
  "id": "parent-uuid",
  "cascade": true
}
```

## Notes
- Embeddings are automatically deleted (cascade)
- Relationships involving this object are automatically deleted (cascade)
- Child objects are NOT deleted unless cascade: true
- Child objects will have their parent_id set to null if parent is deleted
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $id = $input['id'] ?? '';
        $cascade = $input['cascade'] ?? false;

        if (empty($id)) {
            return ToolResult::error('id is required');
        }

        $object = MemoryObject::find($id);
        if (!$object) {
            return ToolResult::error("Object '{$id}' not found.");
        }

        $name = $object->name;
        $structureSlug = $object->structure_slug;

        try {
            $deletedCount = 1;
            $childCount = 0;

            DB::transaction(function () use ($object, $cascade, &$childCount) {
                if ($cascade) {
                    // Delete all descendants recursively
                    $childCount = $this->deleteDescendants($object);
                }

                // Delete the object (embeddings and relationships cascade via FK)
                $object->delete();
            });

            $output = ["Deleted {$structureSlug}: {$name}", "", "ID: {$id}"];

            if ($cascade && $childCount > 0) {
                $output[] = "Also deleted {$childCount} child object(s)";
            }

            // Check for orphaned children
            if (!$cascade) {
                $orphanedChildren = MemoryObject::where('parent_id', $id)->count();
                if ($orphanedChildren > 0) {
                    $output[] = "";
                    $output[] = "Note: {$orphanedChildren} child object(s) are now orphaned (parent_id set to null)";
                }
            }

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to delete object: ' . $e->getMessage());
        }
    }

    /**
     * Recursively delete all descendants of an object.
     *
     * @return int Number of descendants deleted
     */
    private function deleteDescendants(MemoryObject $object): int
    {
        $count = 0;
        $children = $object->children()->get();

        foreach ($children as $child) {
            // Recursively delete grandchildren first
            $count += $this->deleteDescendants($child);
            $child->delete();
            $count++;
        }

        return $count;
    }
}
