<?php

namespace App\Tools;

use App\Models\PocketTool;

/**
 * Update an existing user tool.
 */
class ToolUpdateTool extends Tool
{
    public string $name = 'ToolUpdate';

    public string $description = 'Update an existing user tool (name, description, script, blade_template, etc.).';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'The slug of the tool to update.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'New display name.',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'New description.',
            ],
            'system_prompt' => [
                'type' => 'string',
                'description' => 'New system prompt instructions.',
            ],
            'script' => [
                'type' => 'string',
                'description' => 'New bash script content.',
            ],
            'blade_template' => [
                'type' => 'string',
                'description' => 'New Blade template content for panel tools.',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'New category (memory, tools, file_ops, custom).',
            ],
            'input_schema' => [
                'type' => 'object',
                'description' => 'New JSON Schema for input parameters.',
            ],
            'panel_dependencies' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'description' => 'script or stylesheet'],
                        'url' => ['type' => 'string', 'description' => 'CDN URL'],
                        'defer' => ['type' => 'boolean', 'description' => 'Defer loading (scripts only)'],
                        'crossorigin' => ['type' => 'string', 'description' => 'Crossorigin attribute (e.g., "anonymous")'],
                    ],
                    'required' => ['type', 'url'],
                ],
                'description' => 'Additional CDN dependencies to load for this panel. Base deps (Tailwind, Alpine, Font Awesome) are always loaded. Each entry needs at minimum type ("script" or "stylesheet") and url.',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolUpdate to modify an existing user-created tool.

## Important
- Only user-created tools can be modified
- PocketDev built-in tools cannot be changed

## Updatable Fields
- name, description, system_prompt: Basic tool info
- script: Bash script content for script-based tools
- blade_template: Blade template content for panel tools
- panel_dependencies: Additional CDN dependencies for panels (array of {type, url} objects)
- category, input_schema: Tool configuration

## Valid Categories
- memory: Memory-related tools
- tools: Tool management
- file_ops: File operations
- custom: Custom tools (default)

**Refer to the Panel Dependencies section for available CSS/JS libraries when updating panel templates.**
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
pd tool:update my-tool --name="Updated Name" --description="New description"
pd tool:update my-panel-tool --blade-template-file=/path/to/template.blade.php
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

Update a tool's script:
```json
{
  "slug": "my-tool",
  "script": "#!/bin/bash\necho \"Updated script\""
}
```

Update a panel tool's blade template:
```json
{
  "slug": "my-panel-tool",
  "blade_template": "<div>Updated template content</div>"
}
```

Update name and description:
```json
{
  "slug": "my-tool",
  "name": "My Updated Tool",
  "description": "An updated description"
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
            return ToolResult::error("Cannot modify PocketDev tool '{$slug}'. Only user-created tools can be modified.");
        }

        $changes = [];

        if (isset($input['name']) && $input['name'] !== '') {
            $tool->name = $input['name'];
            $changes[] = 'name';
        }

        if (isset($input['description'])) {
            $tool->description = $input['description'];
            $changes[] = 'description';
        }

        if (isset($input['system_prompt'])) {
            $tool->system_prompt = $input['system_prompt'];
            $changes[] = 'system_prompt';
        }

        if (isset($input['script'])) {
            $tool->script = $input['script'];
            $changes[] = 'script';
        }

        if (isset($input['blade_template'])) {
            $tool->blade_template = $input['blade_template'];
            $changes[] = 'blade_template';
        }

        if (isset($input['category'])) {
            $category = $input['category'];
            $allowedCategories = [
                PocketTool::CATEGORY_MEMORY,
                PocketTool::CATEGORY_TOOLS,
                PocketTool::CATEGORY_FILE_OPS,
                PocketTool::CATEGORY_CUSTOM,
            ];
            if (!in_array($category, $allowedCategories, true)) {
                return ToolResult::error('Invalid category. Allowed values: ' . implode(', ', $allowedCategories));
            }
            $tool->category = $category;
            $changes[] = 'category';
        }

        if (isset($input['input_schema'])) {
            if (!is_array($input['input_schema'])) {
                return ToolResult::error('input_schema must be an object');
            }
            $tool->input_schema = $input['input_schema'];
            $changes[] = 'input_schema';
        }

        if (isset($input['panel_dependencies'])) {
            if (!is_array($input['panel_dependencies'])) {
                return ToolResult::error('panel_dependencies must be an array of dependency objects');
            }
            $tool->panel_dependencies = $input['panel_dependencies'];
            $changes[] = 'panel_dependencies';
        }

        if (empty($changes)) {
            return ToolResult::error('No changes specified. Use name, description, system_prompt, script, blade_template, panel_dependencies, category, or input_schema.');
        }

        try {
            $tool->save();

            $output = [
                "Updated tool: {$tool->name} ({$slug})",
                "",
                "Changed: " . implode(', ', $changes),
            ];

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to update tool: ' . $e->getMessage());
        }
    }

    public function getArtisanCommand(): ?string
    {
        return 'tool:update';
    }
}
