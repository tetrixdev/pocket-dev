<?php

namespace App\Tools;

use App\Models\PocketTool;
use App\Panels\PanelRegistry;

/**
 * Push a tool from a local directory back to PocketDev (create or update).
 */
class ToolPushTool extends Tool
{
    public string $name = 'ToolPush';

    public string $description = 'Push a tool from a local directory to PocketDev (create or update).';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'The slug of the tool to push. Used to find the directory and target tool.',
            ],
            'directory' => [
                'type' => 'string',
                'description' => 'Directory containing the tool files. Default: /tmp/pocketdev/tools/{slug}/',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolPush to create or update a tool from a local directory. This is the primary way to create and edit tools.

## Workflow

**Creating a new tool:**
1. Create files in `/tmp/pocketdev/tools/{slug}/`
2. Run `pd tool:push {slug}`

**Editing an existing tool:**
1. `pd tool:extract {slug}` (downloads to `/tmp/pocketdev/tools/{slug}/`)
2. Edit files with the Read and Edit tools
3. `pd tool:push {slug}` (uploads changes back)

## Directory Structure

```
/tmp/pocketdev/tools/{slug}/
├── meta.json              # REQUIRED: slug, name, description, type, category
├── system_prompt.md       # REQUIRED: AI instructions
├── input_schema.json      # Optional: parameter schema
├── template.blade.php     # Required for panels: Blade template
├── script.sh              # Required for scripts: bash script
└── dependencies.json      # Optional: panel CDN dependencies
```

## Behavior
- If a tool with the slug already exists: **updates** only the fields where files are present
- If no tool exists with the slug: **creates** a new tool (requires meta.json + system_prompt.md)
- Only user-created tools can be updated (PocketDev built-in tools are protected)

## Tool Types

- **script** (default): Bash script that runs and returns output
- **panel**: Interactive UI panel with a Blade template

## How Parameters Work

When the tool is invoked, parameters become environment variables in the script:
- `--location=Paris` (CLI) or `{"location": "Paris"}` (API) → `$TOOL_LOCATION` in script

Parameter names are uppercased and prefixed with `TOOL_`.

## The system_prompt.md File

This is the most important file. It gets injected into the AI's context. Write it as if explaining to an AI:

**Required elements:**
1. What the tool does (1-2 sentences)
2. Example invocation
3. Parameter descriptions

## Creating Panels

Panels are interactive UI components. Required file: `template.blade.php`.

**IMPORTANT - Blade Template Rules:**
1. Use **inline x-data objects** for Alpine.js - do NOT reference external functions
2. Do NOT use `<script>` tags - they won't work with dynamic loading
3. Use **Tailwind CSS** for styling
4. Available variables: `$parameters`, `$state`, `$panelState`, `$panel`
5. **Never use `:attr="$phpVar"` on regular HTML elements** — the `:` prefix is both Alpine.js shorthand (`x-bind:`) and Blade component syntax, but Blade ignores it on regular HTML. So `:disabled="$phpTrue"` passes the literal string `$phpTrue` to Alpine, which evaluates it as undefined JS. **Preferred:** inject PHP values into `x-data` via `@js()` (e.g., `items: @js($parameters['items'] ?? [])`). Alternative: use `x-bind:attr="{{ $var }}"` for one-off bindings.
6. **Use `!!()` for boolean attribute expressions** — `x-bind:disabled` requires an explicit `true`/`false`. If the expression can return `undefined` (e.g., `actionLoading[id]` when the key doesn't exist), Alpine won't remove the attribute. Wrap in `!!()` to force a boolean: `x-bind:disabled="!!(!canWrite() || actionLoading[id])"`.

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

## Panel Action Scripts

Panel action scripts handle server-side logic. They run as POSIX shell (`sh`), not bash.

**Critical: JSON handling in action scripts:**
- `PANEL_PARAMS` may contain pretty-printed JSON with control characters
- **Always** sanitize before parsing with jq:
```sh
PARAMS=$(printf '%s' "$PANEL_PARAMS" | tr -d '\n\r\t' | jq -c '.' 2>/dev/null || printf '%s' "$PANEL_PARAMS")
```
- Use `printf '%s' "$var" | jq` instead of `echo "$var" | jq` (echo mangles control characters)
- Memory query output is nested: `{"output": "{\"results\": [...]}"}`. Extract with:
```sh
RAW=$(pd memory:query --schema="$SCHEMA" --sql="$SQL" 2>&1)
printf '%s' "$RAW" | jq -r '.output' | jq -c '.results // []'
```

**Action endpoint:** `POST /api/panel/{panelStateId}/action`

For script-based panels, the action is handled by running the script with env vars:
- `PANEL_ACTION` - the action name
- `PANEL_PARAMS` - JSON-encoded params (sanitize before use!)
- `PANEL_STATE` - JSON-encoded current state

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

**AbortController for cancellable fetches:**
When users click rapidly, cancel previous in-flight requests:
```blade
diffAbortController: null,

async fetchData(id) {
    if (this.diffAbortController) this.diffAbortController.abort();
    this.diffAbortController = new AbortController();
    try {
        const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            signal: this.diffAbortController.signal,
            body: JSON.stringify({ action: 'load', params: { id } })
        });
        // handle response...
    } catch (e) {
        if (e.name === 'AbortError') return; // User clicked something else
        this.error = e.message;
    }
}
```

**Debounced state sync:**
```blade
syncState(immediate = false) {
    if (this.syncTimeout) clearTimeout(this.syncTimeout);
    const doSync = () => {
        fetch(`/api/panel/${this.panelStateId}/state`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ state: { /* ... */ }, merge: true })
        });
    };
    if (immediate) doSync();
    else this.syncTimeout = setTimeout(doSync, 300);
}
```

**Animations:** Use Alpine's `x-collapse` directive for smooth expand/collapse.

## Panel Dependencies

Base dependencies (Tailwind, Alpine, Font Awesome) are always loaded for all panels.
To add extra CDN libraries, create a `dependencies.json` file:
```json
[
  {"type": "stylesheet", "url": "https://cdn.example.com/lib.css"},
  {"type": "script", "url": "https://cdn.example.com/lib.js", "defer": true}
]
```
Each entry needs `type` ("script" or "stylesheet") and `url`. Optional: `defer`, `crossorigin`.

## Notes
- Scripts have a 5-minute timeout
- Panels can include an optional `script.sh` for peek and action handling
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
# Push from default location
pd tool:push my-tool

# Push from custom directory
pd tool:push my-tool --directory=/tmp/my-workspace/my-tool
```
CLI;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $slug = $input['slug'] ?? '';

        if (empty($slug)) {
            return ToolResult::error('slug is required');
        }

        if (!preg_match('/^[a-z0-9-]+$/', $slug) || strlen($slug) > 64) {
            return ToolResult::error('slug must contain only lowercase letters, numbers, and hyphens (max 64 characters)');
        }

        $directory = $input['directory'] ?? "/tmp/pocketdev/tools/{$slug}";
        $directory = rtrim($directory, '/');

        if (!is_dir($directory)) {
            return ToolResult::error("Directory not found: {$directory}");
        }

        // Read meta.json (required for new tools, optional for updates)
        $meta = null;
        $metaPath = "{$directory}/meta.json";
        if (file_exists($metaPath)) {
            $metaContent = file_get_contents($metaPath);
            $meta = json_decode($metaContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ToolResult::error("Invalid JSON in meta.json: " . json_last_error_msg());
            }
            if ($meta !== null && !is_array($meta)) {
                return ToolResult::error("meta.json must contain a JSON object, not a scalar value");
            }
        }

        // Check if tool exists
        $existingTool = PocketTool::where('slug', $slug)->first();
        $isUpdate = $existingTool !== null;

        if ($isUpdate) {
            return $this->updateTool($existingTool, $directory, $meta);
        } else {
            return $this->createTool($slug, $directory, $meta);
        }
    }

    private function updateTool(PocketTool $tool, string $directory, ?array $meta): ToolResult
    {
        if ($tool->isPocketdev()) {
            return ToolResult::error("Cannot modify PocketDev tool '{$tool->slug}'. Only user-created tools can be modified.");
        }

        $changes = [];
        $warnings = [];

        // Update metadata fields from meta.json
        if ($meta) {
            if (isset($meta['name']) && $meta['name'] !== $tool->name) {
                $tool->name = $meta['name'];
                $changes[] = 'meta.json (name)';
            }
            if (isset($meta['description']) && $meta['description'] !== $tool->description) {
                $tool->description = $meta['description'];
                $changes[] = 'meta.json (description)';
            }
            if (isset($meta['category']) && $meta['category'] !== $tool->category) {
                $tool->category = $meta['category'];
                $changes[] = 'meta.json (category)';
            }
            if (isset($meta['enabled']) && (bool)$meta['enabled'] !== (bool)$tool->enabled) {
                $tool->enabled = (bool)$meta['enabled'];
                $changes[] = 'meta.json (enabled)';
            }
            if (isset($meta['type']) && $meta['type'] !== $tool->type) {
                $newType = $meta['type'];
                if (!in_array($newType, [PocketTool::TYPE_SCRIPT, PocketTool::TYPE_PANEL])) {
                    return ToolResult::error('type must be "script" or "panel" in meta.json');
                }
                // Validate the new type's required file exists
                if ($newType === PocketTool::TYPE_PANEL && empty($tool->blade_template) && !file_exists("{$directory}/template.blade.php")) {
                    return ToolResult::error("Cannot change type to 'panel': template.blade.php is required but not found in {$directory}/ and no existing template is stored.");
                }
                if ($newType === PocketTool::TYPE_SCRIPT && empty($tool->script) && !file_exists("{$directory}/script.sh")) {
                    return ToolResult::error("Cannot change type to 'script': script.sh is required but not found in {$directory}/ and no existing script is stored.");
                }
                // Null out the old type's primary content to prevent stale data.
                // Also flag old-type files to be skipped during file processing below,
                // otherwise they'd be re-read from disk and undo this cleanup.
                if ($newType === PocketTool::TYPE_SCRIPT) {
                    $tool->blade_template = null;
                    $tool->panel_dependencies = null;
                    $skipPanelOnlyFiles = true;
                } elseif ($newType === PocketTool::TYPE_PANEL) {
                    $tool->script = null;
                    $skipScriptOnlyFiles = true;
                }
                $tool->type = $newType;
                $changes[] = 'meta.json (type)';
            }
            // Warn if slug in meta.json differs from the target tool slug
            if (isset($meta['slug']) && $meta['slug'] !== $tool->slug) {
                $warnings[] = "Note: slug in meta.json ('{$meta['slug']}') differs from target tool slug ('{$tool->slug}'). Slug changes are not supported via push.";
            }
        }

        // When changing type, skip files belonging to the old type so stale data
        // from the old type isn't re-read from disk and undoing the cleanup above.
        $skipPanelOnlyFiles = $skipPanelOnlyFiles ?? false;
        $skipScriptOnlyFiles = $skipScriptOnlyFiles ?? false;

        // Track which files were found for diagnostic output
        $filesChecked = [];

        // Update system_prompt
        $systemPromptPath = "{$directory}/system_prompt.md";
        if (file_exists($systemPromptPath)) {
            $filesChecked[] = $systemPromptPath;
            $content = file_get_contents($systemPromptPath);
            if (empty(trim($content))) {
                return ToolResult::error("system_prompt.md must not be empty");
            }
            if ($content !== $tool->system_prompt) {
                $tool->system_prompt = $content;
                $changes[] = 'system_prompt.md';
            }
        }

        // Update input_schema
        $schemaPath = "{$directory}/input_schema.json";
        if (file_exists($schemaPath)) {
            $filesChecked[] = $schemaPath;
            $schemaContent = file_get_contents($schemaPath);
            $schema = json_decode($schemaContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ToolResult::error("Invalid JSON in input_schema.json: " . json_last_error_msg());
            }
            if ($schema !== null && !is_array($schema)) {
                return ToolResult::error("input_schema.json must contain a JSON object, not a scalar value");
            }
            if ($schema !== $tool->input_schema) {
                $tool->input_schema = $schema;
                $changes[] = 'input_schema.json';
            }
        }

        // Update blade_template (skip if type was just changed to script — panel-only file)
        $templatePath = "{$directory}/template.blade.php";
        if (file_exists($templatePath) && !$skipPanelOnlyFiles) {
            $filesChecked[] = $templatePath;
            $content = file_get_contents($templatePath);
            if ($tool->type === PocketTool::TYPE_PANEL && empty(trim($content))) {
                return ToolResult::error("template.blade.php must not be empty for panel-type tools");
            }
            if ($content !== $tool->blade_template) {
                $tool->blade_template = $content;
                $changes[] = 'template.blade.php';
            }
        }

        // Update script (skip if type was just changed to panel — script-only file)
        $scriptPath = "{$directory}/script.sh";
        if (file_exists($scriptPath) && !$skipScriptOnlyFiles) {
            $filesChecked[] = $scriptPath;
            $content = file_get_contents($scriptPath);
            if ($tool->type === PocketTool::TYPE_SCRIPT && empty(trim($content))) {
                return ToolResult::error("script.sh must not be empty for script-type tools");
            }
            if ($content !== $tool->script) {
                $tool->script = $content;
                $changes[] = 'script.sh';
            }
        }

        // Update panel_dependencies (skip if type was just changed to script — panel-only file)
        $depsPath = "{$directory}/dependencies.json";
        if (file_exists($depsPath) && !$skipPanelOnlyFiles) {
            $filesChecked[] = $depsPath;
            $depsContent = file_get_contents($depsPath);
            $deps = json_decode($depsContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ToolResult::error("Invalid JSON in dependencies.json: " . json_last_error_msg());
            }
            if ($deps !== null && !is_array($deps)) {
                return ToolResult::error("dependencies.json must contain a JSON array, not a scalar value");
            }
            if ($deps !== $tool->panel_dependencies) {
                $tool->panel_dependencies = $deps;
                $changes[] = 'dependencies.json';
            }
        }

        if (empty($changes)) {
            $output = ["No changes detected for '{$tool->name}' ({$tool->slug})."];
            if (!empty($filesChecked)) {
                $output[] = "";
                $output[] = "Checked files (all match current values):";
                foreach ($filesChecked as $file) {
                    $output[] = "  - {$file}";
                }
            } else {
                $output[] = "";
                $output[] = "No files found in {$directory}/ besides meta.json.";
            }
            if (!empty($warnings)) {
                $output[] = "";
                foreach ($warnings as $warning) {
                    $output[] = $warning;
                }
            }
            return ToolResult::success(implode("\n", $output));
        }

        try {
            $tool->save();

            $typeLabel = $tool->type === PocketTool::TYPE_PANEL ? 'panel' : 'tool';
            $output = [
                "Updated {$typeLabel}: {$tool->name} ({$tool->slug})",
                "",
                "ID: {$tool->id}",
                "Type: {$tool->type}",
                "Enabled: " . ($tool->enabled ? 'yes' : 'no'),
                "",
                "Changed: " . implode(', ', $changes),
            ];

            if (!empty($warnings)) {
                $output[] = "";
                foreach ($warnings as $warning) {
                    $output[] = $warning;
                }
            }

            return ToolResult::success(implode("\n", $output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to update tool: ' . $e->getMessage());
        }
    }

    private function createTool(string $slug, string $directory, ?array $meta): ToolResult
    {
        // For creation, meta.json is required
        if (!$meta) {
            return ToolResult::error("meta.json is required to create a new tool. File not found in {$directory}/");
        }

        $name = $meta['name'] ?? '';
        $description = $meta['description'] ?? '';
        $type = $meta['type'] ?? PocketTool::TYPE_SCRIPT;
        $category = $meta['category'] ?? 'custom';

        if (empty($name)) {
            return ToolResult::error('name is required in meta.json');
        }

        if (empty($description)) {
            return ToolResult::error('description is required in meta.json');
        }

        // system_prompt.md is required
        $systemPromptPath = "{$directory}/system_prompt.md";
        if (!file_exists($systemPromptPath)) {
            return ToolResult::error("system_prompt.md is required to create a new tool. File not found in {$directory}/");
        }
        $systemPrompt = file_get_contents($systemPromptPath);
        if (empty(trim($systemPrompt))) {
            return ToolResult::error("system_prompt.md must not be empty");
        }

        // Validate type
        if (!in_array($type, [PocketTool::TYPE_SCRIPT, PocketTool::TYPE_PANEL])) {
            return ToolResult::error('type must be "script" or "panel" in meta.json');
        }

        // Check for duplicate slug
        $panelRegistry = app(PanelRegistry::class);
        if ($panelRegistry->has($slug)) {
            return ToolResult::error("A system panel with slug '{$slug}' already exists. Choose a different slug.");
        }

        if (PocketTool::where('slug', $slug)->exists()) {
            return ToolResult::error("A tool with slug '{$slug}' already exists");
        }

        // Read optional files
        $script = null;
        $scriptPath = "{$directory}/script.sh";
        if (file_exists($scriptPath)) {
            $script = file_get_contents($scriptPath);
        }

        $bladeTemplate = null;
        $templatePath = "{$directory}/template.blade.php";
        if (file_exists($templatePath)) {
            $bladeTemplate = file_get_contents($templatePath);
        }

        $inputSchema = null;
        $schemaPath = "{$directory}/input_schema.json";
        if (file_exists($schemaPath)) {
            $schemaContent = file_get_contents($schemaPath);
            $inputSchema = json_decode($schemaContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ToolResult::error("Invalid JSON in input_schema.json: " . json_last_error_msg());
            }
            if ($inputSchema !== null && !is_array($inputSchema)) {
                return ToolResult::error("input_schema.json must contain a JSON object, not a scalar value");
            }
        }

        $panelDependencies = null;
        $depsPath = "{$directory}/dependencies.json";
        if (file_exists($depsPath)) {
            $depsContent = file_get_contents($depsPath);
            $panelDependencies = json_decode($depsContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ToolResult::error("Invalid JSON in dependencies.json: " . json_last_error_msg());
            }
            if ($panelDependencies !== null && !is_array($panelDependencies)) {
                return ToolResult::error("dependencies.json must contain a JSON array, not a scalar value");
            }
        }

        // Type-specific validation
        if ($type === PocketTool::TYPE_SCRIPT) {
            if ($script === null) {
                return ToolResult::error("script.sh is required for type=script. File not found in {$directory}/");
            }
            if (empty(trim($script))) {
                return ToolResult::error('script.sh must not be empty for script-type tools');
            }
        }
        if ($type === PocketTool::TYPE_PANEL) {
            if ($bladeTemplate === null) {
                return ToolResult::error("template.blade.php is required for type=panel. File not found in {$directory}/");
            }
            if (empty(trim($bladeTemplate))) {
                return ToolResult::error('template.blade.php must not be empty for panel-type tools');
            }
        }

        try {
            $tool = PocketTool::create([
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'system_prompt' => $systemPrompt,
                'type' => $type,
                'script' => $script,
                'blade_template' => $bladeTemplate,
                'panel_dependencies' => $panelDependencies,
                'source' => PocketTool::SOURCE_USER,
                'category' => $category,
                'enabled' => $meta['enabled'] ?? true,
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
        return 'tool:push';
    }
}
