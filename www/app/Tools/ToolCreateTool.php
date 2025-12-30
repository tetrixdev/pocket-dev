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

## Why This Tool Matters

Custom tools extend AI capabilities. When you create a tool, the `system_prompt` you provide gets injected into the AI's context whenever that tool is enabled. This means the system_prompt IS the documentation - write it for AI consumption, not human developers.

## How Parameters Work

When the tool is invoked, parameters become environment variables in the script:
- `--location=Paris` (CLI) or `{"location": "Paris"}` (API) → `$TOOL_LOCATION` in script

Parameter names are uppercased and prefixed with `TOOL_`.

## The system_prompt Field

This is the most important field. It gets injected into the AI's context. Write it as if explaining to an AI:

**Required elements:**
1. What the tool does (1-2 sentences)
2. Example invocation
3. Parameter descriptions

**Example of a good system_prompt:**
```
Checks health status of deployed services. Returns JSON with status, uptime, and errors.

## CLI Example
\`\`\`bash
php artisan tool:run health-check -- --service=api --environment=production
\`\`\`

## Parameters
- service: Service name (api, web, worker, database)
- environment: Target environment (development, staging, production)
```

## input_schema Best Practices

Always include descriptions - these appear in the tool's parameter documentation:
```json
{
  "type": "object",
  "properties": {
    "query": {"type": "string", "description": "The search query to execute"},
    "limit": {"type": "integer", "description": "Maximum results (default: 10)"}
  },
  "required": ["query"]
}
```

## Script Best Practices

1. **Output JSON** for structured responses
2. **Handle errors gracefully** - check for required params
3. **Use jq** for JSON manipulation

## Notes
- Scripts have a 5-minute timeout
- Use `tool:show <slug>` to inspect a tool's details
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
php artisan tool:create --slug=git-status --name="Git Status" \
  --description="Check git branch and status" \
  --system_prompt="Returns current git branch and working tree status.

## CLI Example
\`\`\`bash
php artisan tool:run git-status
\`\`\`" \
  --script='#!/bin/bash
echo "{\"branch\": \"$(git branch --show-current)\"}"'
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "slug": "git-status",
  "name": "Git Status",
  "description": "Check git branch and status",
  "system_prompt": "Returns current git branch and working tree status.",
  "script": "#!/bin/bash\necho \"{\\\"branch\\\": \\\"$(git branch --show-current)\\\"}\""
}
```
API;

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
