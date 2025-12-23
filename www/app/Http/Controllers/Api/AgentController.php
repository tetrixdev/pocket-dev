<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\PocketTool;
use App\Services\NativeToolService;
use App\Services\SystemPromptService;
use App\Services\ToolSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    /**
     * List all agents, optionally filtered by provider.
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

        // PocketDev tools available for this provider
        $pocketdevTools = PocketTool::enabled()
            ->forProvider($provider)
            ->pocketdev()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn($tool) => [
                'slug' => $tool->slug,
                'name' => $tool->name,
                'description' => $tool->description,
                'category' => $tool->category,
            ]);

        // User-created tools (available for all providers)
        $userTools = PocketTool::enabled()
            ->user()
            ->orderBy('name')
            ->get()
            ->map(fn($tool) => [
                'slug' => $tool->slug,
                'name' => $tool->name,
                'description' => $tool->description,
            ]);

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
        $toolSelector = app(ToolSelector::class);

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
        $toolPrompt = $toolSelector->buildSystemPrompt($provider);
        if (!empty($toolPrompt)) {
            // If specific tools are selected, filter the prompt
            if ($allowedTools !== null && is_array($allowedTools)) {
                $filteredToolPrompt = $this->filterToolPrompt($allowedTools, $provider);
                if (!empty($filteredToolPrompt)) {
                    $sections[] = [
                        'title' => 'PocketDev Tools',
                        'content' => $filteredToolPrompt,
                        'source' => 'Dynamic (based on selected tools)',
                        'collapsible' => true,
                    ];
                }
            } else {
                $sections[] = [
                    'title' => 'PocketDev Tools',
                    'content' => $toolPrompt,
                    'source' => 'Dynamic (all tools enabled)',
                    'collapsible' => true,
                ];
            }
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
     * Filter tool prompt to only include selected tools.
     */
    private function filterToolPrompt(array $allowedTools, string $provider): string
    {
        // Get the tool slugs that are allowed
        $allowedSlugs = collect($allowedTools)->map(fn($t) => strtolower($t))->toArray();

        // Get enabled tools for the provider
        $tools = PocketTool::enabled()
            ->forProvider($provider)
            ->noNativeEquivalent()
            ->get();

        // Filter to only allowed tools
        $filteredTools = $tools->filter(function ($tool) use ($allowedSlugs) {
            return in_array(strtolower($tool->slug), $allowedSlugs);
        });

        if ($filteredTools->isEmpty()) {
            return '';
        }

        // We need to temporarily modify what buildSystemPrompt returns
        // For now, just note which tools are included
        $lines = ["# PocketDev Tools\n"];

        if ($provider === PocketTool::PROVIDER_CLAUDE_CODE) {
            $lines[] = "The following tools are available as artisan commands. Use your Bash tool to execute them.\n";
        }

        $grouped = $filteredTools->groupBy('category');

        foreach ($grouped as $category => $categoryTools) {
            $categoryTitle = match ($category) {
                PocketTool::CATEGORY_MEMORY => 'Memory System',
                PocketTool::CATEGORY_TOOLS => 'Tool Management',
                PocketTool::CATEGORY_FILE_OPS => 'File Operations',
                PocketTool::CATEGORY_CUSTOM => 'Custom Tools',
                default => ucfirst(str_replace('_', ' ', $category)),
            };

            $lines[] = "## {$categoryTitle}\n";

            foreach ($categoryTools as $tool) {
                if ($provider === PocketTool::PROVIDER_CLAUDE_CODE) {
                    $artisanCommand = $tool->getArtisanCommand();
                    $lines[] = "### {$artisanCommand}\n";
                } else {
                    $lines[] = "### {$tool->name}\n";
                }
                $lines[] = $tool->system_prompt ?? '';
                $lines[] = "";
            }
        }

        return implode("\n", $lines);
    }
}
