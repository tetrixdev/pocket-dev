<?php

namespace App\Tools;

use App\Models\PocketTool;

/**
 * List all tools.
 */
class ToolListTool extends Tool
{
    public string $name = 'ToolList';

    public string $description = 'List all available tools with optional filtering.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'enabled' => [
                'type' => 'boolean',
                'description' => 'Filter to only enabled tools.',
            ],
            'disabled' => [
                'type' => 'boolean',
                'description' => 'Filter to only disabled tools.',
            ],
            'pocketdev' => [
                'type' => 'boolean',
                'description' => 'Filter to only PocketDev built-in tools.',
            ],
            'user' => [
                'type' => 'boolean',
                'description' => 'Filter to only user-created tools.',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Filter by category (memory, tools, file_ops, custom).',
            ],
        ],
        'required' => [],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolList to see all available tools.

## Filters
- enabled/disabled: Filter by status
- pocketdev/user: Filter by source
- category: Filter by category
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
pd tool:list
pd tool:list --enabled --user
pd tool:list --category=memory
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

List all tools:
```json
{}
```

List only enabled user tools:
```json
{
  "enabled": true,
  "user": true
}
```

List memory tools:
```json
{
  "category": "memory"
}
```
API;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $query = PocketTool::query();

        if (!empty($input['enabled'])) {
            $query->where('enabled', true);
        }

        if (!empty($input['disabled'])) {
            $query->where('enabled', false);
        }

        if (!empty($input['pocketdev'])) {
            $query->pocketdev();
        }

        if (!empty($input['user'])) {
            $query->user();
        }

        if (!empty($input['category'])) {
            $query->category($input['category']);
        }

        $tools = $query->orderBy('category')->orderBy('name')->get();

        if ($tools->isEmpty()) {
            return ToolResult::success('No tools found');
        }

        $output = ["Found {$tools->count()} tool(s):", ""];

        $currentCategory = null;
        foreach ($tools as $tool) {
            if ($tool->category !== $currentCategory) {
                if ($currentCategory !== null) {
                    $output[] = "";
                }
                $output[] = "[{$tool->category}]";
                $currentCategory = $tool->category;
            }

            $status = $tool->enabled ? '+' : '-';
            $source = $tool->source;
            $output[] = "  {$status} {$tool->slug} [{$source}]";
            $output[] = "    {$tool->description}";
        }

        $output[] = "";
        $output[] = "Legend: + enabled, - disabled";
        $output[] = "Sources: pocketdev = core PocketDev tools, user = custom tools";

        return ToolResult::success(implode("\n", $output));
    }

    public function getArtisanCommand(): ?string
    {
        return 'tool:list';
    }
}
