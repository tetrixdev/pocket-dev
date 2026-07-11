<?php

namespace App\Http\Controllers;

use App\Models\Credential;
use App\Models\SystemPackage;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EnvironmentController extends Controller
{
    /**
     * Display the environment management page with credentials and system packages.
     */
    public function index(Request $request)
    {
        $request->session()->put('config_last_section', 'environment');

        $credentials = Credential::with('workspace')
            ->orderByRaw('workspace_id IS NOT NULL, workspace_id')
            ->orderBy('env_var')
            ->get();

        // Packages are now global only - no workspace relationship
        $packages = SystemPackage::orderBy('name')->get();

        $workspaces = Workspace::orderBy('name')->get();

        return view('config.environment', [
            'credentials' => $credentials,
            'packages' => $packages,
            'workspaces' => $workspaces,
        ]);
    }

    /**
     * Store a new credential.
     */
    public function storeCredential(Request $request)
    {
        try {
            $validated = $request->validate([
                'env_var' => [
                    'required',
                    'string',
                    'max:255',
                    'regex:/^[A-Z][A-Z0-9_]*$/',
                ],
                'value' => 'required|string',
                'description' => 'nullable|string|max:1000',
                'workspace_id' => 'nullable|uuid|exists:workspaces,id',
            ], [
                'env_var.regex' => 'Must start with an uppercase letter, then uppercase letters, numbers, or underscores (e.g., GITHUB_TOKEN).',
            ]);

            $workspaceId = $validated['workspace_id'] ?? null;

            // Auto-generate slug from env_var (lowercase)
            $slug = strtolower($validated['env_var']);

            // Check for duplicate env_var within the same scope (global or workspace)
            $existingEnvVar = Credential::where('env_var', $validated['env_var'])
                ->where('workspace_id', $workspaceId)
                ->exists();

            if ($existingEnvVar) {
                $scope = $workspaceId ? 'this workspace' : 'global scope';
                return redirect()->back()
                    ->withErrors(['env_var' => "A credential with this environment variable already exists in {$scope}."])
                    ->withInput($request->except('value'));
            }

            $credential = new Credential();
            $credential->slug = $slug;
            $credential->env_var = $validated['env_var'];
            $credential->value = $validated['value'];
            $credential->description = $validated['description'] ?? null;
            $credential->workspace_id = $workspaceId;
            $credential->save();

            return redirect()->route('config.environment')
                ->with('success', "Credential '{$validated['env_var']}' created successfully.");
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput($request->except('value'));
        } catch (\Exception $e) {
            Log::error('Failed to create credential', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->with('error', 'Failed to create credential. Please try again.')
                ->withInput($request->except('value'));
        }
    }

    /**
     * Update an existing credential.
     */
    public function updateCredential(Request $request, Credential $credential)
    {
        try {
            $validated = $request->validate([
                'env_var' => [
                    'required',
                    'string',
                    'max:255',
                    'regex:/^[A-Z][A-Z0-9_]*$/',
                ],
                'value' => 'nullable|string',
                'description' => 'nullable|string|max:1000',
                'workspace_id' => 'nullable|uuid|exists:workspaces,id',
            ], [
                'env_var.regex' => 'Must start with an uppercase letter, then uppercase letters, numbers, or underscores (e.g., GITHUB_TOKEN).',
            ]);

            $workspaceId = $validated['workspace_id'] ?? null;

            // Auto-generate slug from env_var (lowercase)
            $slug = strtolower($validated['env_var']);

            // Check for duplicate env_var within the same scope (excluding current credential)
            $existingEnvVar = Credential::where('env_var', $validated['env_var'])
                ->where('workspace_id', $workspaceId)
                ->where('id', '!=', $credential->id)
                ->exists();

            if ($existingEnvVar) {
                $scope = $workspaceId ? 'this workspace' : 'global scope';
                return redirect()->back()
                    ->withErrors(['env_var' => "A credential with this environment variable already exists in {$scope}."])
                    ->withInput($request->except('value'));
            }

            $credential->slug = $slug;
            $credential->env_var = $validated['env_var'];
            $credential->description = $validated['description'] ?? null;
            $credential->workspace_id = $workspaceId;

            // Only update value if provided
            if (!empty($validated['value'])) {
                $credential->value = $validated['value'];
            }

            $credential->save();

            return redirect()->route('config.environment')
                ->with('success', "Credential '{$validated['env_var']}' updated successfully.");
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput($request->except('value'));
        } catch (\Exception $e) {
            Log::error('Failed to update credential', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->with('error', 'Failed to update credential. Please try again.')
                ->withInput($request->except('value'));
        }
    }

    /**
     * Delete a credential.
     */
    public function destroyCredential(Credential $credential)
    {
        try {
            $envVar = $credential->env_var;
            $credential->delete();

            return redirect()->route('config.environment')
                ->with('success', "Credential '{$envVar}' deleted successfully.");
        } catch (\Exception $e) {
            Log::error('Failed to delete credential', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->with('error', 'Failed to delete credential. Please try again.');
        }
    }

    /**
     * Remove a system package.
     */
    public function destroyPackage(string $id)
    {
        try {
            $package = SystemPackage::findOrFail($id);
            $name = $package->name;
            $package->delete();

            return redirect()->route('config.environment')
                ->with('success', "Package '{$name}' removed from the list. Note: It is still installed in the current container but will not be installed in new containers.");
        } catch (\Exception $e) {
            Log::error('Failed to remove system package', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->with('error', 'Failed to remove system package. Please try again.');
        }
    }
}
