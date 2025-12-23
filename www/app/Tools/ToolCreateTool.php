<?php

namespace App\Tools;

use App\Models\PocketTool;

/**
 * Create a new user tool.
 */
class ToolCreateTool extends Tool
{
    public string $name = 'ToolCreate';

    public string $description = 'Create a new custom tool with a bash script.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'Unique identifier for the tool (e.g., "my-tool").',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Display name for the tool.',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Short description of what the tool does.',
            ],
            'system_prompt' => [
                'type' => 'string',
                'description' => 'Detailed instructions for AI (added to system prompt when enabled).',
            ],
            'script' => [
                'type' => 'string',
                'description' => 'Bash script content to execute.',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Tool category (default: "custom").',
            ],
            'input_schema' => [
                'type' => 'object',
                'description' => 'JSON Schema for named parameters (optional).',
            ],
            'disabled' => [
                'type' => 'boolean',
                'description' => 'Create the tool in disabled state.',
            ],
        ],
        'required' => ['slug', 'name', 'description', 'system_prompt', 'script'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolCreate to create custom tools with bash scripts.

## Example

```bash
php artisan tool:create -- --slug=git-status --name="Git Status" \
  --description="Check git branch and status" \
  --system_prompt="Returns current git branch and working tree status." \
  --script='#!/bin/bash
echo "{\"branch\": \"$(git branch --show-current)\"}"'
```

## Best Practices

**1. Always add descriptions in input_schema** - These are shown to AI in the system prompt:
```json
"input_schema": {
  "type": "object",
  "properties": {
    "query": {"type": "string", "description": "The search query to execute"}
  },
  "required": ["query"]
}
```

**2. Script receives TOOL_* environment variables:**
- `--name=John` → `$TOOL_NAME` in script
- `--my-param=value` → `$TOOL_MY_PARAM` in script

**3. Write a clear system_prompt** - This is what AI sees. Include:
- What the tool does
- Example invocation: `php artisan tool:run my-tool -- --param=value`

## Notes
- Scripts should output JSON for structured responses
- Scripts have a 5-minute timeout
- Use `tool:show <slug>` to inspect a tool's details
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $slug = $input['slug'] ?? '';
        $name = $input['name'] ?? '';
        $description = $input['description'] ?? '';
        $systemPrompt = $input['system_prompt'] ?? '';
        $script = $input['script'] ?? '';
        $category = $input['category'] ?? 'custom';
        $inputSchema = $input['input_schema'] ?? null;
        $disabled = $input['disabled'] ?? false;

        if (empty($slug)) {
            return ToolResult::error('slug is required');
        }

        if (empty($name)) {
            return ToolResult::error('name is required');
        }

        if (empty($description)) {
            return ToolResult::error('description is required');
        }

        if (empty($systemPrompt)) {
            return ToolResult::error('system_prompt is required');
        }

        if (empty($script)) {
            return ToolResult::error('script is required');
        }

        // Check for duplicate slug
        if (PocketTool::where('slug', $slug)->exists()) {
            return ToolResult::error("A tool with slug '{$slug}' already exists");
        }

        // Validate input_schema if provided
        if ($inputSchema !== null && !is_array($inputSchema)) {
            return ToolResult::error('input_schema must be an object');
        }

        try {
            $tool = PocketTool::create([
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'system_prompt' => $systemPrompt,
                'script' => $script,
                'source' => PocketTool::SOURCE_USER,
                'category' => $category,
                'enabled' => !$disabled,
                'input_schema' => $inputSchema,
            ]);

            $output = [
                "Created tool: {$name} ({$slug})",
                "",
                "ID: {$tool->id}",
                "Enabled: " . ($tool->enabled ? 'yes' : 'no'),
            ];

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to create tool: ' . $e->getMessage());
        }
    }

    public function getArtisanCommand(): ?string
    {
        return 'tool:create';
    }
}
