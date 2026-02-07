<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    /**
     * List all workspaces
     */
    public function index(): JsonResponse
    {
        $workspaces = Workspace::query()
            ->withCount(['agents', 'conversations'])
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'directory' => $workspace->directory,
                'working_directory_path' => $workspace->getWorkingDirectoryPath(),
                'description' => $workspace->description,
                'agents_count' => $workspace->agents_count,
                'conversations_count' => $workspace->conversations_count,
                'created_at' => $workspace->created_at->toIso8601String(),
            ]);

        return response()->json($workspaces);
    }

    /**
     * Get a single workspace
     */
    public function show(Workspace $workspace): JsonResponse
    {
        $workspace->loadCount(['agents', 'conversations']);

        // Load memory databases
        $memoryDatabases = $workspace->memoryDatabases()->get()->map(fn ($db) => [
            'id' => $db->id,
            'name' => $db->name,
            'schema_name' => $db->schema_name,
            'enabled' => (bool) $db->pivot->enabled,
            'is_default' => (bool) $db->pivot->is_default,
        ]);

        // Load enabled tool slugs (inverse - we store disabled)
        $disabledToolSlugs = $workspace->getDisabledToolSlugs();

        return response()->json([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'directory' => $workspace->directory,
            'description' => $workspace->description,
            'working_directory_path' => $workspace->getWorkingDirectoryPath(),
            'agents_count' => $workspace->agents_count,
            'conversations_count' => $workspace->conversations_count,
            'memory_databases' => $memoryDatabases,
            'disabled_tool_slugs' => $disabledToolSlugs,
            'created_at' => $workspace->created_at->toIso8601String(),
            'updated_at' => $workspace->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Get agents for a workspace
     */
    public function agents(Workspace $workspace): JsonResponse
    {
        $agents = $workspace->agents()
            ->where('enabled', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn ($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'provider' => $agent->provider,
                'model' => $agent->model,
                'is_default' => $agent->is_default,
                'description' => $agent->description,
            ]);

        return response()->json($agents);
    }

    /**
     * Get memory databases for a workspace
     */
    public function memoryDatabases(Workspace $workspace): JsonResponse
    {
        $memoryDatabases = $workspace->enabledMemoryDatabases()
            ->get()
            ->map(fn ($db) => [
                'id' => $db->id,
                'name' => $db->name,
                'schema_name' => $db->schema_name,
                'description' => $db->description,
                'is_default' => (bool) $db->pivot->is_default,
            ]);

        return response()->json($memoryDatabases);
    }

    /**
     * Set the active workspace in the session
     */
    public function setActive(Request $request, Workspace $workspace): JsonResponse
    {
        $request->session()->put('active_workspace_id', $workspace->id);

        // Get the last session for this workspace (if any)
        $lastSessionId = $request->session()->get("last_session_{$workspace->id}");

        return response()->json([
            'success' => true,
            'message' => "Switched to workspace: {$workspace->name}",
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'directory' => $workspace->directory,
                'working_directory_path' => $workspace->getWorkingDirectoryPath(),
                'default_session_template' => $workspace->default_session_template,
            ],
            'last_session_id' => $lastSessionId,
        ]);
    }

    /**
     * Get the currently active workspace
     */
    public function getActive(Request $request): JsonResponse
    {
        $workspaceId = $request->session()->get('active_workspace_id');

        if (!$workspaceId) {
            // Return first workspace as default
            $workspace = Workspace::first();
        } else {
            $workspace = Workspace::find($workspaceId);
            if (!$workspace) {
                // Fallback to first workspace if stored one doesn't exist
                $workspace = Workspace::first();
            }
        }

        if (!$workspace) {
            return response()->json(['workspace' => null]);
        }

        // Get the last session for this workspace (if any)
        $lastSessionId = $request->session()->get("last_session_{$workspace->id}");

        return response()->json([
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'directory' => $workspace->directory,
                'working_directory_path' => $workspace->getWorkingDirectoryPath(),
                'default_session_template' => $workspace->default_session_template,
            ],
            'last_session_id' => $lastSessionId,
        ]);
    }
}
