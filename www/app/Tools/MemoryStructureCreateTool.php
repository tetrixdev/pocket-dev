<?php

namespace App\Tools;

use App\Models\MemoryStructure;
use Illuminate\Support\Str;

/**
 * Create a new memory structure (schema/template).
 */
class MemoryStructureCreateTool extends Tool
{
    public string $name = 'MemoryStructureCreate';

    public string $description = 'Create a new memory structure (schema/template for memory objects).';

    public string $category = 'memory';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Structure name (e.g., "Character", "Location").',
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'URL-friendly slug (auto-generated from name if omitted).',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'What this structure represents.',
            ],
            'schema' => [
                'type' => 'object',
                'description' => 'JSON Schema defining the structure fields. Must include "type" and "properties".',
            ],
            'icon' => [
                'type' => 'string',
                'description' => 'Icon name (optional).',
            ],
            'color' => [
                'type' => 'string',
                'description' => 'Hex color code (optional, e.g., "#2196F3").',
            ],
        ],
        'required' => ['name', 'schema'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryStructureCreate to define new memory object templates.

## Example

```bash
php artisan memory:structure:create --name="Project" \
  --description="A project being tracked" \
  --schema='{"type":"object","properties":{"status":{"type":"string","description":"Current status"},"notes":{"type":"string","x-embed":true,"description":"Project notes"}}}'
```

## Supported Field Types

**Basic types:**
- `string` - text (add `x-embed: true` for semantic search)
- `integer` - whole numbers
- `number` - decimals
- `boolean` - true/false
- `array` - lists (define `items` type)
- `object` - nested structures

**String formats (optional):**
- `"format": "date"` - YYYY-MM-DD
- `"format": "date-time"` - ISO 8601 timestamp
- `"format": "email"`, `"format": "uri"`, `"format": "uuid"`

## Example with Various Types

```json
{
  "type": "object",
  "properties": {
    "name": {"type": "string", "description": "Display name"},
    "count": {"type": "integer", "description": "Item count"},
    "price": {"type": "number", "description": "Price in dollars"},
    "active": {"type": "boolean", "description": "Is active"},
    "tags": {"type": "array", "items": {"type": "string"}, "description": "Tags"},
    "created": {"type": "string", "format": "date-time", "description": "Creation date"},
    "notes": {"type": "string", "x-embed": true, "description": "Searchable notes"}
  },
  "required": ["name"]
}
```

## Best Practices

1. **Always add descriptions to fields** - AI uses these to understand the schema
2. **Use `x-embed: true`** on text fields you want to search semantically
3. **Provide a structure description** - Explains when to use this structure
4. **Coordinates** can be stored as nested objects: `{"lat": {"type": "number"}, "lng": {"type": "number"}}`

**Note:** Basic coordinate storage works, but efficient geospatial queries (e.g., "find all within 30km") are not currently supported.
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $name = $input['name'] ?? '';
        $slug = $input['slug'] ?? '';
        $description = $input['description'] ?? '';
        $schema = $input['schema'] ?? null;
        $icon = $input['icon'] ?? null;
        $color = $input['color'] ?? null;

        if (empty($name)) {
            return ToolResult::error('name is required');
        }

        if (empty($schema)) {
            return ToolResult::error('schema is required');
        }

        if (!is_array($schema)) {
            return ToolResult::error('schema must be an object');
        }

        // Validate schema structure
        if (!isset($schema['type']) || !isset($schema['properties'])) {
            return ToolResult::error('Invalid JSON Schema: must include "type" and "properties"');
        }

        // Generate slug if not provided
        if (empty($slug)) {
            $slug = Str::slug($name);
        }

        // Check if slug already exists
        if (MemoryStructure::where('slug', $slug)->exists()) {
            return ToolResult::error("Structure with slug '{$slug}' already exists");
        }

        try {
            $structure = MemoryStructure::create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'schema' => $schema,
                'icon' => $icon,
                'color' => $color,
            ]);

            $output = [
                "Created structure: {$name} ({$slug})",
                "",
                "ID: {$structure->id}",
            ];

            if ($description) {
                $output[] = "Description: {$description}";
            }

            $fieldCount = count($schema['properties'] ?? []);
            $output[] = "Fields: {$fieldCount}";

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to create structure: ' . $e->getMessage());
        }
    }

    public function getArtisanCommand(): ?string
    {
        return 'memory:structure:create';
    }
}
