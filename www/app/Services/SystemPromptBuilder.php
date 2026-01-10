<?php

namespace App\Services;

use App\Enums\Provider;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Credential;
use App\Models\MemoryDatabase;
use App\Models\SystemPackage;
use App\Models\Workspace;

/**
 * Builds the system prompt for AI providers.
 *
 * System prompt structure (optimized for primacy/recency effects):
 *
 * PRIMACY ZONE (beginning - high attention):
 * 1. Core system prompt - fundamental identity and behavior
 *
 * MIDDLE ZONE (reference material - retrievable):
 * 2. Tools - capabilities and how to use them
 * 3. Memory - available data schemas
 *
 * RECENCY ZONE (end - high attention, task-relevant):
 * 4. Additional system prompt - project-wide customs
 * 5. Agent instructions - task-specific behavior
 * 6. Working directory - current context
 * 7. Environment - available resources
 * 8. Context usage - current state
 */
class SystemPromptBuilder
{
    public function __construct(
        private SystemPromptService $systemPromptService,
        private ToolSelector $toolSelector
    ) {}

    /**
     * Build the system prompt for a conversation (API providers).
     */
    public function build(Conversation $conversation, ToolRegistry $toolRegistry): string
    {
        $agent = $conversation->agent()->first();
        $workspace = $agent?->workspace;
        $allowedTools = null;
        if ($agent && !$agent->inheritsWorkspaceTools()) {
            $allowedTools = $agent->allowed_tools;
        }

        $sections = [];

        // === PRIMACY ZONE ===
        // 1. Core system prompt
        $sections[] = $this->systemPromptService->getCore();

        // === MIDDLE ZONE (reference material) ===
        // 2. Tool instructions
        $toolInstructions = $toolRegistry->getInstructions($allowedTools);
        if (!empty($toolInstructions)) {
            $sections[] = $this->buildToolSection($toolInstructions);
        }

        // 3. Memory section (separate from tools)
        if ($memorySection = $this->toolSelector->buildMemorySection($agent)) {
            $sections[] = $memorySection;
        }

        // === RECENCY ZONE (task-relevant) ===
        // 4. Additional system prompt
        $additionalPrompt = $this->systemPromptService->getAdditional();
        if (!empty($additionalPrompt)) {
            $sections[] = $additionalPrompt;
        }

        // 5. Agent-specific instructions
        $agentPrompt = $agent?->system_prompt;
        if (!empty($agentPrompt)) {
            $sections[] = $this->buildAgentSection($agentPrompt);
        }

        // 6. Working directory context
        $sections[] = $this->buildContextSection($conversation);

        // 7. Environment (credentials and packages)
        if ($envSection = $this->buildEnvironmentSection($workspace)) {
            $sections[] = $envSection;
        }

        // 8. Context usage (dynamic)
        if ($contextUsage = $this->buildContextUsageSection($conversation)) {
            $sections[] = $contextUsage;
        }

        return implode("\n\n", array_filter($sections));
    }

    /**
     * Build the system prompt for CLI providers (Claude Code, Codex).
     * Injects PocketDev-exclusive tools (memory, tool management, user tools).
     */
    public function buildForCliProvider(Conversation $conversation, string $provider): string
    {
        $agent = $conversation->agent()->first();
        $workspace = $agent?->workspace;
        $allowedTools = null;
        if ($agent && !$agent->inheritsWorkspaceTools()) {
            $allowedTools = $agent->allowed_tools;
        }

        $sections = [];

        // === PRIMACY ZONE ===
        // 1. Core system prompt
        $sections[] = $this->systemPromptService->getCore();

        // === MIDDLE ZONE (reference material) ===
        // 2. PocketDev tools
        $pocketDevToolPrompt = $this->toolSelector->buildSystemPrompt($provider, $allowedTools, $workspace);
        if (!empty($pocketDevToolPrompt)) {
            $sections[] = $pocketDevToolPrompt;
        }

        // 3. Memory section (separate from tools)
        if ($memorySection = $this->toolSelector->buildMemorySection($agent)) {
            $sections[] = $memorySection;
        }

        // === RECENCY ZONE (task-relevant) ===
        // 4. Additional system prompt
        $additionalPrompt = $this->systemPromptService->getAdditional();
        if (!empty($additionalPrompt)) {
            $sections[] = $additionalPrompt;
        }

        // 5. Agent-specific instructions
        $agentPrompt = $agent?->system_prompt;
        if (!empty($agentPrompt)) {
            $sections[] = $this->buildAgentSection($agentPrompt);
        }

        // 6. Working directory context
        $sections[] = $this->buildContextSection($conversation);

        // 7. Environment (credentials and packages)
        if ($envSection = $this->buildEnvironmentSection($workspace)) {
            $sections[] = $envSection;
        }

        // 8. Context usage (dynamic)
        if ($contextUsage = $this->buildContextUsageSection($conversation)) {
            $sections[] = $contextUsage;
        }

        return implode("\n\n", array_filter($sections));
    }

    /**
     * Build preview sections for the agent form.
     * Returns structured data for each section with metadata for display.
     *
     * @param  string  $provider  The provider type (claude_code, anthropic, openai)
     * @param  string|null  $agentSystemPrompt  Custom agent system prompt
     * @param  array|null  $allowedTools  Tool filter (null = all tools)
     * @param  array  $memorySchemaIds  Selected memory schema IDs
     * @param  Workspace|null  $workspace  Target workspace
     * @return array{sections: array, estimated_tokens: int}
     */
    public function buildPreviewSections(
        string $provider,
        ?string $agentSystemPrompt,
        ?array $allowedTools,
        array $memorySchemaIds,
        ?Workspace $workspace
    ): array {
        $sections = [];

        // Helper to create section with token estimate
        $createSection = function (string $title, string $content, string $source, array $children = []): array {
            $chars = strlen($content);
            $childTokens = array_sum(array_column($children, 'estimated_tokens'));

            return [
                'title' => $title,
                'content' => $content,
                'source' => $source,
                'collapsed' => true,
                'chars' => $chars,
                'estimated_tokens' => (int) ceil($chars / 4) + $childTokens,
                'children' => $children,
            ];
        };

        // Create a preview agent for memory schema access
        // Always create an agent to avoid null = "all schemas" behavior
        $previewAgent = new Agent([
            'name' => 'preview',
            'inherit_workspace_schemas' => false,
        ]);
        if (!empty($memorySchemaIds)) {
            $previewAgent->setRelation('memoryDatabases', MemoryDatabase::whereIn('id', $memorySchemaIds)->get());
        } else {
            // Explicitly set empty collection when no schemas selected
            $previewAgent->setRelation('memoryDatabases', collect());
        }

        $isCliProvider = in_array($provider, [Provider::ClaudeCode->value, Provider::Codex->value]);

        // === PRIMACY ZONE ===
        // 1. Core system prompt
        $corePrompt = $this->systemPromptService->getCore();
        $sections[] = $createSection(
            'Core System Prompt',
            $corePrompt,
            $this->systemPromptService->isCoreOverridden()
                ? 'storage/pocketdev/system-prompt.md (custom)'
                : 'resources/defaults/system-prompt.md'
        );

        // === MIDDLE ZONE (reference material) ===
        // 2. Tools (hierarchical: groups → categories → tools)
        if ($isCliProvider) {
            $toolsHierarchy = $this->toolSelector->buildToolsPreviewHierarchy($provider, $allowedTools, $workspace);
            if (!empty($toolsHierarchy['children'])) {
                $sections[] = $toolsHierarchy;
            }
        }

        // 3. Memory (hierarchical: schemas → tables)
        $memoryHierarchy = $this->toolSelector->buildMemoryPreviewHierarchy($previewAgent);
        if ($memoryHierarchy) {
            $sections[] = $memoryHierarchy;
        }

        // === RECENCY ZONE (task-relevant) ===
        // 4. Additional system prompt
        $additionalPrompt = $this->systemPromptService->getAdditional();
        if (!empty($additionalPrompt)) {
            $sections[] = $createSection(
                'Additional System Prompt',
                $additionalPrompt,
                'storage/pocketdev/additional-system-prompt.md'
            );
        }

        // 5. Agent instructions
        if (!empty($agentSystemPrompt)) {
            $sections[] = $createSection(
                'Agent Instructions',
                $this->buildAgentSection($agentSystemPrompt),
                'Agent configuration'
            );
        }

        // 6. Working directory
        $workingDir = $workspace?->getWorkingDirectoryPath() ?? '/workspace';
        $sections[] = $createSection(
            'Working Directory',
            "# Working Directory\n\nCurrent project: {$workingDir}\n\nAll file operations should be relative to or within this directory.",
            'Dynamic (per conversation)'
        );

        // 7. Environment
        $envContent = $this->buildEnvironmentSection($workspace);
        if (!empty($envContent)) {
            $sections[] = $createSection(
                'Environment',
                $envContent,
                'Dynamic (workspace settings)'
            );
        }

        // Calculate totals
        $totalChars = array_sum(array_column($sections, 'chars'));
        $totalTokens = (int) ceil($totalChars / 4);

        return [
            'sections' => $sections,
            'estimated_tokens' => $totalTokens,
        ];
    }

    private function buildAgentSection(string $prompt): string
    {
        return <<<PROMPT
# Agent Instructions

{$prompt}
PROMPT;
    }

    private function buildToolSection(string $instructions): string
    {
        return <<<PROMPT
# Tool Instructions

{$instructions}
PROMPT;
    }

    private function buildContextSection(Conversation $conversation): string
    {
        $workingDir = $conversation->working_directory;

        return <<<PROMPT
# Working Directory

Current project: {$workingDir}

All file operations should be relative to or within this directory.
PROMPT;
    }

    private function buildContextUsageSection(Conversation $conversation): ?string
    {
        $percentage = $conversation->getContextUsagePercentage();

        if ($percentage === null) {
            return null;
        }

        $formatted = number_format($percentage, 0);

        return "Context window usage: {$formatted}% (estimate includes thinking tokens from current turn)";
    }

    /**
     * Build environment section showing available credentials and packages.
     * This helps AI know what's available without exposing actual values.
     * Filters by workspace if provided.
     */
    private function buildEnvironmentSection(?Workspace $workspace): ?string
    {
        $lines = [];
        $workspaceId = $workspace?->id;

        // Get credential env var names for this workspace (not values!)
        // Global + workspace-specific, with workspace ones overriding global
        $credentials = Credential::getForWorkspace($workspaceId);

        // Get unique env_var names (workspace-specific takes precedence)
        $globalEnvVars = [];
        $workspaceEnvVars = [];

        foreach ($credentials as $cred) {
            if ($cred->workspace_id === null) {
                $globalEnvVars[$cred->env_var] = true;
            } else {
                $workspaceEnvVars[$cred->env_var] = true;
            }
        }

        // Merge: workspace overrides global
        $envVarNames = array_keys(array_merge($globalEnvVars, $workspaceEnvVars));

        if (!empty($envVarNames)) {
            $lines[] = '**Credentials:** '.implode(', ', $envVarNames);
        }

        // Get packages for this workspace
        $packages = SystemPackage::getNamesForWorkspace($workspace);
        if (!empty($packages)) {
            $lines[] = '**Packages:** '.implode(', ', $packages);
        }

        if (empty($lines)) {
            return null;
        }

        return "# Environment\n\n".implode("\n", $lines);
    }
}
