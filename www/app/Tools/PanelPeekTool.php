<?php

namespace App\Tools;

use App\Models\PanelState;
use App\Models\PocketTool;
use App\Models\Screen;
use App\Panels\PanelRegistry;
use Illuminate\Support\Facades\Process;

/**
 * Peek at a panel's current state.
 *
 * Returns a text representation of what the user sees in a panel,
 * suitable for AI context. This allows AI to understand panel state
 * without visual access.
 */
class PanelPeekTool extends Tool
{
    public string $name = 'PanelPeek';

    public string $description = 'Peek at the current visible state of an open panel.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'panel_slug' => [
                'type' => 'string',
                'description' => 'The slug of the panel type to peek at. If multiple instances exist, peeks at the first one unless id is specified.',
            ],
            'id' => [
                'type' => 'string',
                'description' => 'Optional. The specific panel state ID (full UUID). Use this when multiple instances of the same panel type are open.',
            ],
        ],
        'required' => ['panel_slug'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Use PanelPeek to see what's currently visible in an open panel. This is useful when:
- You need to know what the user is looking at in a panel
- You want to verify state changes after user interactions
- You're helping the user with something displayed in a panel

The peek returns a text representation of the panel's visible state, not the raw state data.

**Important:** Only works on panels that are currently open in the session.
Check the "Open Panels" section of the system prompt to see available panels and their IDs.
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
# Peek at a file explorer panel
pd panel:peek file-explorer

# Peek at a specific panel instance by ID
pd panel:peek file-explorer --id=019c2fcc-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

Peek at first instance of a panel type:
```json
{
  "panel_slug": "file-explorer"
}
```

Peek at a specific panel instance:
```json
{
  "panel_slug": "file-explorer",
  "id": "019c2fcc-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
}
```
API;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $panelSlug = $input['panel_slug'] ?? '';
        $id = $input['id'] ?? null;

        if (empty($panelSlug)) {
            return ToolResult::error('panel_slug is required');
        }

        // Find the panel state
        $panelState = null;

        if ($id) {
            // Find by exact UUID
            $panelState = PanelState::where('panel_slug', $panelSlug)
                ->where('id', $id)
                ->first();

            if (!$panelState) {
                return ToolResult::error("Panel '{$panelSlug}' with id '{$id}' not found. It may have been closed.");
            }
        } else {
            // No ID provided - get most recent panel of this type
            $panelState = PanelState::where('panel_slug', $panelSlug)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$panelState) {
                // Build list of available panels for helpful error message
                $availablePanels = $this->getAvailablePanelSlugs();
                $availableList = empty($availablePanels)
                    ? 'No panels are currently open.'
                    : 'Available: ' . implode(', ', $availablePanels);

                return ToolResult::error(
                    "No open panel found with slug '{$panelSlug}'. {$availableList}"
                );
            }
        }

        // First check for system panel (from PanelRegistry)
        $panelRegistry = app(PanelRegistry::class);
        if ($panelRegistry->has($panelSlug)) {
            $systemPanel = $panelRegistry->get($panelSlug);
            $peekOutput = $systemPanel->peek($panelState->parameters ?? [], $panelState->state ?? []);
            return ToolResult::success($peekOutput);
        }

        // Then check database panel (PocketTool)
        $panel = PocketTool::where('slug', $panelSlug)
            ->where('type', PocketTool::TYPE_PANEL)
            ->first();

        if (!$panel) {
            return ToolResult::error("Panel type '{$panelSlug}' not found");
        }

        // Generate peek output for database panel
        $peekOutput = $this->generatePeek($panel, $panelState);

        return ToolResult::success($peekOutput);
    }

    /**
     * Generate peek output for a panel.
     */
    private function generatePeek(PocketTool $panel, PanelState $panelState): string
    {
        // If no script, return a basic peek
        if (!$panel->hasScript()) {
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

                return "## Panel Error\n\nPeek script failed: " . $process->errorOutput();
            }

            return $process->output();
        } catch (\Throwable $e) {
            \Log::error('Panel peek error', [
                'panel_slug' => $panelState->panel_slug,
                'panel_state_id' => $panelState->id,
                'error' => $e->getMessage(),
            ]);

            return "## Panel Error\n\n" . $e->getMessage();
        }
    }

    /**
     * Generate a basic peek when no script is defined.
     */
    private function generateBasicPeek(PocketTool $panel, PanelState $panelState): string
    {
        $output = "## {$panel->name}\n\n";
        $output .= "**Panel ID:** {$panelState->id}\n\n";

        if (!empty($panelState->parameters)) {
            $output .= "**Parameters:**\n";
            foreach ($panelState->parameters as $key => $value) {
                $output .= "- {$key}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
            $output .= "\n";
        }

        if (!empty($panelState->state)) {
            $output .= "**Current State:**\n";
            $output .= "```json\n" . json_encode($panelState->state, JSON_PRETTY_PRINT) . "\n```\n";
        } else {
            $output .= "*Panel has no recorded state.*\n";
        }

        $output .= "\n*Note: No peek script defined for this panel. Showing raw state data.*\n";

        return $output;
    }

    /**
     * Get list of currently open panel slugs.
     */
    private function getAvailablePanelSlugs(): array
    {
        return PanelState::select('panel_slug')
            ->distinct()
            ->orderBy('panel_slug')
            ->pluck('panel_slug')
            ->toArray();
    }

    public function getArtisanCommand(): ?string
    {
        return 'panel:peek';
    }
}
