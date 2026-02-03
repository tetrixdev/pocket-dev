<?php

namespace App\Tools;

use App\Models\Credential;
use App\Models\PanelState;
use App\Models\PocketTool;
use App\Models\Screen;
use App\Panels\PanelRegistry;
use App\Streaming\StreamEvent;
use Illuminate\Support\Facades\Process;

/**
 * Run a user tool (script or panel).
 *
 * For script tools: Executes the bash script and returns output.
 * For panel tools: Opens the panel in the user's UI and returns a peek.
 */
class ToolRunTool extends Tool
{
    public string $name = 'ToolRun';

    public string $description = 'Execute a user-created tool (script) or open a panel.';

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
                'description' => 'Named arguments to pass to the tool. For scripts: become TOOL_* env vars. For panels: become panel parameters.',
            ],
        ],
        'required' => ['slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use ToolRun to execute a user-created tool or open a panel.

## Tool Types

- **Script tools**: Execute bash scripts and return text output
- **Panel tools**: Open interactive UI panels in the user's browser

## How Arguments Work

For script tools, arguments become environment variables:
- `--name=John` → `$TOOL_NAME` in script
- `--my-param=value` → `$TOOL_MY_PARAM` in script

For panel tools, arguments become panel parameters:
- `--path=/workspace` → Panel opens with `path: "/workspace"`

## Notes
- Only user-created tools can be run with tool:run
- PocketDev built-in tools have their own artisan commands (e.g., `memory:query`)
- Scripts have a 5-minute timeout
- Panels open in the user's UI and return a "peek" showing visible state
- Use `tool:list` to see available tools and their slugs
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
pd tool:run greet -- --name=World
```

**Important:** The `--` separator is REQUIRED for `tool:run` only, before any `--arguments`.
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "slug": "greet",
  "arguments": {
    "name": "World"
  }
}
```
API;

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
                return ToolResult::error("'{$slug}' is a PocketDev tool. Use: pd {$command}");
            }

            return ToolResult::error("'{$slug}' is a PocketDev tool and cannot be run directly");
        }

        // Validate required parameters if tool has input_schema
        if ($tool->input_schema && ! empty($tool->input_schema['properties'])) {
            $schema = $tool->input_schema;
            $required = $schema['required'] ?? [];

            foreach ($required as $param) {
                if (! isset($arguments[$param])) {
                    return ToolResult::error("Missing required parameter: {$param}");
                }
            }
        }

        // Handle panel tools differently from script tools
        if ($tool->isPanel()) {
            return $this->executePanel($tool, $arguments, $context);
        }

        // Script tool execution
        return $this->executeScript($tool, $arguments, $context);
    }

    /**
     * Execute a script tool.
     */
    private function executeScript(PocketTool $tool, array $arguments, ExecutionContext $context): ToolResult
    {
        if (! $tool->hasScript()) {
            return ToolResult::error("Tool '{$tool->slug}' has no script defined");
        }

        // Build environment variables from arguments (uppercase with TOOL_ prefix)
        $envVars = [];
        foreach ($arguments as $name => $value) {
            $envName = 'TOOL_'.strtoupper(str_replace('-', '_', $name));
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

            // Get credentials and merge with tool arguments (tool args take precedence)
            // Workspace-specific credentials override global ones
            $workspace = $context->getWorkspace();
            $workspaceId = $workspace?->id;
            $credentials = Credential::getEnvArrayForWorkspace($workspaceId);
            $allEnvVars = array_merge($credentials, $envVars);

            // Execute the script with environment variables
            $result = Process::timeout(300)->env($allEnvVars)->path($context->workingDirectory)->run($tempFile);

            $output = $result->output();
            $errorOutput = $result->errorOutput();

            if ($result->failed()) {
                $message = $errorOutput ?: $output ?: 'Script execution failed with exit code '.$result->exitCode();

                return ToolResult::error($message);
            }

            return ToolResult::success(trim($output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to run tool: '.$e->getMessage());
        } finally {
            // Clean up temp file
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Execute a panel tool - opens the panel in the user's UI.
     */
    private function executePanel(PocketTool $tool, array $arguments, ExecutionContext $context): ToolResult
    {
        $session = $context->getSession();

        if (! $session) {
            return ToolResult::error(
                "Cannot open panel: no active session context. ".
                "Panel tools require execution within a PocketDev conversation. ".
                "If running from terminal, use: pd tool:run {$tool->slug} --session=<session-id>"
            );
        }

        // Create panel state with the provided arguments as parameters
        $panelState = PanelState::create([
            'panel_slug' => $tool->slug,
            'parameters' => $arguments,
            'state' => [],
        ]);

        // Create screen in the session
        $screen = Screen::createPanelScreen(
            $session,
            $tool->slug,
            $panelState,
            $arguments
        );

        // Activate the screen so it's immediately visible
        $screen->activate();

        // Emit screen_created event if we have a stream context
        // This notifies the frontend to refresh the screen tabs
        if ($context->streamManager && $context->conversationUuid) {
            $context->streamManager->appendEvent(
                $context->conversationUuid,
                StreamEvent::screenCreated($screen->id, 'panel', $tool->slug)
            );
        }

        // Generate peek output so AI knows what the user sees
        $peekOutput = $this->generatePeek($tool, $panelState);

        // Build response with structured info + peek
        $shortId = substr($panelState->id, 0, 8);
        $output = "Opened panel '{$tool->name}' (id: {$shortId})\n";
        $output .= "The panel is now visible in the user's UI.\n\n";
        $output .= "---\n\n";
        $output .= $peekOutput;

        return ToolResult::success($output);
    }

    /**
     * Generate peek output for a panel.
     */
    private function generatePeek(PocketTool $panel, PanelState $panelState): string
    {
        // If no script, return a basic peek
        if (! $panel->hasScript()) {
            return $this->generateBasicPeek($panel, $panelState);
        }

        try {
            // Set environment variables for the peek script
            $env = [
                'PANEL_STATE' => json_encode($panelState->state ?? []),
                'PANEL_PARAMS' => json_encode($panelState->parameters ?? []),
                'PANEL_INSTANCE_ID' => $panelState->id,
                'PANEL_SLUG' => $panelState->panel_slug,
            ];

            // Run the peek script
            $process = Process::env($env)
                ->timeout(30)
                ->run($panel->script);

            if ($process->failed()) {
                \Log::warning('Panel peek script failed', [
                    'panel_slug' => $panelState->panel_slug,
                    'exit_code' => $process->exitCode(),
                    'stderr' => $process->errorOutput(),
                ]);

                return "## Panel Opened\n\nPeek script failed: ".$process->errorOutput();
            }

            return $process->output();
        } catch (\Throwable $e) {
            \Log::error('Panel peek error', [
                'panel_slug' => $panelState->panel_slug,
                'panel_state_id' => $panelState->id,
                'error' => $e->getMessage(),
            ]);

            return "## Panel Opened\n\nPeek failed: ".$e->getMessage();
        }
    }

    /**
     * Generate a basic peek when no script is defined.
     */
    private function generateBasicPeek(PocketTool $panel, PanelState $panelState): string
    {
        $output = "## {$panel->name}\n\n";

        if (! empty($panelState->parameters)) {
            $output .= "**Parameters:**\n";
            foreach ($panelState->parameters as $key => $value) {
                $output .= "- {$key}: ".(is_array($value) ? json_encode($value) : $value)."\n";
            }
            $output .= "\n";
        }

        $output .= "*Panel is now open. Use `panel:peek {$panel->slug}` to see current state after user interacts with it.*\n";

        return $output;
    }

    public function getArtisanCommand(): ?string
    {
        return 'tool:run';
    }
}
