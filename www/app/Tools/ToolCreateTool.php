<?php

namespace App\Tools;

use App\Models\PocketTool;
use App\Panels\PanelRegistry;

/**
 * Create a new user tool or panel.
 */
class ToolCreateTool extends Tool
{
    public string $name = 'ToolCreate';

    public string $description = 'Create a new custom tool (bash script) or panel (interactive UI).';

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
            'type' => [
                'type' => 'string',
                'enum' => ['script', 'panel'],
                'description' => 'Tool type: "script" for bash tools (default), "panel" for interactive UI panels.',
            ],
            'script' => [
                'type' => 'string',
                'description' => 'Bash script content. Required for type=script. Optional for type=panel (used for peek).',
            ],
            'blade_template' => [
                'type' => 'string',
                'description' => 'Blade template for panel rendering. Required for type=panel.',
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
        'required' => ['slug', 'name', 'description', 'system_prompt'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolCreate to create custom tools (bash scripts) or panels (interactive UI).

## Tool Types

- **script** (default): Bash script that runs and returns output
- **panel**: Interactive UI panel with a Blade template

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

## Creating Script Tools

Script tools execute bash and return output. Required field: `script`.

```json
{
  "slug": "health-check",
  "name": "Health Check",
  "type": "script",
  "script": "#!/bin/bash\ncurl -s http://localhost/health"
}
```

## Creating Panels

Panels are interactive UI components. Required field: `blade_template`.

**IMPORTANT - Blade Template Rules:**
1. Use **inline x-data objects** for Alpine.js - do NOT reference external functions
2. Do NOT use `<script>` tags - they won't work with dynamic loading
3. Use **Tailwind CSS** for styling
4. Available variables: `$parameters`, `$state`, `$panelState`, `$panel`

**Correct pattern:**
```blade
<div x-data="{
    count: 0,
    items: @js($parameters['items'] ?? []),
    increment() { this.count++ }
}">
    <button @click="increment()" class="px-4 py-2 bg-blue-500 text-white rounded">
        Count: <span x-text="count"></span>
    </button>
</div>
```

**WRONG - Do not do this:**
```blade
<div x-data="myComponent()">...</div>
<script>
function myComponent() { return { count: 0 } }
</script>
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

## Panel Actions (Advanced)

Panels can call server-side actions for lazy loading and interactive behavior.
Use the action endpoint to run server-side logic without full page refresh.

**Action endpoint:** `POST /api/panel/{panelStateId}/action`

**Request body:**
```json
{
  "action": "loadChildren",
  "params": { "path": "/some/path", "depth": 1 }
}
```

**Response:**
```json
{
  "ok": true,
  "html": "<div>...</div>",
  "state": { "loadedPaths": [...] },
  "data": null,
  "error": null
}
```

**Panel template pattern for actions:**
```blade
<div x-data="{
    panelStateId: @js($panelState->id ?? null),

    async loadData(id) {
        const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'loadDetails', params: { id } })
        });
        const result = await response.json();
        if (result.ok) {
            // Use result.html, result.data, or result.state
        }
    }
}">
```

For script-based panels, the action is handled by running the script with env vars:
- `PANEL_ACTION` - the action name
- `PANEL_PARAMS` - JSON-encoded params
- `PANEL_STATE` - JSON-encoded current state

## Notes
- Scripts have a 5-minute timeout
- Panels can include an optional `script` field for peek and action handling
- Use `tool:show <slug>` to inspect a tool's details
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example (Script Tool)

```bash
pd tool:create --slug=git-status --name="Git Status" \
  --description="Check git branch and status" \
  --system_prompt="Returns current git branch and working tree status." \
  --script='#!/bin/bash
echo "{\"branch\": \"$(git branch --show-current)\"}"'
```

## CLI Example (Panel)

```bash
pd tool:create --slug=counter --name="Counter Panel" \
  --type=panel \
  --description="Simple counter panel" \
  --system_prompt="Opens a counter panel." \
  --blade-template='<div x-data="{ count: 0 }" class="p-4">
    <button @click="count++" class="px-4 py-2 bg-blue-500 text-white rounded">
        Count: <span x-text="count"></span>
    </button>
</div>'
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (Script Tool)

```json
{
  "slug": "git-status",
  "name": "Git Status",
  "type": "script",
  "description": "Check git branch and status",
  "system_prompt": "Returns current git branch and working tree status.",
  "script": "#!/bin/bash\necho \"{\\\"branch\\\": \\\"$(git branch --show-current)\\\"}\""
}
```

## API Example (Panel)

```json
{
  "slug": "counter",
  "name": "Counter Panel",
  "type": "panel",
  "description": "Simple counter panel",
  "system_prompt": "Opens a counter panel with increment button.",
  "blade_template": "<div x-data=\"{ count: 0 }\" class=\"p-4\">\n  <button @click=\"count++\" class=\"px-4 py-2 bg-blue-500 text-white rounded\">\n    Count: <span x-text=\"count\"></span>\n  </button>\n</div>"
}
```
API;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $slug = $input['slug'] ?? '';
        $name = $input['name'] ?? '';
        $description = $input['description'] ?? '';
        $systemPrompt = $input['system_prompt'] ?? '';
        $type = $input['type'] ?? PocketTool::TYPE_SCRIPT;
        $script = $input['script'] ?? '';
        $bladeTemplate = $input['blade_template'] ?? '';
        $category = $input['category'] ?? 'custom';
        $inputSchema = $input['input_schema'] ?? null;
        $disabled = $input['disabled'] ?? false;

        // Validate common required fields
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

        // Validate type
        if (!in_array($type, [PocketTool::TYPE_SCRIPT, PocketTool::TYPE_PANEL])) {
            return ToolResult::error('type must be "script" or "panel"');
        }

        // Type-specific validation
        if ($type === PocketTool::TYPE_SCRIPT) {
            if (empty($script)) {
                return ToolResult::error('script is required for type=script');
            }
        } else {
            // Panel type
            if (empty($bladeTemplate)) {
                return ToolResult::error('blade_template is required for type=panel');
            }
        }

        // Check for duplicate slug against system panels
        $panelRegistry = app(PanelRegistry::class);
        if ($panelRegistry->has($slug)) {
            return ToolResult::error("A system panel with slug '{$slug}' already exists. Choose a different slug.");
        }

        // Check for duplicate slug against database tools
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
                'type' => $type,
                'script' => $script ?: null,
                'blade_template' => $bladeTemplate ?: null,
                'source' => PocketTool::SOURCE_USER,
                'category' => $category,
                'enabled' => !$disabled,
                'input_schema' => $inputSchema,
            ]);

            $typeLabel = $type === PocketTool::TYPE_PANEL ? 'panel' : 'tool';
            $output = [
                "Created {$typeLabel}: {$name} ({$slug})",
                "",
                "ID: {$tool->id}",
                "Type: {$type}",
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
