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
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolShow when preparing to update a tool. Most tool info (description, parameters) is already in your system prompt, so this shows the additional content: script and blade_template.
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
pd tool:show my-tool
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

        $output = $this->formatToolDetails($tool);

        return ToolResult::success($output);
    }

    private function formatToolDetails(PocketTool $tool): string
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
                $lines[] = "Artisan Command: pd {$command}";
            }
        }

        if ($tool->hasScript()) {
            $lines[] = "";
            $lines[] = "Script:";
            $lines[] = "---";
            $lines[] = $tool->script;
            $lines[] = "---";
        }

        if ($tool->hasBladeTemplate()) {
            $lines[] = "";
            $lines[] = "Blade Template:";
            $lines[] = "---";
            $lines[] = $tool->blade_template;
            $lines[] = "---";
        }

        return implode("\n", $lines);
    }

    public function getArtisanCommand(): ?string
    {
        return 'tool:show';
    }
}
