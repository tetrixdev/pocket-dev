<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelState;
use App\Models\Screen;
use App\Models\Session;
use App\Models\Workspace;
use App\Services\ConversationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function __construct(
        private ConversationFactory $conversationFactory,
    ) {}
    /**
     * List sessions for a workspace.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => 'required|uuid|exists:workspaces,id',
            'include_archived' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = Session::forWorkspace($validated['workspace_id'])
            ->with(['screens' => function ($q) {
                $q->with(['conversation:id,title,status,last_activity_at', 'panel:id,slug,name']);
            }])
            ->orderByDesc('updated_at');

        if (!$request->boolean('include_archived')) {
            $query->active();
        }

        $sessions = $query->paginate($validated['per_page'] ?? 20);

        return response()->json($sessions);
    }

    /**
     * Create a new session.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => 'required|uuid|exists:workspaces,id',
            'name' => 'nullable|string|max:255',
            'create_initial_chat' => 'nullable|boolean',
        ]);

        $workspace = Workspace::find($validated['workspace_id']);

        return DB::transaction(function () use ($validated, $workspace, $request) {
            // Check for default session template
            $template = $workspace->default_session_template ?? null;

            $session = Session::create([
                'workspace_id' => $validated['workspace_id'],
                'name' => $validated['name'] ?? 'New Session',
                'screen_order' => [],
            ]);

            // Set workspace relation for factory to access
            $session->setRelation('workspace', $workspace);

            $firstScreen = null;

            if ($template && !empty($template['screen_order'])) {
                // Create screens from template using factory methods
                // Factory methods handle screen_order management automatically
                foreach ($template['screen_order'] as $item) {
                    if ($item['type'] === 'chat') {
                        $conversation = $this->conversationFactory->createForScreen(
                            $session,
                            null, // Factory will use workspace default agent
                            $item['title'] ?? 'New Chat'
                        );

                        // Set tab_label from template if provided
                        if (!empty($item['tab_label'])) {
                            $conversation->update(['tab_label' => $item['tab_label']]);
                        }

                        $screen = Screen::createChatScreen($session, $conversation);
                    } else {
                        // Panel
                        $panelState = PanelState::create([
                            'panel_slug' => $item['slug'],
                            'parameters' => $item['params'] ?? [],
                            'state' => [],
                        ]);

                        $screen = Screen::createPanelScreen(
                            $session,
                            $item['slug'],
                            $panelState,
                            $item['params'] ?? []
                        );
                    }

                    $firstScreen = $firstScreen ?? $screen;
                }
            } elseif ($request->boolean('create_initial_chat', true)) {
                // No template: create a single initial chat using factory
                $conversation = $this->conversationFactory->createForScreen(
                    $session,
                    null, // Factory will find workspace default agent
                    'New Chat'
                );

                $firstScreen = Screen::createChatScreen($session, $conversation);
            }

            // Activate the first screen (sets is_active flag and last_active_screen_id)
            $firstScreen?->activate();

            $session->load(['screens.conversation', 'screens.panelState']);

            return response()->json($session, 201);
        });
    }

    /**
     * Get a session with its screens.
     */
    public function show(Session $session): JsonResponse
    {
        $session->load([
            'screens' => function ($q) {
                $q->with(['conversation', 'panelState', 'panel:id,slug,name']);
            },
        ]);

        return response()->json($session);
    }

    /**
     * Update session (name, screen order).
     */
    public function update(Request $request, Session $session): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'screen_order' => 'nullable|array',
            'screen_order.*' => 'uuid|exists:screens,id',
            'last_active_screen_id' => 'nullable|uuid|exists:screens,id',
        ]);

        $updates = [];

        if (isset($validated['name'])) {
            $updates['name'] = $validated['name'];
        }

        if (isset($validated['screen_order'])) {
            $updates['screen_order'] = $validated['screen_order'];
        }

        if (isset($validated['last_active_screen_id'])) {
            $updates['last_active_screen_id'] = $validated['last_active_screen_id'];
        }

        if (!empty($updates)) {
            // Session timestamp: preserve (metadata/navigation changes)
            $session->updatePreservingTimestamp($updates);
        }

        return response()->json($session);
    }

    /**
     * Archive a session.
     */
    public function archive(Session $session): JsonResponse
    {
        $session->archive();

        return response()->json([
            'ok' => true,
            'is_archived' => $session->is_archived,
        ]);
    }

    /**
     * Restore a session from archive.
     */
    public function restore(Session $session): JsonResponse
    {
        $session->restore();

        return response()->json([
            'ok' => true,
            'is_archived' => $session->is_archived,
        ]);
    }

    /**
     * Mark a session as the last active session for its workspace.
     *
     * This persists the session selection to the database so it survives
     * PHP session expiry. Called when a user loads or creates a session.
     */
    public function setActive(Session $session): JsonResponse
    {
        // Guard against soft-deleted workspace (would NPE on ->update())
        if (!$session->workspace) {
            return response()->json(['ok' => false, 'error' => 'Workspace not found'], 404);
        }

        $session->workspace->update([
            'last_active_session_id' => $session->id,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Delete a session and all its screens.
     */
    public function destroy(Session $session): JsonResponse
    {
        // Screens will be cascade deleted via foreign key
        // Panel states will have panel_id set to null via nullOnDelete
        $session->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * Save current session layout as workspace default template.
     */
    public function saveAsDefault(Session $session): JsonResponse
    {
        $template = ['screen_order' => []];

        $screens = $session->orderedScreens()->get();

        foreach ($screens as $screen) {
            if ($screen->isChat()) {
                // Only include non-archived conversations
                if ($screen->conversation && !$screen->conversation->isArchived()) {
                    $item = [
                        'type' => 'chat',
                        'title' => $screen->conversation->title ?? 'Chat',
                    ];
                    // Include tab_label if set
                    if ($screen->conversation->tab_label) {
                        $item['tab_label'] = $screen->conversation->tab_label;
                    }
                    $template['screen_order'][] = $item;
                }
            } else {
                $template['screen_order'][] = [
                    'type' => 'panel',
                    'slug' => $screen->panel_slug,
                    'params' => $screen->panelState?->parameters ?? [],
                ];
            }
        }

        $session->workspace->update([
            'default_session_template' => $template,
        ]);

        return response()->json([
            'ok' => true,
            'template' => $template,
        ]);
    }

    /**
     * Clear the workspace default session template.
     */
    public function clearDefault(Session $session): JsonResponse
    {
        $session->workspace->update([
            'default_session_template' => null,
        ]);

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * Get archived conversations for a session.
     */
    public function archivedConversations(Session $session): JsonResponse
    {
        $archivedConversations = $session->screens()
            ->chats()
            ->whereHas('conversation', function ($q) {
                $q->where('status', 'archived');
            })
            ->with('conversation:id,uuid,title,status,updated_at')
            ->get()
            ->map(fn ($screen) => [
                'id' => $screen->conversation->uuid, // Use uuid for API calls (route model binding)
                'title' => $screen->conversation->title,
                'archived_at' => $screen->conversation->updated_at,
            ]);

        return response()->json([
            'conversations' => $archivedConversations,
            'count' => $archivedConversations->count(),
        ]);
    }

    /**
     * Get the latest activity timestamp for sessions in a workspace.
     */
    public function latestActivity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => 'required|uuid|exists:workspaces,id',
        ]);

        $latest = Session::forWorkspace($validated['workspace_id'])
            ->max('updated_at');

        return response()->json([
            'latest_activity_at' => $latest,
        ]);
    }
}
