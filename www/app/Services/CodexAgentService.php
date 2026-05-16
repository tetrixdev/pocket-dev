<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Facades\Log;

class CodexAgentService
{
    /**
     * Ensure a default Codex agent exists for the given workspace.
     */
    public function ensureDefaultAgentExists(string $workspaceId): ?Agent
    {
        $existing = Agent::where('workspace_id', $workspaceId)
            ->where('provider', 'codex')
            ->where('is_default', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $anyCodex = Agent::where('workspace_id', $workspaceId)
            ->where('provider', 'codex')
            ->first();

        if ($anyCodex) {
            return $anyCodex;
        }

        try {
            return Agent::create([
                'workspace_id' => $workspaceId,
                'name' => 'Codex',
                'slug' => 'codex-default',
                'description' => 'Default Codex agent',
                'provider' => 'codex',
                'model' => config('ai.providers.codex.default_model', 'gpt-5.3-codex'),
                'reasoning_config' => ['effort' => 'medium'],
                'response_level' => 1,
                'enabled' => true,
                'is_default' => true,
                'inherit_workspace_tools' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('[Codex Auth] Failed to create default agent', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

