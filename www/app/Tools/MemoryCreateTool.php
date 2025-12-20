<?php

namespace App\Tools;

use App\Models\MemoryObject;
use App\Models\MemoryStructure;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;

/**
 * Create a new memory object.
 */
class MemoryCreateTool extends Tool
{
    public string $name = 'MemoryCreate';

    public string $description = 'Create a new memory object of a specified structure type.';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'structure' => [
                'type' => 'string',
                'description' => 'The structure slug (e.g., "character", "location", "item"). Use MemoryQuery to list available structures.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Name of the object. Required.',
            ],
            'data' => [
                'type' => 'object',
                'description' => 'Object data fields as key-value pairs. Should match the structure\'s schema.',
            ],
            'parent_id' => [
                'type' => 'string',
                'description' => 'Optional parent object ID for hierarchical relationships.',
            ],
        ],
        'required' => ['structure', 'name'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryCreate to create new memory objects.

## Example

Create a character:
```json
{
  "structure": "character",
  "name": "Thorin Ironforge",
  "data": {
    "class": "fighter",
    "level": 5,
    "backstory": "A dwarf warrior seeking revenge..."
  }
}
```

Create a location with parent:
```json
{
  "structure": "location",
  "name": "The Sunken Library",
  "data": {
    "description": "An ancient library half-submerged in the swamp...",
    "terrain": "swamp"
  },
  "parent_id": "parent-region-uuid"
}
```

## Notes
- First query available structures with MemoryQuery if unsure of slug
- Embeddings are automatically generated for fields marked with x-embed in schema
- Returns the created object's ID
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $structureSlug = $input['structure'] ?? '';
        $name = $input['name'] ?? '';
        $data = $input['data'] ?? [];
        $parentId = $input['parent_id'] ?? null;

        if (empty($structureSlug)) {
            return ToolResult::error('structure is required');
        }

        if (empty($name)) {
            return ToolResult::error('name is required');
        }

        // Find structure
        $structure = MemoryStructure::where('slug', $structureSlug)->first();
        if (!$structure) {
            $available = MemoryStructure::pluck('slug')->implode(', ');
            return ToolResult::error("Structure '{$structureSlug}' not found. Available: {$available}");
        }

        // Validate parent if provided
        if ($parentId) {
            $parent = MemoryObject::find($parentId);
            if (!$parent) {
                return ToolResult::error("Parent object '{$parentId}' not found.");
            }
        }

        try {
            $object = DB::transaction(function () use ($structure, $name, $data, $parentId) {
                // Create the object
                $object = MemoryObject::create([
                    'structure_id' => $structure->id,
                    'structure_slug' => $structure->slug,
                    'name' => $name,
                    'data' => $data,
                    'parent_id' => $parentId,
                ]);

                // Build and save searchable text
                $object->refreshSearchableText();

                return $object;
            });

            // Generate embeddings asynchronously (or sync if queue not available)
            $this->generateEmbeddings($object);

            $output = [
                "Created {$structure->name}: {$name}",
                "",
                "ID: {$object->id}",
                "Structure: {$structure->slug}",
            ];

            if ($parentId) {
                $output[] = "Parent: {$parentId}";
            }

            if (!empty($data)) {
                $output[] = "";
                $output[] = "Data:";
                foreach ($data as $key => $value) {
                    $displayValue = is_array($value) ? json_encode($value) : $value;
                    if (strlen($displayValue) > 100) {
                        $displayValue = substr($displayValue, 0, 97) . '...';
                    }
                    $output[] = "  {$key}: {$displayValue}";
                }
            }

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to create object: ' . $e->getMessage());
        }
    }

    private function generateEmbeddings(MemoryObject $object): void
    {
        try {
            $service = app(EmbeddingService::class);
            if ($service->isAvailable()) {
                $service->embedObject($object);
            }
        } catch (\Exception $e) {
            // Log but don't fail - embeddings can be regenerated later
            \Log::warning('Failed to generate embeddings for object', [
                'object_id' => $object->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
