<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\PanelState;
use App\Models\PocketTool;
use App\Models\Screen;
use App\Models\Session;
use App\Panels\PanelRegistry;
use App\Services\ConversationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScreenController extends Controller
{
    public function __construct(
        private ConversationFactory $conversationFactory,
        private PanelRegistry $panelRegistry,
    ) {}

    /**
     * Create a new chat screen.
     */
    public function createChat(Request $request, Session $session): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'agent_id' => 'nullable|uuid|exists:agents,id',
            'activate' => 'nullable|boolean',
        ]);

        return DB::transaction(function () use ($validated, $session, $request) {
            // Get agent if provided
            $agentId = $validated['agent_id'] ?? null;
            $agent = $agentId ? Agent::find($agentId) : null;

            // Use factory to create conversation with all settings properly copied
            $conversation = $this->conversationFactory->createForScreen(
                $session,
                ($agent && $agent->enabled) ? $agent : null,
                $validated['title'] ?? 'New Chat'
            );

            // Create the screen
            $screen = Screen::createChatScreen($session, $conversation);

            // Optionally activate the new screen
            if ($request->boolean('activate', true)) {
                $screen->activate();
            }

            $screen->load('conversation');

            return response()->json($screen, 201);
        });
    }

    /**
     * Create a new panel screen.
     */
    public function createPanel(Request $request, Session $session): JsonResponse
    {
        $validated = $request->validate([
            'panel_slug' => 'required|string',
            'parameters' => 'nullable|array',
            'activate' => 'nullable|boolean',
        ]);

        // Check for system panel first (in PanelRegistry)
        $systemPanel = $this->panelRegistry->get($validated['panel_slug']);

        // Then check database for user-created panels
        $dbPanel = PocketTool::where('slug', $validated['panel_slug'])
            ->where('type', PocketTool::TYPE_PANEL)
            ->first();

        if (!$systemPanel && !$dbPanel) {
            return response()->json([
                'error' => 'Panel not found: ' . $validated['panel_slug'],
            ], 400);
        }

        // Get panel info for response
        $panelInfo = $systemPanel
            ? ['slug' => $systemPanel->slug, 'name' => $systemPanel->name, 'description' => $systemPanel->description, 'is_system' => true]
            : ['slug' => $dbPanel->slug, 'name' => $dbPanel->name, 'description' => $dbPanel->description, 'is_system' => false];

        return DB::transaction(function () use ($validated, $session, $panelInfo, $request) {
            // Create panel state
            $panelState = PanelState::create([
                'panel_slug' => $validated['panel_slug'],
                'parameters' => $validated['parameters'] ?? [],
                'state' => [],
            ]);

            // Create the screen
            $screen = Screen::createPanelScreen(
                $session,
                $validated['panel_slug'],
                $panelState,
                $validated['parameters'] ?? null
            );

            // Optionally activate the new screen
            if ($request->boolean('activate', true)) {
                $screen->activate();
            }

            // Only load panel relationship for DB panels (system panels don't have a DB record)
            $screen->load(['panelState']);
            if (!$panelInfo['is_system']) {
                $screen->load(['panel:id,slug,name']);
            }

            return response()->json([
                'screen' => $screen,
                'panel' => $panelInfo,
            ], 201);
        });
    }

    /**
     * Get a screen with its associated data.
     */
    public function show(Screen $screen): JsonResponse
    {
        if ($screen->isChat()) {
            $screen->load('conversation.messages');
        } else {
            $screen->load(['panelState', 'panel']);
        }

        return response()->json($screen);
    }

    /**
     * Activate a screen (make it the current visible screen).
     */
    public function activate(Screen $screen): JsonResponse
    {
        $screen->activate();

        return response()->json([
            'ok' => true,
            'screen_id' => $screen->id,
            'session_id' => $screen->session_id,
        ]);
    }

    /**
     * Close/remove a screen from the session.
     *
     * For chat screens, this archives the conversation but doesn't delete it.
     * For panel screens, this deletes the panel state.
     */
    public function destroy(Screen $screen): JsonResponse
    {
        return DB::transaction(function () use ($screen) {
            $session = $screen->session;
            $screenId = $screen->id;

            if ($screen->isChat() && $screen->conversation) {
                // Archive the conversation (don't delete it)
                $screen->conversation->archive();
            } elseif ($screen->isPanel() && $screen->panelState) {
                // Delete the panel state
                $screen->panelState->delete();
            }

            // Remove from screen order
            $session->removeScreenFromOrder($screenId);

            // If this was the active screen, activate another one
            if ($session->last_active_screen_id === $screenId) {
                $nextScreen = $session->orderedScreens()
                    ->where('id', '!=', $screenId)
                    ->first();

                if ($nextScreen) {
                    $nextScreen->activate();
                } else {
                    $session->update(['last_active_screen_id' => null]);
                }
            }

            // Delete the screen
            $screen->delete();

            return response()->json([
                'ok' => true,
            ]);
        });
    }

    /**
     * Reorder screens within a session.
     */
    public function reorder(Request $request, Session $session): JsonResponse
    {
        $validated = $request->validate([
            'screen_order' => 'required|array',
            'screen_order.*' => 'uuid',
        ]);

        // Verify all screen IDs belong to this session
        $sessionScreenIds = $session->screens()->pluck('id')->toArray();
        $invalidIds = array_diff($validated['screen_order'], $sessionScreenIds);

        if (!empty($invalidIds)) {
            return response()->json([
                'error' => 'Invalid screen IDs',
                'invalid_ids' => $invalidIds,
            ], 400);
        }

        $session->reorderScreens($validated['screen_order']);

        return response()->json([
            'ok' => true,
            'screen_order' => $session->screen_order,
        ]);
    }
}
