<?php

namespace App\Tools;

use App\Models\PocketTool;

/**
 * Extract a tool's components to a local directory for editing.
 */
class ToolExtractTool extends Tool
{
    public string $name = 'ToolExtract';

    public string $description = 'Extract a tool to a local directory structure for editing.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'The slug of the tool to extract.',
            ],
            'directory' => [
                'type' => 'string',
                'description' => 'Output directory path. Default: /tmp/pocketdev/tools/{slug}/',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolExtract to extract a tool's components to a local directory for editing. This creates separate files for each component (template, script, system prompt, metadata) so you can use the Edit tool for surgical changes instead of rewriting entire files.

## When to Use
- **Always** before editing an existing tool, unless you created or extracted it earlier **in this same conversation**
- Tools can change between conversations, so never rely on a stale local copy

## Output Structure

```
/tmp/pocketdev/tools/{slug}/
├── meta.json              # slug, name, description, type, category, enabled
├── system_prompt.md       # AI instructions (the system_prompt field)
├── input_schema.json      # Parameter schema (if any)
├── template.blade.php     # Blade template (panels only)
├── script.sh              # Script content (if any)
└── dependencies.json      # Panel CDN dependencies (if any)
```

## Workflow

1. **Extract**: `pd tool:extract my-tool`
2. **Edit**: Use the Read and Edit tools on individual files
3. **Push back**: `pd tool:push my-tool` (reads from the same directory)
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
# Extract to default location
pd tool:extract my-tool

# Extract to a custom directory
pd tool:extract my-tool --directory=/tmp/my-workspace/my-tool
```
CLI;

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

        $outputDir = $input['directory'] ?? "/tmp/pocketdev/tools/{$slug}";
        $outputDir = rtrim($outputDir, '/');

        // Create directory
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            return ToolResult::error("Failed to create directory: {$outputDir}");
        }

        $writtenFiles = [];

        // 1. meta.json - basic metadata
        $meta = [
            'slug' => $tool->slug,
            'name' => $tool->name,
            'description' => $tool->description,
            'type' => $tool->type ?? 'script',
            'category' => $tool->category ?? 'custom',
            'enabled' => (bool)$tool->enabled,
        ];
        file_put_contents("{$outputDir}/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $writtenFiles[] = 'meta.json';

        // 2. system_prompt.md
        if (!empty($tool->system_prompt)) {
            file_put_contents("{$outputDir}/system_prompt.md", $tool->system_prompt);
            $writtenFiles[] = 'system_prompt.md';
        }

        // 3. input_schema.json
        if (!empty($tool->input_schema)) {
            file_put_contents("{$outputDir}/input_schema.json", json_encode($tool->input_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $writtenFiles[] = 'input_schema.json';
        }

        // 4. template.blade.php (panels only)
        if (!empty($tool->blade_template)) {
            file_put_contents("{$outputDir}/template.blade.php", $tool->blade_template);
            $writtenFiles[] = 'template.blade.php';
        }

        // 5. script.sh
        if (!empty($tool->script)) {
            file_put_contents("{$outputDir}/script.sh", $tool->script);
            $writtenFiles[] = 'script.sh';
        }

        // 6. dependencies.json (panels only)
        if (!empty($tool->panel_dependencies)) {
            file_put_contents("{$outputDir}/dependencies.json", json_encode($tool->panel_dependencies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $writtenFiles[] = 'dependencies.json';
        }

        $output = [
            "Extracted tool '{$tool->name}' ({$slug}) to {$outputDir}/",
            "",
            "Files:",
        ];

        foreach ($writtenFiles as $file) {
            $output[] = "  - {$outputDir}/{$file}";
        }

        $output[] = "";
        $isCustomDirectory = isset($input['directory']);
        if ($isCustomDirectory) {
            $output[] = "Edit the files, then push back with: pd tool:push {$slug} --directory={$outputDir}";
        } else {
            $output[] = "Edit the files, then push back with: pd tool:push {$slug}";
        }

        return ToolResult::success(implode("\n", $output));
    }

    public function getArtisanCommand(): ?string
    {
        return 'tool:extract';
    }
}
