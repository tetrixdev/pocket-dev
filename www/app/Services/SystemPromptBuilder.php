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
 * 5. Panel dependencies - CDN libraries for panel creation/update
 *
 * RECENCY ZONE (end - high attention, task-relevant):
 * 6. Additional system prompt - project-wide customs (global)
 * 7. Workspace Prompt - workspace-level base prompt
 * 8. Agent instructions - task-specific behavior
 * 9. Working directory - current context
 * 10. Open panels - currently visible panels in session
 * 11. Environment - available resources
 */
class SystemPromptBuilder
{
    public function __construct(
        private SystemPromptService $systemPromptService,
        private ToolSelector $toolSelector
    ) {}

    /**
     * Build the system prompt for a conversation.
     *
     * Unified method that handles both API and CLI providers.
     * The $promptType parameter determines the tool injection strategy:
     * - 'api': uses ToolRegistry for API provider tool instructions
     * - 'cli': uses ToolSelector for PocketDev CLI tool instructions
     *
     * @param Conversation $conversation The conversation context
     * @param ToolRegistry $toolRegistry For API providers' tool instructions
     * @param string $promptType 'api' or 'cli' (from provider->getSystemPromptType())
     * @param string|null $providerType Provider type string (for CLI tool selection)
     */
    public function build(
        Conversation $conversation,
        ToolRegistry $toolRegistry,
        string $promptType = 'api',
        ?string $providerType = null
    ): string {
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
        // 2. Tool instructions (differs by prompt type)
        if ($promptType === 'cli') {
            if (!$providerType) {
                throw new \InvalidArgumentException('providerType is required when promptType is "cli"');
            }
            $pocketDevToolPrompt = $this->toolSelector->buildSystemPrompt($providerType, $allowedTools, $workspace);
            if (!empty($pocketDevToolPrompt)) {
                $sections[] = $pocketDevToolPrompt;
            }
        } else {
            $toolInstructions = $toolRegistry->getInstructions($allowedTools);
            if (!empty($toolInstructions)) {
                $sections[] = $this->buildToolSection($toolInstructions);
            }
        }

        // 3. Memory section (separate from tools)
        if ($memorySection = $this->toolSelector->buildMemorySection($agent)) {
            $sections[] = $memorySection;
        }

        // 4. Skills section (slash commands)
        if ($skillsSection = $this->toolSelector->buildSkillsSection($agent)) {
            $sections[] = $skillsSection;
        }

        // 5. Panel dependencies (for creating/updating panels)
        if ($panelSection = $this->buildPanelDependenciesSection($allowedTools)) {
            $sections[] = $panelSection;
        }

        // === RECENCY ZONE (task-relevant) ===
        // 6. Additional system prompt (global)
        $additionalPrompt = $this->systemPromptService->getAdditional();
        if (!empty($additionalPrompt)) {
            $sections[] = $additionalPrompt;
        }

        // 7. Workspace Prompt
        if ($workspace?->claude_base_prompt) {
            $sections[] = $this->buildWorkspacePromptSection($workspace->claude_base_prompt);
        }

        // 8. Agent-specific instructions
        $agentPrompt = $agent?->system_prompt;
        if (!empty($agentPrompt)) {
            $sections[] = $this->buildAgentSection($agentPrompt);
        }

        // 9. Working directory context
        $sections[] = $this->buildContextSection($conversation);

        // 10. Open panels (if any panels are open in the session)
        if ($openPanelsSection = $this->buildOpenPanelsSection($conversation)) {
            $sections[] = $openPanelsSection;
        }

        // 11. Environment (credentials and packages)
        if ($envSection = $this->buildEnvironmentSection($workspace)) {
            $sections[] = $envSection;
        }

        // Note: Context usage section removed to improve prompt caching.
        // The percentage changed every message, invalidating cache.
        // Users can see context usage in the UI progress bar instead.

        return implode("\n\n", array_filter($sections));
    }

    /**
     * Build the system prompt for CLI providers (Claude Code, Codex).
     *
     * @deprecated Use build() with promptType='cli' instead.
     */
    public function buildForCliProvider(Conversation $conversation, string $provider): string
    {
        return $this->build(
            $conversation,
            app(ToolRegistry::class),
            'cli',
            $provider
        );
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

        // 5. Panel dependencies
        if ($panelSection = $this->buildPanelDependenciesSection($allowedTools)) {
            $sections[] = $createSection(
                'Panel Dependencies',
                $panelSection,
                'config/panels.php'
            );
        }

        // === RECENCY ZONE (task-relevant) ===
        // 6. Additional system prompt (global)
        $additionalPrompt = $this->systemPromptService->getAdditional();
        if (!empty($additionalPrompt)) {
            $sections[] = $createSection(
                'Additional System Prompt',
                $additionalPrompt,
                'storage/pocketdev/additional-system-prompt.md'
            );
        }

        // 7. Workspace Prompt
        if ($workspace?->claude_base_prompt) {
            $sections[] = $createSection(
                'Workspace Prompt',
                $this->buildWorkspacePromptSection($workspace->claude_base_prompt),
                'Workspace settings'
            );
        }

        // 8. Agent instructions
        if (!empty($agentSystemPrompt)) {
            $sections[] = $createSection(
                'Agent Instructions',
                $this->buildAgentSection($agentSystemPrompt),
                'Agent configuration'
            );
        }

        // 9. Working directory
        $workingDir = $workspace?->getWorkingDirectoryPath() ?? '/workspace';
        $sections[] = $createSection(
            'Working Directory',
            "# Working Directory\n\nCurrent project: {$workingDir}\n\nAll file operations should be relative to or within this directory.",
            'Dynamic (per conversation)'
        );

        // 10. Open panels (placeholder - actual panels are per-session)
        $sections[] = $createSection(
            'Open Panels',
            "# Open Panels\n\n*This section shows panels currently open in the session.*\n\nExample:\n- git-status (id: abc-123) @ /workspace/default\n- file-explorer (id: def-456) @ /workspace/default\n\nUse `pd panel:peek <panel-slug>` to see current visible state.",
            'Dynamic (per session)'
        );

        // 11. Environment
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

            // Format: - panel-slug (id: full-uuid) @ /path/or/context
            $lines[] = "- {$slug} (id: {$id}) @ {$context}";
        }

        $panelList = implode("\n", $lines);

        return <<<PROMPT
# Open Panels

{$panelList}

To see what's currently visible in a panel, use the PanelPeek tool or run:
```bash
pd panel:peek <panel-slug>
pd panel:peek <panel-slug> --id=<panel-id>
```
PROMPT;
    }

    /**
     * Build panel dependencies section from config.
     * This is shown once in the system prompt for panel creation/update reference.
     * Only included if tool-create or tool-update is allowed (or all tools allowed).
     */
    private function buildPanelDependenciesSection(?array $allowedTools = null): ?string
    {
        // Only include if panel tools are allowed
        // null = all tools allowed, otherwise check for tool-create or tool-update
        if ($allowedTools !== null) {
            $panelToolsAllowed = in_array('tool-create', $allowedTools) || in_array('tool-update', $allowedTools);
            if (!$panelToolsAllowed) {
                return null;
            }
        }

        $config = config('panels');

        if (!$config) {
            return null;
        }

        $deps = $config['dependencies'] ?? [];
        $examples = $config['examples'] ?? [];
        $baseCss = $config['base_css'] ?? '';
        $tailwindTheme = $config['tailwind_theme'] ?? '';

        $doc = "# Panel Dependencies\n\n";

        $doc .= "## Base Dependencies (always loaded)\n\n";
        $doc .= "These libraries are loaded for ALL panels automatically:\n\n";
        foreach ($deps as $name => $dep) {
            $displayName = ucwords(str_replace('-', ' ', $name));
            $doc .= "### {$displayName}\n";
            $doc .= "- **URL:** `" . ($dep['url'] ?? 'N/A') . "`\n";
            $doc .= "- " . ($dep['description'] ?? '') . "\n\n";
        }

        $doc .= "## Additional Dependencies\n\n";
        $doc .= "Panels can load extra CDN libraries via `panel_dependencies` in `tool:create` or `tool:update`.\n";
        $doc .= "Each entry is an object with `type` (\"script\" or \"stylesheet\") and `url`.\n";
        $doc .= "Optional keys: `defer` (boolean), `crossorigin` (string).\n\n";
        $doc .= "Example:\n```json\n[{\"type\": \"script\", \"url\": \"https://cdn.jsdelivr.net/npm/chart.js@4.4.0\", \"defer\": true}]\n```\n\n";

        $doc .= "## Base CSS (Always Present)\n\n";
        $doc .= "```css\n" . trim($baseCss) . "\n```\n\n";

        $doc .= "## Tailwind Theme\n\n";
        $doc .= "```css\n" . trim($tailwindTheme) . "\n```\n\n";

        $doc .= "## Examples\n\n";

        if (!empty($examples['icons'])) {
            $doc .= "### Icons (Font Awesome)\n```html\n" . trim($examples['icons']) . "\n```\n\n";
        }

        if (!empty($examples['collapse'])) {
            $doc .= "### Collapse Animation\n```blade\n" . trim($examples['collapse']) . "\n```\n\n";
        }

        if (!empty($examples['card'])) {
            $doc .= "### Card Component\n```blade\n" . trim($examples['card']) . "\n```\n\n";
        }

        // Add script environment documentation if present
        if (!empty($config['script_environment'])) {
            $doc .= "\n" . $config['script_environment'] . "\n";
        }

        return $doc;
    }
}
