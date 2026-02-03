<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelState;
use App\Models\PocketTool;
use App\Panels\PanelRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Process;

class PanelController extends Controller
{
    /**
     * Render a panel's Blade template.
     *
     * Returns HTML that can be inserted into the panel container.
     */
    public function render(PanelState $panelState, PanelRegistry $registry): Response
    {
        $slug = $panelState->panel_slug;
        $params = $panelState->parameters ?? [];
        $state = $panelState->state ?? [];

        // Check for system panel first
        $systemPanel = $registry->get($slug);
        if ($systemPanel) {
            try {
                $html = $systemPanel->render($params, $state, $panelState->id);
                return response($html)->header('Content-Type', 'text/html');
            } catch (\Throwable $e) {
                \Log::error('System panel render error', [
                    'panel_slug' => $slug,
                    'panel_state_id' => $panelState->id,
                    'error' => $e->getMessage(),
                ]);

                return response(
                    '<div class="p-4 text-red-500">Panel error: ' . e($e->getMessage()) . '</div>',
                    500
                )->header('Content-Type', 'text/html');
            }
        }

        // Fall back to database panel
        $panel = PocketTool::where('slug', $slug)
            ->where('type', PocketTool::TYPE_PANEL)
            ->first();

        if (!$panel) {
            return response('Panel not found', 404);
        }

        if (!$panel->hasBladeTemplate()) {
            return response('Panel has no template', 400);
        }

        try {
            // Render the Blade template with panel context
            $html = Blade::render($panel->blade_template, [
                'parameters' => $params,
                'state' => $state,
                'panelState' => $panelState,
                'panel' => $panel,
            ]);

            return response($html)
                ->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            \Log::error('Panel render error', [
                'panel_slug' => $slug,
                'panel_state_id' => $panelState->id,
                'error' => $e->getMessage(),
            ]);

            return response(
                '<div class="p-4 text-red-500">Panel error: ' . e($e->getMessage()) . '</div>',
                500
            )->header('Content-Type', 'text/html');
        }
    }

    /**
     * Get current panel state.
     */
    public function getState(PanelState $panelState): JsonResponse
    {
        return response()->json([
            'id' => $panelState->id,
            'panel_slug' => $panelState->panel_slug,
            'parameters' => $panelState->parameters,
            'state' => $panelState->state,
            'updated_at' => $panelState->updated_at,
        ]);
    }

    /**
     * Update panel state (debounced sync from frontend).
     */
    public function updateState(Request $request, PanelState $panelState): JsonResponse
    {
        $validated = $request->validate([
            'state' => 'required|array',
            'merge' => 'nullable|boolean',
        ]);

        if ($request->boolean('merge', false)) {
            // Merge new state values into existing state
            $panelState->mergeState($validated['state']);
        } else {
            // Replace entire state
            $panelState->state = $validated['state'];
        }

        $panelState->save();

        return response()->json([
            'ok' => true,
            'state' => $panelState->state,
            'updated_at' => $panelState->updated_at,
        ]);
    }

    /**
     * Handle a panel action (for interactive panels with lazy loading).
     *
     * Actions enable server-side logic for panel interactions like:
     * - Lazy loading children (file explorer)
     * - Fetching details for selected items
     * - Computing derived values
     */
    public function action(Request $request, PanelState $panelState, PanelRegistry $registry): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string',
            'params' => 'nullable|array',
        ]);

        $action = $validated['action'];
        $params = $validated['params'] ?? [];
        $slug = $panelState->panel_slug;
        $state = $panelState->state ?? [];

        // Check for system panel first
        $systemPanel = $registry->get($slug);
        if ($systemPanel) {
            try {
                $result = $systemPanel->handleAction($action, $params, $state);

                // If action returned state updates, merge them into panel state
                if (!empty($result['state'])) {
                    $panelState->mergeState($result['state']);
                    $panelState->save();
                }

                return response()->json([
                    'ok' => true,
                    'html' => $result['html'] ?? null,
                    'state' => $result['state'] ?? null,
                    'data' => $result['data'] ?? null,
                    'error' => $result['error'] ?? null,
                ]);
            } catch (\Throwable $e) {
                \Log::error('Panel action error', [
                    'panel_slug' => $slug,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        // Database panel - use script if available
        $panel = PocketTool::where('slug', $slug)
            ->where('type', PocketTool::TYPE_PANEL)
            ->first();

        if (!$panel) {
            return response()->json([
                'ok' => false,
                'error' => 'Panel not found',
            ], 404);
        }

        // If panel has a script, run it with action context
        if ($panel->hasScript()) {
            try {
                $env = [
                    'PANEL_ACTION' => $action,
                    'PANEL_PARAMS' => json_encode($params),
                    'PANEL_STATE' => json_encode($state),
                    'PANEL_INSTANCE_ID' => $panelState->id,
                    'PANEL_SLUG' => $slug,
                ];

                $process = Process::env($env)
                    ->timeout(30)
                    ->run($panel->script);

                if ($process->failed()) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'Action script failed: ' . $process->errorOutput(),
                    ], 500);
                }

                // Try to parse as JSON response
                $output = trim($process->output());
                $result = json_decode($output, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($result)) {
                    // Merge state updates if provided
                    if (!empty($result['state'])) {
                        $panelState->mergeState($result['state']);
                        $panelState->save();
                    }

                    return response()->json([
                        'ok' => true,
                        'html' => $result['html'] ?? null,
                        'state' => $result['state'] ?? null,
                        'data' => $result['data'] ?? null,
                        'error' => $result['error'] ?? null,
                    ]);
                }

                // Non-JSON output treated as HTML
                return response()->json([
                    'ok' => true,
                    'html' => $output,
                    'state' => null,
                    'data' => null,
                    'error' => null,
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        return response()->json([
            'ok' => false,
            'error' => "Action '{$action}' not supported by this panel",
        ], 400);
    }

    /**
     * Run the panel's peek script and return markdown.
     *
     * The peek script generates a text representation of what the user sees,
     * suitable for AI context.
     */
    public function peek(PanelState $panelState, PanelRegistry $registry): Response
    {
        $slug = $panelState->panel_slug;
        $params = $panelState->parameters ?? [];
        $state = $panelState->state ?? [];

        // Check for system panel first
        $systemPanel = $registry->get($slug);
        if ($systemPanel) {
            try {
                $markdown = $systemPanel->peek($params, $state);
                return response($markdown)->header('Content-Type', 'text/markdown');
            } catch (\Throwable $e) {
                \Log::error('System panel peek error', [
                    'panel_slug' => $slug,
                    'panel_state_id' => $panelState->id,
                    'error' => $e->getMessage(),
                ]);

                return response(
                    "## Panel Error\n\n" . $e->getMessage(),
                    500
                )->header('Content-Type', 'text/markdown');
            }
        }

        // Fall back to database panel
        $panel = PocketTool::where('slug', $slug)
            ->where('type', PocketTool::TYPE_PANEL)
            ->first();

        if (!$panel) {
            return response('Panel not found', 404)
                ->header('Content-Type', 'text/markdown');
        }

        // If no script, return a basic peek
        if (!$panel->hasScript()) {
            return response($this->generateBasicPeek($panel, $panelState))
                ->header('Content-Type', 'text/markdown');
        }

        try {
            // Set environment variables for the peek script
            $env = [
                'PANEL_STATE' => json_encode($state),
                'PANEL_PARAMS' => json_encode($params),
                'PANEL_INSTANCE_ID' => $panelState->id,
                'PANEL_SLUG' => $slug,
            ];

            // Run the peek script
            $process = Process::env($env)
                ->timeout(30)
                ->run($panel->script);

            if ($process->failed()) {
                \Log::warning('Panel peek script failed', [
                    'panel_slug' => $slug,
                    'exit_code' => $process->exitCode(),
                    'stderr' => $process->errorOutput(),
                ]);

                return response(
                    "## Panel Error\n\nPeek script failed: " . $process->errorOutput(),
                    500
                )->header('Content-Type', 'text/markdown');
            }

            return response($process->output())
                ->header('Content-Type', 'text/markdown');
        } catch (\Throwable $e) {
            \Log::error('Panel peek error', [
                'panel_slug' => $slug,
                'panel_state_id' => $panelState->id,
                'error' => $e->getMessage(),
            ]);

            return response(
                "## Panel Error\n\n" . $e->getMessage(),
                500
            )->header('Content-Type', 'text/markdown');
        }
    }

    /**
     * Generate a basic peek when no script is defined.
     */
    private function generateBasicPeek(PocketTool $panel, PanelState $panelState): string
    {
        $output = "## {$panel->name}\n\n";

        if (!empty($panelState->parameters)) {
            $output .= "**Parameters:**\n";
            foreach ($panelState->parameters as $key => $value) {
                $output .= "- {$key}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
            $output .= "\n";
        }

        if (!empty($panelState->state)) {
            $output .= "**State:**\n";
            $output .= "```json\n" . json_encode($panelState->state, JSON_PRETTY_PRINT) . "\n```\n";
        }

        $output .= "\n*No peek script defined for this panel.*\n";

        return $output;
    }

    /**
     * Close/delete a panel instance.
     */
    public function destroy(PanelState $panelState): JsonResponse
    {
        // The associated screen will have panel_id set to null via nullOnDelete
        $panelState->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * List available panel tools.
     */
    public function availablePanels(PanelRegistry $registry): JsonResponse
    {
        return response()->json($registry->allAvailable());
    }
}
