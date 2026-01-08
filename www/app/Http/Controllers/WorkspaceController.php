<?php

namespace App\Http\Controllers;

use App\Models\Credential;
use App\Models\MemoryDatabase;
use App\Models\SystemPackage;
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

            $trashedWorkspaces = Workspace::onlyTrashed()
                ->withCount(['agents', 'conversations'])
                ->orderBy('deleted_at', 'desc')
                ->get();

            return view('config.workspaces.index', [
                'workspaces' => $workspaces,
                'trashedWorkspaces' => $trashedWorkspaces,
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
        $allPackages = SystemPackage::orderBy('name')->pluck('name')->toArray();

        return view('config.workspaces.form', [
            'memoryDatabases' => $memoryDatabases,
            'disabledToolSlugs' => [], // New workspace has all tools enabled
            'allPackages' => $allPackages,
            'selectedPackages' => [], // New workspace has no packages selected (shows all by default)
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
                'selected_packages' => 'nullable|array',
                'selected_packages.*' => 'string|max:255',
            ]);

            // Check for duplicate directory
            $directory = $validated['directory'] ?: Str::slug($validated['name']);
            if (Workspace::where('directory', $directory)->exists()) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'A workspace with this directory already exists');
            }

            DB::beginTransaction();

            // Filter selected_packages to only include existing packages
            $selectedPackages = $validated['selected_packages'] ?? [];
            if (!empty($selectedPackages)) {
                $existingPackages = SystemPackage::pluck('name')->toArray();
                $selectedPackages = array_values(array_intersect($selectedPackages, $existingPackages));
            }

            $workspace = Workspace::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'directory' => $directory,
                'selected_packages' => !empty($selectedPackages) ? $selectedPackages : null,
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

            // Redirect to create first agent for this workspace
            return redirect()->route('config.agents.create', ['workspace_id' => $workspace->id])
                ->with('success', 'Workspace created! Now create your first agent.');
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

        // Get all packages and workspace's selected packages
        $allPackages = SystemPackage::orderBy('name')->pluck('name')->toArray();
        $selectedPackages = $workspace->selected_packages ?? [];

        // Get credentials for this workspace (global + workspace-specific)
        $workspaceCredentials = Credential::getForWorkspace($workspace->id);

        return view('config.workspaces.form', [
            'workspace' => $workspace,
            'memoryDatabases' => $memoryDatabases,
            'enabledDbIds' => $enabledDbIds,
            'defaultDbId' => $defaultDb?->id,
            'disabledToolSlugs' => $disabledToolSlugs,
            'allPackages' => $allPackages,
            'selectedPackages' => $selectedPackages,
            'workspaceCredentials' => $workspaceCredentials,
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
                'selected_packages' => 'nullable|array',
                'selected_packages.*' => 'string|max:255',
            ]);

            // Check for duplicate directory (excluding current workspace)
            $directory = $validated['directory'] ?: Str::slug($validated['name']);
            if (Workspace::where('directory', $directory)->where('id', '!=', $workspace->id)->exists()) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'A workspace with this directory already exists');
            }

            // Track if directory change is needed (filesystem operation must happen AFTER commit)
            $oldDirectory = $workspace->directory;
            $needsDirectoryRename = $directory !== $oldDirectory;
            $oldPath = $workspace->getWorkingDirectoryPath();
            $newPath = '/workspace/' . $directory;

            DB::beginTransaction();

            // Filter selected_packages to only include existing packages
            $selectedPackages = $validated['selected_packages'] ?? [];
            if (!empty($selectedPackages)) {
                $existingPackages = SystemPackage::pluck('name')->toArray();
                $selectedPackages = array_values(array_intersect($selectedPackages, $existingPackages));
            }

            // Update workspace fields (including directory if changed)
            // Note: Filesystem rename happens AFTER commit to avoid sync issues
            $workspace->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'directory' => $directory,
                'selected_packages' => !empty($selectedPackages) ? $selectedPackages : null,
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

            // Perform filesystem rename AFTER transaction commits
            // This ensures database is consistent even if filesystem op fails
            if ($needsDirectoryRename && is_dir($oldPath) && $oldPath !== $newPath) {
                if (!@rename($oldPath, $newPath)) {
                    // Filesystem rename failed - revert the database change
                    try {
                        $workspace->update(['directory' => $oldDirectory]);
                    } catch (\Exception $revertError) {
                        Log::error("Failed to revert directory change after filesystem error", [
                            'workspace_id' => $workspace->id,
                            'error' => $revertError->getMessage(),
                        ]);
                    }
                    throw new \RuntimeException('Failed to rename workspace directory on filesystem');
                }
            }

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
     * Delete workspace (soft delete)
     */
    public function destroy(Workspace $workspace)
    {
        try {
            // Soft delete - keeps all relations intact (tools, agents, conversations, memory links)
            $workspace->delete();

            return redirect()->route('config.workspaces')
                ->with('success', 'Workspace moved to trash. You can restore it from the trash section below.');
        } catch (\Exception $e) {
            Log::error("Failed to delete workspace {$workspace->id}", ['error' => $e->getMessage()]);
            return redirect()->route('config.workspaces')
                ->with('error', 'Failed to delete workspace: ' . $e->getMessage());
        }
    }

    /**
     * Restore a soft-deleted workspace
     */
    public function restore(string $id)
    {
        try {
            $workspace = Workspace::withTrashed()->findOrFail($id);
            $workspace->restore();

            return redirect()->route('config.workspaces')
                ->with('success', 'Workspace restored successfully');
        } catch (\Exception $e) {
            Log::error("Failed to restore workspace {$id}", ['error' => $e->getMessage()]);
            return redirect()->route('config.workspaces')
                ->with('error', 'Failed to restore workspace: ' . $e->getMessage());
        }
    }
}
