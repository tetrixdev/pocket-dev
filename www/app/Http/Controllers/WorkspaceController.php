<?php

namespace App\Http\Controllers;

use App\Models\MemoryDatabase;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    /**
     * List all workspaces
     */
    public function index(Request $request)
    {
        $request->session()->put('config_last_section', 'workspaces');

        try {
            $workspaces = Workspace::query()
                ->withCount(['agents', 'conversations'])
                ->orderBy('name')
                ->get();

            return view('config.workspaces.index', [
                'workspaces' => $workspaces,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list workspaces', ['error' => $e->getMessage()]);
            return redirect()->route('config.index')
                ->with('error', 'Failed to list workspaces: ' . $e->getMessage());
        }
    }

    /**
     * Show create workspace form
     */
    public function create(Request $request)
    {
        $request->session()->put('config_last_section', 'workspaces');

        $memoryDatabases = MemoryDatabase::orderBy('name')->get();

        return view('config.workspaces.form', [
            'memoryDatabases' => $memoryDatabases,
            'disabledToolSlugs' => [], // New workspace has all tools enabled
        ]);
    }

    /**
     * Store new workspace
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1024',
                'directory' => 'nullable|string|regex:/^[a-z0-9-]+$/|max:64',
                'memory_databases' => 'nullable|array',
                'memory_databases.*' => 'uuid|exists:memory_databases,id',
                'default_memory_database' => 'nullable|uuid|exists:memory_databases,id',
                'disabled_tools' => 'nullable|array',
                'disabled_tools.*' => 'string|max:100',
            ]);

            // Check for duplicate directory
            $directory = $validated['directory'] ?: Str::slug($validated['name']);
            if (Workspace::where('directory', $directory)->exists()) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'A workspace with this directory already exists');
            }

            DB::beginTransaction();

            $workspace = Workspace::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'directory' => $directory,
            ]);

            // Attach memory databases
            if (!empty($validated['memory_databases'])) {
                $defaultId = $validated['default_memory_database'] ?? null;

                foreach ($validated['memory_databases'] as $dbId) {
                    $workspace->memoryDatabases()->attach($dbId, [
                        'enabled' => true,
                        'is_default' => $dbId === $defaultId,
                    ]);
                }
            }

            // Save disabled tools
            $disabledTools = $validated['disabled_tools'] ?? [];
            foreach ($disabledTools as $toolSlug) {
                $workspace->workspaceTools()->create([
                    'tool_slug' => $toolSlug,
                    'enabled' => false,
                ]);
            }

            DB::commit();

            return redirect()->route('config.workspaces')
                ->with('success', 'Workspace created successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create workspace', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to create workspace: ' . $e->getMessage());
        }
    }

    /**
     * Show edit workspace form
     */
    public function edit(Request $request, Workspace $workspace)
    {
        $request->session()->put('config_last_section', 'workspaces');

        $memoryDatabases = MemoryDatabase::orderBy('name')->get();
        $enabledDbIds = $workspace->memoryDatabases()->pluck('memory_databases.id')->toArray();
        $defaultDb = $workspace->defaultMemoryDatabase();
        $disabledToolSlugs = $workspace->getDisabledToolSlugs();

        return view('config.workspaces.form', [
            'workspace' => $workspace,
            'memoryDatabases' => $memoryDatabases,
            'enabledDbIds' => $enabledDbIds,
            'defaultDbId' => $defaultDb?->id,
            'disabledToolSlugs' => $disabledToolSlugs,
        ]);
    }

    /**
     * Update workspace
     */
    public function update(Request $request, Workspace $workspace)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1024',
                'directory' => 'nullable|string|regex:/^[a-z0-9-]+$/|max:64',
                'memory_databases' => 'nullable|array',
                'memory_databases.*' => 'uuid|exists:memory_databases,id',
                'default_memory_database' => 'nullable|uuid|exists:memory_databases,id',
                'disabled_tools' => 'nullable|array',
                'disabled_tools.*' => 'string|max:100',
            ]);

            // Check for duplicate directory (excluding current workspace)
            $directory = $validated['directory'] ?: Str::slug($validated['name']);
            if (Workspace::where('directory', $directory)->where('id', '!=', $workspace->id)->exists()) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'A workspace with this directory already exists');
            }

            DB::beginTransaction();

            // Handle directory rename
            if ($directory !== $workspace->directory) {
                if (!$workspace->changeDirectory($directory)) {
                    throw new \RuntimeException('Failed to rename workspace directory');
                }
            }

            $workspace->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            // Sync memory databases
            $memoryDbs = $validated['memory_databases'] ?? [];
            $defaultId = $validated['default_memory_database'] ?? null;

            // Detach all and re-attach with proper flags
            $workspace->memoryDatabases()->detach();

            foreach ($memoryDbs as $dbId) {
                $workspace->memoryDatabases()->attach($dbId, [
                    'enabled' => true,
                    'is_default' => $dbId === $defaultId,
                ]);
            }

            // Sync disabled tools
            // Delete all existing entries and recreate disabled ones
            $workspace->workspaceTools()->delete();
            $disabledTools = $validated['disabled_tools'] ?? [];
            foreach ($disabledTools as $toolSlug) {
                $workspace->workspaceTools()->create([
                    'tool_slug' => $toolSlug,
                    'enabled' => false,
                ]);
            }

            DB::commit();

            return redirect()->route('config.workspaces')
                ->with('success', 'Workspace saved successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to save workspace {$workspace->id}", ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to save workspace: ' . $e->getMessage());
        }
    }

    /**
     * Delete workspace
     */
    public function destroy(Workspace $workspace)
    {
        try {
            // Check if workspace has conversations
            if ($workspace->conversations()->count() > 0) {
                return redirect()->route('config.workspaces')
                    ->with('error', 'Cannot delete workspace with existing conversations. Archive or delete conversations first.');
            }

            $workspace->delete();

            return redirect()->route('config.workspaces')
                ->with('success', 'Workspace deleted successfully');
        } catch (\Exception $e) {
            Log::error("Failed to delete workspace {$workspace->id}", ['error' => $e->getMessage()]);
            return redirect()->route('config.workspaces')
                ->with('error', 'Failed to delete workspace: ' . $e->getMessage());
        }
    }

    /**
     * Show workspace tools management
     */
    public function tools(Request $request, Workspace $workspace)
    {
        $request->session()->put('config_last_section', 'workspaces');

        // Get all available tools
        $toolSelector = app(\App\Services\ToolSelector::class);
        $allTools = $toolSelector->getAllTools();

        // Get disabled tool slugs for this workspace
        $disabledSlugs = $workspace->getDisabledToolSlugs();

        return view('config.workspaces.tools', [
            'workspace' => $workspace,
            'tools' => $allTools,
            'disabledSlugs' => $disabledSlugs,
        ]);
    }

    /**
     * Toggle tool enabled/disabled for workspace (AJAX)
     */
    public function toggleTool(Request $request, Workspace $workspace)
    {
        try {
            $validated = $request->validate([
                'tool_slug' => 'required|string|max:64',
                'enabled' => 'required|boolean',
            ]);

            // Upsert the workspace tool setting
            $workspace->workspaceTools()->updateOrCreate(
                ['tool_slug' => $validated['tool_slug']],
                ['enabled' => $validated['enabled']]
            );

            return response()->json([
                'success' => true,
                'message' => $validated['enabled'] ? 'Tool enabled' : 'Tool disabled',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle workspace tool', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle tool: ' . $e->getMessage(),
            ], 500);
        }
    }
}
