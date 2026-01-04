<?php

namespace App\Http\Controllers\Api;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\MemoryDatabase;
use App\Models\PocketTool;
use App\Services\NativeToolService;
use App\Services\SystemPromptService;
use App\Services\ToolSelector;
use App\Tools\Tool;
use App\Tools\UserTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function __construct(
        private ToolSelector $toolSelector
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
     */
    public function allTools(): JsonResponse
    {
        $tools = $this->toolSelector->getAllTools()
            ->map(fn(Tool $tool) => [
                'slug' => $tool->getSlug(),
                'name' => $tool->name,
                'description' => $tool->description,
                'category' => $tool->category,
            ])
            ->values();

        return response()->json([
            'tools' => $tools,
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
     */
    public function previewSystemPrompt(Request $request): JsonResponse
    {
        $provider = $request->input('provider', Agent::PROVIDER_ANTHROPIC);
        $agentSystemPrompt = $request->input('agent_system_prompt', '');
        $allowedTools = $request->input('allowed_tools'); // null = all tools

        $systemPromptService = app(SystemPromptService::class);

        $sections = [];

        // 1. Core system prompt
        $corePrompt = $systemPromptService->getCore();
        $sections[] = [
            'title' => 'Core System Prompt',
            'content' => $corePrompt,
            'source' => 'resources/defaults/system-prompt.md',
            'collapsible' => true,
        ];

        // 2. Additional system prompt (if any)
        $additionalPrompt = $systemPromptService->getAdditional();
        if (!empty($additionalPrompt)) {
            $sections[] = [
                'title' => 'Additional System Prompt',
                'content' => $additionalPrompt,
                'source' => 'storage/pocketdev/additional-system-prompt.md',
                'collapsible' => true,
            ];
        }

        // 3. Agent-specific system prompt
        if (!empty($agentSystemPrompt)) {
            $sections[] = [
                'title' => 'Agent System Prompt',
                'content' => $agentSystemPrompt,
                'source' => 'Agent configuration',
                'collapsible' => false,
            ];
        }

        // 4. Tool instructions (for PocketDev tools)
        // Pass allowedTools to filter (null = all tools, array = specific tools)
        // Create a temporary agent model to preview memory schemas
        $memorySchemas = $request->input('memory_schemas', []);
        $previewAgent = null;
        if (!empty($memorySchemas)) {
            $previewAgent = new Agent(['name' => 'preview']);
            // Attach memory databases without saving (for preview purposes)
            $previewAgent->setRelation('memoryDatabases', MemoryDatabase::whereIn('id', $memorySchemas)->get());
        }
        $toolPrompt = $this->toolSelector->buildSystemPrompt($provider, $allowedTools, null, $previewAgent);
        if (!empty($toolPrompt)) {
            $sections[] = [
                'title' => 'PocketDev Tools',
                'content' => $toolPrompt,
                'source' => $allowedTools === null ? 'Dynamic (all tools enabled)' : 'Dynamic (based on selected tools)',
                'collapsible' => true,
            ];
        }

        // 5. Working directory context (example)
        $sections[] = [
            'title' => 'Working Directory Context',
            'content' => "# Working Directory\n\nCurrent project: /var/www\n\nAll file operations should be relative to or within this directory.",
            'source' => 'Dynamic (set per conversation)',
            'collapsible' => true,
        ];

        // Combine all sections for full preview
        $fullPrompt = collect($sections)
            ->pluck('content')
            ->implode("\n\n");

        return response()->json([
            'sections' => $sections,
            'full_prompt' => $fullPrompt,
            'total_length' => strlen($fullPrompt),
            'estimated_tokens' => (int) ceil(strlen($fullPrompt) / 4), // Rough estimate
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
}
