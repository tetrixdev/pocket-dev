<?php

namespace App\Tools;

use App\Models\MemoryStructure;

/**
 * Get a memory structure by slug.
 */
class MemoryStructureGetTool extends Tool
{
    public string $name = 'MemoryStructureGet';

    public string $description = 'Get a memory structure schema by slug.';

    public string $category = 'memory';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'The structure slug to retrieve.',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryStructureGet to retrieve a structure's schema definition.

## CLI Example

```bash
php artisan memory:structure:get --slug=character
```

Returns the structure's name, description, schema (with field definitions), icon, and color.
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $slug = $input['slug'] ?? '';

        if (empty($slug)) {
            return ToolResult::error('slug is required');
        }

        $structure = MemoryStructure::where('slug', $slug)->first();

        if (!$structure) {
            $available = MemoryStructure::pluck('slug')->implode(', ');
            $msg = "Structure '{$slug}' not found.";
            if ($available) {
                $msg .= " Available: {$available}";
            }
            return ToolResult::error($msg);
        }

        $output = [
            "Structure: {$structure->name} ({$structure->slug})",
            "",
            "ID: {$structure->id}",
        ];

        if ($structure->description) {
            $output[] = "Description: {$structure->description}";
        }

        if ($structure->icon) {
            $output[] = "Icon: {$structure->icon}";
        }

        if ($structure->color) {
            $output[] = "Color: {$structure->color}";
        }

        $output[] = "";
        $output[] = "Schema:";
        $output[] = json_encode($structure->schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return ToolResult::success(implode("\n", $output));
    }

    public function getArtisanCommand(): ?string
    {
        return 'memory:structure:get';
    }
}
