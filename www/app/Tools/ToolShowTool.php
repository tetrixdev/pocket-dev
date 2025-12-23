<?php

namespace App\Tools;

use App\Models\PocketTool;

/**
 * Show details of a specific tool.
 */
class ToolShowTool extends Tool
{
    public string $name = 'ToolShow';

    public string $description = 'Show detailed information about a specific tool.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'The slug of the tool to show.',
            ],
            'include_script' => [
                'type' => 'boolean',
                'description' => 'Include the full script content in output.',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolShow to get detailed information about a tool.

## Example

Show basic info:
```json
{
  "slug": "my-tool"
}
```

Show info including script:
```json
{
  "slug": "my-tool",
  "include_script": true
}
```
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $slug = $input['slug'] ?? '';
        $includeScript = $input['include_script'] ?? false;

        if (empty($slug)) {
            return ToolResult::error('slug is required');
        }

        $tool = PocketTool::where('slug', $slug)->first();

        if (!$tool) {
            return ToolResult::error("Tool '{$slug}' not found");
        }

        $output = $this->formatToolDetails($tool, $includeScript);

        return ToolResult::success($output);
    }

    private function formatToolDetails(PocketTool $tool, bool $includeScript): string
    {
        $lines = [
            "Tool: {$tool->name}",
            "Slug: {$tool->slug}",
            "ID: {$tool->id}",
            "",
            "Source: " . $tool->source,
            "Status: " . ($tool->enabled ? 'Enabled' : 'Disabled'),
            "Category: {$tool->category}",
            "",
            "Description:",
            "  {$tool->description}",
            "",
            "System Prompt:",
            "  " . str_replace("\n", "\n  ", $tool->system_prompt),
        ];

        if ($tool->input_schema) {
            $lines[] = "";
            $lines[] = "Input Schema:";
            $schemaJson = json_encode($tool->input_schema, JSON_PRETTY_PRINT);
            $lines[] = "  " . str_replace("\n", "\n  ", $schemaJson);
        }

        if ($tool->isPocketdev()) {
            $command = $tool->getArtisanCommand();
            if ($command) {
                $lines[] = "";
                $lines[] = "Artisan Command: php artisan {$command}";
            }
        }

        if ($includeScript && $tool->hasScript()) {
            $lines[] = "";
            $lines[] = "Script:";
            $lines[] = "---";
            $lines[] = $tool->script;
            $lines[] = "---";
        }

        return implode("\n", $lines);
    }

    public function getArtisanCommand(): ?string
    {
        return 'tool:show';
    }
}
