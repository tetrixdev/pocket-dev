<?php

namespace App\Http\Controllers\Api;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\MemoryDatabase;
use App\Models\PocketTool;
use App\Models\Workspace;
use App\Services\NativeToolService;
use App\Services\SystemPromptBuilder;
use App\Services\ToolSelector;
use App\Tools\Tool;
use App\Tools\UserTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function __construct(
        private ToolSelector $toolSelector,
        private SystemPromptBuilder $systemPromptBuilder
    ) {}

    /**
     * List all agents, optionally filtered by provider and workspace.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Agent::query();

        // Filter by enabled status (default: only enabled)
        if ($request->boolean('all', false)) {
            // Show all agents
        } else {
            $query->enabled();
        }

        // Filter by workspace
        if ($request->has('workspace_id')) {
            $query->forWorkspace($request->input('workspace_id'));
        }

        // Filter by provider
        if ($request->has('provider')) {
            $query->forProvider($request->input('provider'));
        }

        $agents = $query->orderBy('provider')
                       ->orderBy('is_default', 'desc')
                       ->orderBy('name')
                       ->get();

        return response()->json([
            'data' => $agents,
        ]);
    }

    /**
     * Get a single agent by ID.
     */
    public function show(Agent $agent): JsonResponse
    {
        return response()->json([
            'data' => $agent,
        ]);
    }

    /**
     * Get agents for a specific provider.
     */
    public function forProvider(string $provider): JsonResponse
    {
        $agents = Agent::enabled()
            ->forProvider($provider)
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $agents,
        ]);
    }

    /**
     * Get the default agent for a provider.
     */
    public function defaultForProvider(string $provider): JsonResponse
    {
        $agent = Agent::enabled()
            ->defaultFor($provider)
            ->first();

        if (!$agent) {
            // Fall back to first enabled agent for provider
            $agent = Agent::enabled()
                ->forProvider($provider)
                ->first();
        }

        if (!$agent) {
            return response()->json([
                'error' => 'No agent available for this provider',
            ], 404);
        }

        return response()->json([
            'data' => $agent,
        ]);
    }

    /**
     * Get all tools (for workspace tool selection).
     * Includes native tools from CLI providers and PocketDev/user tools.
     */
    public function allTools(): JsonResponse
    {
        $nativeToolService = app(NativeToolService::class);

        // Get native tools from both CLI providers
        $claudeCodeNative = collect($nativeToolService->getToolsForProvider('claude_code'))
            ->map(fn($tool) => [
                'slug' => 'native:claude_code:' . $tool['name'],
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'category' => 'native',
                'provider' => 'claude_code',
            ]);

        $codexNative = collect($nativeToolService->getToolsForProvider('codex'))
            ->map(fn($tool) => [
                'slug' => 'native:codex:' . $tool['name'],
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'category' => 'native',
                'provider' => 'codex',
            ]);

        // Get PocketDev tools (built-in)
        $pocketdevTools = $this->toolSelector->getAllTools()
            ->filter(fn(Tool $tool) => !($tool instanceof UserTool))
            ->map(fn(Tool $tool) => [
                'slug' => $tool->getSlug(),
                'name' => $tool->name,
                'description' => $tool->description,
                'category' => $tool->category,
                'source' => 'pocketdev',
            ])
            ->values();

        // Get user-created tools
        $userTools = $this->toolSelector->getUserTools()
            ->map(fn(Tool $tool) => [
                'slug' => $tool->getSlug(),
                'name' => $tool->name,
                'description' => $tool->description,
                'category' => 'custom',
                'source' => 'user',
            ])
            ->values();

        return response()->json([
            'native' => [
                'claude_code' => $claudeCodeNative->values(),
                'codex' => $codexNative->values(),
            ],
            'pocketdev' => $pocketdevTools,
            'user' => $userTools,
        ]);
    }

    /**
     * Get available tools for a provider, grouped by source.
     */
    public function availableTools(string $provider): JsonResponse
    {
        $nativeTools = [];

        // Claude Code has native tools - get from NativeToolService with enabled status
        if ($provider === Agent::PROVIDER_CLAUDE_CODE) {
            $nativeToolService = app(NativeToolService::class);
            $nativeTools = $nativeToolService->getToolsForProvider('claude_code');
        }

        // PocketDev tools (built-in Tool classes) available for this provider
        $pocketdevTools = $this->toolSelector->getAvailableTools($provider)
            ->filter(fn(Tool $tool) => !($tool instanceof UserTool))
            ->map(fn(Tool $tool) => [
                'slug' => $tool->getSlug(),
                'name' => $tool->name,
                'description' => $tool->description,
                'category' => $tool->category,
            ])
            ->values();

        // User-created tools
        $userTools = $this->toolSelector->getUserTools()
            ->map(fn(Tool $tool) => [
                'slug' => $tool->getSlug(),
                'name' => $tool->name,
                'description' => $tool->description,
            ])
            ->values();

        return response()->json([
            'native' => $nativeTools,
            'pocketdev' => $pocketdevTools,
            'user' => $userTools,
        ]);
    }

    /**
     * Get all available providers with their availability status.
     */
    public function providers(): JsonResponse
    {
        $providers = [];

        foreach (Agent::getProviders() as $provider) {
            $hasAgents = Agent::enabled()->forProvider($provider)->exists();
            $defaultAgent = Agent::enabled()->defaultFor($provider)->first();

            $providers[$provider] = [
                'name' => match ($provider) {
                    Agent::PROVIDER_ANTHROPIC => 'Anthropic',
                    Agent::PROVIDER_OPENAI => 'OpenAI',
                    Agent::PROVIDER_CLAUDE_CODE => 'Claude Code',
                    default => ucfirst($provider),
                },
                'has_agents' => $hasAgents,
                'default_agent_id' => $defaultAgent?->id,
                'default_agent_name' => $defaultAgent?->name,
            ];
        }

        return response()->json($providers);
    }

    /**
     * Preview the assembled system prompt for an agent configuration.
     * Uses the same SystemPromptBuilder as actual conversations for consistency.
     */
    public function previewSystemPrompt(Request $request): JsonResponse
    {
        $provider = $request->input('provider', Provider::Anthropic->value);
        $agentSystemPrompt = $request->input('agent_system_prompt', '');
        $allowedTools = $request->input('allowed_tools'); // null = all tools
        $memorySchemas = $request->input('memory_schemas', []);
        $workspaceId = $request->input('workspace_id');
        $workspace = $workspaceId ? Workspace::find($workspaceId) : null;
        $inheritWorkspaceSchemas = $request->boolean('inherit_workspace_schemas', false);

        // If inheriting from workspace, use workspace's enabled schemas
        if ($inheritWorkspaceSchemas && $workspace) {
            $memorySchemas = $workspace->enabledMemoryDatabases()->pluck('memory_databases.id')->toArray();
        }

        // Use the same builder that generates actual conversation prompts
        $preview = $this->systemPromptBuilder->buildPreviewSections(
            $provider,
            $agentSystemPrompt,
            $allowedTools,
            $memorySchemas ?? [],
            $workspace
        );

        return response()->json($preview);
    }

    /**
     * Get system prompt preview for an existing agent.
     * Uses the agent's actual configuration to build the preview.
     */
    public function agentSystemPromptPreview(Agent $agent): JsonResponse
    {
        // Load the agent with its memory databases
        $agent->load('memoryDatabases', 'workspace');

        // Get the agent's allowed tools (null = all tools)
        $allowedTools = $agent->allowed_tools;

        // Get the agent's memory schema IDs using ToolSelector
        $memorySchemaIds = $this->toolSelector->getAvailableSchemas($agent)->pluck('id')->toArray();

        // Use the same builder that generates actual conversation prompts
        $preview = $this->systemPromptBuilder->buildPreviewSections(
            $agent->provider,
            $agent->system_prompt ?? '',
            $allowedTools,
            $memorySchemaIds,
            $agent->workspace
        );

        return response()->json($preview);
    }

    /**
     * Check which agents would be affected by disabling a memory schema in a workspace.
     *
     * Used by the workspace form to warn before disabling schemas that agents depend on.
     */
    public function checkSchemaAffectedAgents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => 'required|uuid|exists:workspaces,id',
            'schema_id' => 'required|uuid|exists:memory_databases,id',
        ]);

        // Find agents in this workspace that have this schema enabled
        $affectedAgents = Agent::where('workspace_id', $validated['workspace_id'])
            ->whereHas('memoryDatabases', function ($query) use ($validated) {
                $query->where('memory_databases.id', $validated['schema_id']);
            })
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'affected_count' => $affectedAgents->count(),
            'agents' => $affectedAgents->map(fn($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
            ]),
        ]);
    }

    /**
     * Get available memory schemas for an agent.
     *
     * For existing agents: returns schemas enabled in their workspace.
     * For new agents: returns all schemas (since workspace isn't set yet).
     */
    public function availableSchemas(Request $request, ?Agent $agent = null): JsonResponse
    {
        if ($agent && $agent->workspace) {
            // Get schemas enabled in the agent's workspace
            $schemas = $agent->workspace->enabledMemoryDatabases()
                ->get()
                ->map(fn($db) => [
                    'id' => $db->id,
                    'name' => $db->name,
                    'schema_name' => $db->schema_name,
                    'description' => $db->description,
                ]);
        } else {
            // For new agents without workspace, show all schemas
            $schemas = MemoryDatabase::orderBy('name')
                ->get()
                ->map(fn($db) => [
                    'id' => $db->id,
                    'name' => $db->name,
                    'schema_name' => $db->schema_name,
                    'description' => $db->description,
                ]);
        }

        return response()->json([
            'schemas' => $schemas,
        ]);
    }

    /**
     * Validate cloning an agent to a different workspace.
     *
     * Checks if tools and memory schemas from the source agent are available
     * in the target workspace. Returns lists of missing items.
     */
    public function validateClone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_agent_id' => 'required|uuid|exists:agents,id',
            'target_workspace_id' => 'required|uuid|exists:workspaces,id,deleted_at,NULL',
        ]);

        $sourceAgent = Agent::with(['memoryDatabases', 'workspace'])->findOrFail($validated['source_agent_id']);
        $targetWorkspace = Workspace::findOrFail($validated['target_workspace_id']);

        $missingTools = [];
        $missingSchemas = [];

        // Check tools only if agent has specific tools selected (not inheriting from workspace)
        if (!$sourceAgent->inheritsWorkspaceTools() && $sourceAgent->allowed_tools !== null && is_array($sourceAgent->allowed_tools)) {
            $disabledToolSlugs = $targetWorkspace->getDisabledToolSlugs();

            foreach ($sourceAgent->allowed_tools as $toolSlug) {
                if (in_array($toolSlug, $disabledToolSlugs)) {
                    $missingTools[] = $toolSlug;
                }
            }
        }

        // Check memory schemas only if agent has specific schemas selected (not inheriting)
        if (!$sourceAgent->inheritsWorkspaceSchemas()) {
            $enabledSchemaIds = $targetWorkspace->enabledMemoryDatabases()
                ->pluck('memory_databases.id')
                ->toArray();

            foreach ($sourceAgent->memoryDatabases as $schema) {
                if (!in_array($schema->id, $enabledSchemaIds)) {
                    $missingSchemas[] = [
                        'id' => $schema->id,
                        'name' => $schema->name,
                        'schema_name' => $schema->schema_name,
                    ];
                }
            }
        }

        return response()->json([
            'valid' => empty($missingTools) && empty($missingSchemas),
            'missing_tools' => $missingTools,
            'missing_schemas' => $missingSchemas,
            'source_agent' => [
                'id' => $sourceAgent->id,
                'name' => $sourceAgent->name,
                'workspace_name' => $sourceAgent->workspace?->name,
            ],
        ]);
    }
}
