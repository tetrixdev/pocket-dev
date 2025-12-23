<?php

namespace App\Tools;

use App\Models\MemoryObject;
use App\Models\MemoryStructure;

/**
 * Delete a memory structure.
 */
class MemoryStructureDeleteTool extends Tool
{
    public string $name = 'MemoryStructureDelete';

    public string $description = 'Delete a memory structure (only if no objects exist).';

    public string $category = 'memory';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'The structure slug to delete.',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryStructureDelete to remove a memory structure.

## CLI Example

```bash
php artisan memory:structure:delete --slug=character
```

## Notes
- Will fail if any objects exist for this structure
- Delete all objects of this structure type first using MemoryDelete
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

        // Check if any objects exist for this structure
        $objectCount = MemoryObject::where('structure_id', $structure->id)->count();

        if ($objectCount > 0) {
            return ToolResult::error(
                "Cannot delete structure '{$slug}': {$objectCount} object(s) still exist. Delete all objects first."
            );
        }

        $name = $structure->name;
        $structure->delete();

        return ToolResult::success("Deleted structure: {$name} ({$slug})");
    }

    public function getArtisanCommand(): ?string
    {
        return 'memory:structure:delete';
    }
}
