<?php

namespace App\Tools;

use App\Models\PocketTool;
use Illuminate\Support\Facades\Process;

/**
 * Run a user tool.
 */
class ToolRunTool extends Tool
{
    public string $name = 'ToolRun';

    public string $description = 'Execute a user-created tool with optional arguments.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'slug' => [
                'type' => 'string',
                'description' => 'The slug of the tool to run.',
            ],
            'arguments' => [
                'type' => 'object',
                'description' => 'Named arguments to pass to the tool (become TOOL_* env vars).',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolRun to execute a user-created tool.

## CLI Example

```bash
php artisan tool:run greet -- --name=World
```

**Important:** The `--` separator is REQUIRED before any `--arguments`.

## How Arguments Work

Arguments passed via CLI become environment variables in the script:
- `--name=John` → `$TOOL_NAME` in script
- `--my-param=value` → `$TOOL_MY_PARAM` in script

## Notes
- Only user-created tools can be run with tool:run
- PocketDev built-in tools have their own artisan commands (e.g., `memory:query`)
- Scripts have a 5-minute timeout
- Use `tool:list` to see available tools and their slugs
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $slug = $input['slug'] ?? '';
        $arguments = $input['arguments'] ?? [];

        if (empty($slug)) {
            return ToolResult::error('slug is required');
        }

        $tool = PocketTool::where('slug', $slug)->first();

        if (!$tool) {
            return ToolResult::error("Tool '{$slug}' not found");
        }

        if ($tool->isPocketdev()) {
            $command = $tool->getArtisanCommand();
            if ($command) {
                return ToolResult::error("'{$slug}' is a PocketDev tool. Use: php artisan {$command}");
            }
            return ToolResult::error("'{$slug}' is a PocketDev tool and cannot be run directly");
        }

        if (!$tool->hasScript()) {
            return ToolResult::error("Tool '{$slug}' has no script defined");
        }

        // Validate required parameters if tool has input_schema
        if ($tool->input_schema && !empty($tool->input_schema['properties'])) {
            $schema = $tool->input_schema;
            $required = $schema['required'] ?? [];

            foreach ($required as $param) {
                if (!isset($arguments[$param])) {
                    return ToolResult::error("Missing required parameter: {$param}");
                }
            }
        }

        // Build environment variables from arguments (uppercase with TOOL_ prefix)
        $envVars = [];
        foreach ($arguments as $name => $value) {
            $envName = 'TOOL_' . strtoupper(str_replace('-', '_', $name));
            $envVars[$envName] = is_string($value) ? $value : json_encode($value);
        }

        $tempFile = null;
        try {
            // Create temp script file
            $tempFile = tempnam(sys_get_temp_dir(), 'pocket_tool_');
            if ($tempFile === false) {
                return ToolResult::error('Failed to create temporary script file');
            }
            file_put_contents($tempFile, $tool->script);
            chmod($tempFile, 0755);

            // Execute the script with environment variables
            $result = Process::timeout(300)->env($envVars)->path($context->workingDirectory)->run($tempFile);

            $output = $result->output();
            $errorOutput = $result->errorOutput();

            if ($result->failed()) {
                $message = $errorOutput ?: $output ?: 'Script execution failed with exit code ' . $result->exitCode();
                return ToolResult::error($message);
            }

            return ToolResult::success(trim($output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to run tool: ' . $e->getMessage());
        } finally {
            // Clean up temp file
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function getArtisanCommand(): ?string
    {
        return 'tool:run';
    }
}
