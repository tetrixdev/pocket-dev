<?php

namespace App\Services;

use App\Enums\Provider;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Credential;
use App\Models\MemoryDatabase;
use App\Models\Screen;
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
 * 4. Skills - slash commands that can be invoked
 *
 * RECENCY ZONE (end - high attention, task-relevant):
 * 5. Additional system prompt - project-wide customs (global)
 * 6. Workspace Prompt - workspace-level base prompt
 * 7. Agent instructions - task-specific behavior
 * 8. Working directory - current context
 * 9. Environment - available resources
 * 10. Context usage - current state
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

        // 4. Skills section (slash commands)
        if ($skillsSection = $this->toolSelector->buildSkillsSection($agent)) {
            $sections[] = $skillsSection;
        }

        // === RECENCY ZONE (task-relevant) ===
        // 5. Additional system prompt (global)
        $additionalPrompt = $this->systemPromptService->getAdditional();
        if (!empty($additionalPrompt)) {
            $sections[] = $additionalPrompt;
        }

        // 6. Workspace Prompt
        if ($workspace?->claude_base_prompt) {
            $sections[] = $this->buildWorkspacePromptSection($workspace->claude_base_prompt);
        }

        // 7. Agent-specific instructions
        $agentPrompt = $agent?->system_prompt;
        if (!empty($agentPrompt)) {
            $sections[] = $this->buildAgentSection($agentPrompt);
        }

        // 8. Working directory context
        $sections[] = $this->buildContextSection($conversation);

        // 9. Open panels (if any panels are open in the session)
        if ($openPanelsSection = $this->buildOpenPanelsSection($conversation)) {
            $sections[] = $openPanelsSection;
        }

        // 10. Environment (credentials and packages)
        if ($envSection = $this->buildEnvironmentSection($workspace)) {
            $sections[] = $envSection;
        }

        // 11. Context usage (dynamic)
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

        // 4. Skills section (slash commands)
        if ($skillsSection = $this->toolSelector->buildSkillsSection($agent)) {
            $sections[] = $skillsSection;
        }

        // === RECENCY ZONE (task-relevant) ===
        // 5. Additional system prompt (global)
        $additionalPrompt = $this->systemPromptService->getAdditional();
        if (!empty($additionalPrompt)) {
            $sections[] = $additionalPrompt;
        }

        // 6. Workspace Prompt
        if ($workspace?->claude_base_prompt) {
            $sections[] = $this->buildWorkspacePromptSection($workspace->claude_base_prompt);
        }

        // 7. Agent-specific instructions
        $agentPrompt = $agent?->system_prompt;
        if (!empty($agentPrompt)) {
            $sections[] = $this->buildAgentSection($agentPrompt);
        }

        // 8. Working directory context
        $sections[] = $this->buildContextSection($conversation);

        // 9. Open panels (if any panels are open in the session)
        if ($openPanelsSection = $this->buildOpenPanelsSection($conversation)) {
            $sections[] = $openPanelsSection;
        }

        // 10. Environment (credentials and packages)
        if ($envSection = $this->buildEnvironmentSection($workspace)) {
            $sections[] = $envSection;
        }

        // 11. Context usage (dynamic)
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

        // 4. Skills (from memory schemas)
        $skillsHierarchy = $this->toolSelector->buildSkillsPreviewHierarchy($previewAgent);
        if ($skillsHierarchy) {
            $sections[] = $skillsHierarchy;
        }

        // === RECENCY ZONE (task-relevant) ===
        // 5. Additional system prompt (global)
        $additionalPrompt = $this->systemPromptService->getAdditional();
        if (!empty($additionalPrompt)) {
            $sections[] = $createSection(
                'Additional System Prompt',
                $additionalPrompt,
                'storage/pocketdev/additional-system-prompt.md'
            );
        }

        // 6. Workspace Prompt
        if ($workspace?->claude_base_prompt) {
            $sections[] = $createSection(
                'Workspace Prompt',
                $this->buildWorkspacePromptSection($workspace->claude_base_prompt),
                'Workspace settings'
            );
        }

        // 7. Agent instructions
        if (!empty($agentSystemPrompt)) {
            $sections[] = $createSection(
                'Agent Instructions',
                $this->buildAgentSection($agentSystemPrompt),
                'Agent configuration'
            );
        }

        // 8. Working directory
        $workingDir = $workspace?->getWorkingDirectoryPath() ?? '/workspace';
        $sections[] = $createSection(
            'Working Directory',
            "# Working Directory\n\nCurrent project: {$workingDir}\n\nAll file operations should be relative to or within this directory.",
            'Dynamic (per conversation)'
        );

        // 9. Environment
        $envContent = $this->buildEnvironmentSection($workspace);
        if (!empty($envContent)) {
            $sections[] = $createSection(
                'Environment',
                $envContent,
                'Dynamic (workspace settings)'
            );
        }

        // Calculate totals - sum estimated_tokens from top-level sections
        // (each section's estimated_tokens already includes its children)
        $totalTokens = array_sum(array_column($sections, 'estimated_tokens'));

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

    private function buildWorkspacePromptSection(string $prompt): string
    {
        return <<<PROMPT
# Workspace Prompt

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

    /**
     * Build open panels section showing which panels are currently open.
     *
     * This helps AI know what panels are available in the current session,
     * avoiding duplicate opens and enabling peek functionality.
     */
    private function buildOpenPanelsSection(Conversation $conversation): ?string
    {
        // Get the screen for this conversation
        $screen = $conversation->screen()->with('session')->first();

        if (!$screen || !$screen->session) {
            return null;
        }

        $session = $screen->session;

        // Get all panel screens in this session with their panel states
        $panelScreens = Screen::where('session_id', $session->id)
            ->where('type', Screen::TYPE_PANEL)
            ->with('panelState')
            ->get();

        if ($panelScreens->isEmpty()) {
            return null;
        }

        $lines = [];
        foreach ($panelScreens as $panelScreen) {
            $panelState = $panelScreen->panelState;
            $slug = $panelScreen->panel_slug;
            $id = $panelState?->id ?? $panelScreen->panel_id ?? 'unknown';

            // Try to get a meaningful context from parameters (e.g., path)
            $context = 'N/A';
            if ($panelState && !empty($panelState->parameters)) {
                // Common parameter names that provide context
                $contextKeys = ['path', 'directory', 'file', 'query', 'schema', 'table'];
                foreach ($contextKeys as $key) {
                    if (isset($panelState->parameters[$key])) {
                        $context = $panelState->parameters[$key];
                        break;
                    }
                }
            }

            // Format: - panel-slug (id: abc123) @ /path/or/context
            $shortId = substr($id, 0, 8);
            $lines[] = "- {$slug} (id: {$shortId}) @ {$context}";
        }

        $panelList = implode("\n", $lines);

        return <<<PROMPT
# Open Panels

{$panelList}

To see what's currently visible in a panel, use the PanelPeek tool or run:
```bash
pd panel:peek <panel-slug>
pd panel:peek <panel-slug> --id=<short-id>
```
PROMPT;
    }
}
