<?php

namespace App\Tools;

use App\Models\PocketTool;

/**
 * Delete a user tool.
 */
class ToolDeleteTool extends Tool
{
    public string $name = 'ToolDelete';

    public string $description = 'Delete a user-created tool.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'The slug of the tool to delete.',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolDelete to permanently remove a user-created tool.

## Important
- Only user-created tools can be deleted
- PocketDev built-in tools cannot be deleted
- This action cannot be undone
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
php artisan tool:delete --slug=my-tool
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "slug": "my-tool"
}
```
API;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $slug = $input['slug'] ?? '';

        if (empty($slug)) {
            return ToolResult::error('slug is required');
        }

        $tool = PocketTool::where('slug', $slug)->first();

        if (!$tool) {
            return ToolResult::error("Tool '{$slug}' not found");
        }

        if ($tool->isPocketdev()) {
            return ToolResult::error("Cannot delete PocketDev tool '{$slug}'. Only user-created tools can be deleted.");
        }

        $name = $tool->name;

        try {
            $tool->delete();

            return ToolResult::success("Deleted tool: {$name} ({$slug})");
        } catch (\Exception $e) {
            return ToolResult::error('Failed to delete tool: ' . $e->getMessage());
        }
    }

    public function getArtisanCommand(): ?string
    {
        return 'tool:delete';
    }
}
