<?php

namespace App\Services;

use App\Enums\Provider;
use App\Models\Agent;
use App\Models\MemoryDatabase;
use App\Models\PocketTool;
use App\Models\ToolConflict;
use App\Models\Workspace;
use App\Services\MemorySchemaService;
use App\Tools\Tool;
use App\Tools\UserTool;
use Illuminate\Support\Collection;

/**
 * ToolSelector provides methods for selecting and filtering tools.
 *
 * It uses the ToolRegistry (which auto-discovers Tool classes and wraps
 * user tools from the database) as the source of truth.
 *
 * Tool filtering follows a three-tier hierarchy:
 * 1. Global enablement (PocketTool.enabled)
 * 2. Workspace enablement (WorkspaceTool, no entry = enabled)
 * 3. Agent allowed tools (optional per-agent filtering)
 */
class ToolSelector
{
    public function __construct(
        private ToolRegistry $registry
    ) {}

    /**
     * Get all tools from the registry.
     */
    public function getAllTools(): Collection
    {
        return collect($this->registry->all());
    }

    /**
     * Get all tools from the registry, filtered by workspace.
     */
    public function getAllToolsForWorkspace(Workspace $workspace): Collection
    {
        return collect($this->registry->allForWorkspace($workspace));
    }

    /**
     * Get all tools available for a specific provider.
     * All tools are available for all providers - filtering is done in getDefaultTools().
     */
    public function getAvailableTools(string $provider, ?Workspace $workspace = null): Collection
    {
        $tools = $workspace
            ? $this->getAllToolsForWorkspace($workspace)
            : $this->getAllTools();

        return $tools
            ->sortBy([
                ['category', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    /**
     * Get default tools for a provider (for new conversations).
     * For CLI providers (Claude Code, Codex): excludes file operations tools (they have native equivalents).
     */
    public function getDefaultTools(string $provider, ?Workspace $workspace = null): Collection
    {
        $tools = $this->getAvailableTools($provider, $workspace);

        // For CLI providers, exclude file operation tools (native equivalents exist)
        $providerEnum = Provider::tryFrom($provider);
        if ($providerEnum?->isCliProvider()) {
            return $tools->filter(fn(Tool $tool) => $tool->category !== PocketTool::CATEGORY_FILE_OPS);
        }

        return $tools;
    }

    /**
     * Get tools for system prompt injection (CLI providers).
     * Returns only non-file-ops tools (CLI providers have native file ops).
     */
    public function getToolsForSystemPrompt(string $provider, ?Workspace $workspace = null): Collection
    {
        $providerEnum = Provider::tryFrom($provider);

        // For CLI providers, exclude file_ops since they have native equivalents
        if ($providerEnum?->isCliProvider()) {
            return $this->getAvailableTools($provider, $workspace)
                ->filter(fn(Tool $tool) => $tool->category !== PocketTool::CATEGORY_FILE_OPS);
        }

        return $this->getAvailableTools($provider, $workspace);
    }

    /**
     * Get tools by category for a provider.
     */
    public function getToolsByCategory(string $provider): Collection
    {
        return $this->getAvailableTools($provider)
            ->groupBy('category');
    }

    /**
     * Check if enabling a tool would conflict with already enabled tools.
     *
     * @return array|null Conflict info or null if no conflict
     */
    public function checkConflict(string $toolSlug, array $enabledToolSlugs): ?array
    {
        foreach ($enabledToolSlugs as $enabledSlug) {
            $conflict = ToolConflict::findConflict($toolSlug, $enabledSlug);

            if ($conflict) {
                return [
                    'conflicting_tool' => $enabledSlug,
                    'conflict_type' => $conflict->conflict_type,
                    'resolution_hint' => $conflict->resolution_hint,
                ];
            }
        }

        return null;
    }

    /**
     * Get all conflicts for a specific tool.
     */
    public function getConflictsFor(string $toolSlug): Collection
    {
        return ToolConflict::getConflictsFor($toolSlug);
    }

    /**
     * Get all memory tools (schema + data operations).
     */
    public function getMemoryTools(): Collection
    {
        return $this->getAllTools()
            ->filter(fn(Tool $tool) => in_array($tool->category, [
                PocketTool::CATEGORY_MEMORY_SCHEMA,
                PocketTool::CATEGORY_MEMORY_DATA,
            ]))
            ->sortBy('name')
            ->values();
    }

    /**
     * Get tool management tools.
     */
    public function getToolManagementTools(): Collection
    {
        return $this->getAllTools()
            ->filter(fn(Tool $tool) => $tool->category === PocketTool::CATEGORY_TOOLS)
            ->sortBy('name')
            ->values();
    }

    /**
     * Get user-created tools.
     */
    public function getUserTools(): Collection
    {
        return $this->getAllTools()
            ->filter(fn(Tool $tool) => $tool instanceof UserTool)
            ->sortBy('name')
            ->values();
    }

    /**
     * Get file operation tools (for API providers only).
     */
    public function getFileOperationTools(): Collection
    {
        return $this->getAllTools()
            ->filter(fn(Tool $tool) => $tool->category === PocketTool::CATEGORY_FILE_OPS)
            ->sortBy('name')
            ->values();
    }

    /**
     * Build system prompt section for PocketDev tools.
     * For CLI providers, these are artisan commands to run via Bash.
     *
     * @param string $provider The provider type
     * @param array|null $allowedTools Tool slugs to allow (null = all, [] = none, [...] = specific)
     * @param Workspace|null $workspace Workspace for tool filtering
     * @param Agent|null $agent Agent for memory schema access (uses agent's enabled schemas)
     */
    public function buildSystemPrompt(
        string $provider,
        ?array $allowedTools = null,
        ?Workspace $workspace = null,
        ?Agent $agent = null
    ): string {
        $tools = $this->getToolsForSystemPrompt($provider, $workspace);

        // Filter by agent's allowed tools if specified (case-insensitive)
        $tools = ToolFilterHelper::filterCollection($tools, $allowedTools);

        if ($tools->isEmpty()) {
            return '';
        }

        $sections = [];
        $providerEnum = Provider::tryFrom($provider);
        $isCliProvider = $providerEnum?->isCliProvider() ?? false;

        // Add preamble for CLI providers explaining these are CLI commands
        if ($isCliProvider) {
            $sections[] = "# PocketDev Tools\n";
            $sections[] = "These PocketDev tools are invoked via PHP Artisan commands. Use your Bash tool to execute them.\n";
            $sections[] = $this->buildInvocationGuide();
        } else {
            $sections[] = "# PocketDev Tools\n";
        }

        // Add available memory schemas for this agent
        $schemasSection = $this->buildAvailableSchemasSection($agent);
        if ($schemasSection) {
            $sections[] = $schemasSection;
        }

        // Build sections using tool groups from config
        $groups = collect(config('tool-groups', []))->sortBy('sort_order');
        $allGroupedCategories = $groups->flatMap(fn($g) => $g['categories'] ?? [])->all();

        foreach ($groups as $group) {
            $groupCategories = $group['categories'] ?? [];
            $groupTools = $tools->filter(fn(Tool $t) => in_array($t->category, $groupCategories));

            if ($groupTools->isNotEmpty()) {
                // Output group intro if defined
                if ($group['system_prompt_active'] ?? null) {
                    $sections[] = $group['system_prompt_active'];
                    $sections[] = "";
                }

                // Output tools organized by category within the group
                $sections[] = $this->buildGroupToolsSection($groupTools, $group, $isCliProvider);
            } elseif ($group['system_prompt_inactive'] ?? null) {
                // Group has no enabled tools - show inactive message
                $sections[] = $group['system_prompt_inactive'];
                $sections[] = "";
            }
        }

        // Handle ungrouped tools (custom category or categories not in any group)
        $ungroupedTools = $tools->filter(fn(Tool $t) => !in_array($t->category, $allGroupedCategories));

        if ($ungroupedTools->isNotEmpty()) {
            $systemUngrouped = $ungroupedTools->filter(fn(Tool $t) => !($t instanceof UserTool));
            $customUngrouped = $ungroupedTools->filter(fn(Tool $t) => $t instanceof UserTool);

            if ($systemUngrouped->isNotEmpty()) {
                $sections[] = "## Other\n";
                $sections[] = $this->buildToolsList($systemUngrouped, $isCliProvider);
            }

            if ($customUngrouped->isNotEmpty()) {
                $sections[] = "## Custom Tools\n";
                $sections[] = "User-created tools invoked via `tool:run`.\n";
                $sections[] = $this->buildToolsList($customUngrouped, $isCliProvider);
            }
        }

        return implode("\n", $sections);
    }

    /**
     * Build the tools section for a group, organized by category.
     */
    private function buildGroupToolsSection(Collection $tools, array $group, bool $isCliProvider): string
    {
        $sections = [];
        $categoryLabels = $group['category_labels'] ?? [];

        // Split into system and custom tools
        $systemTools = $tools->filter(fn(Tool $t) => !($t instanceof UserTool));
        $customTools = $tools->filter(fn(Tool $t) => $t instanceof UserTool);

        // System tools grouped by category
        if ($systemTools->isNotEmpty()) {
            $sections[] = "### System Tools\n";

            foreach ($group['categories'] as $category) {
                $categoryTools = $systemTools->filter(fn(Tool $t) => $t->category === $category);
                if ($categoryTools->isEmpty()) {
                    continue;
                }

                // Use custom label if defined, otherwise format the category name
                $categoryLabel = $categoryLabels[$category] ?? $this->formatCategoryTitle($category);
                $sections[] = "**{$categoryLabel}**\n";
                $sections[] = $this->buildToolsList($categoryTools, $isCliProvider);
            }
        }

        // Custom tools in this group's categories
        if ($customTools->isNotEmpty()) {
            $sections[] = "### Custom Tools\n";
            $sections[] = $this->buildToolsList($customTools, $isCliProvider);
        }

        return implode("\n", $sections);
    }

    /**
     * Build a list of tool documentation.
     */
    private function buildToolsList(Collection $tools, bool $isCliProvider): string
    {
        $sections = [];

        foreach ($tools as $tool) {
            if ($isCliProvider) {
                $artisanCommand = $tool->getArtisanCommand();
                $sections[] = "#### {$artisanCommand}\n";
            } else {
                $sections[] = "#### {$tool->name}\n";
            }
            $sections[] = $this->getToolInstructions($tool, $isCliProvider);
            $sections[] = $this->formatToolParameters($tool);
            $sections[] = "";
        }

        return implode("\n", $sections);
    }

    /**
     * Build the available memory schemas section for the system prompt.
     * Shows which schemas the agent has access to.
     *
     * @param Agent|null $agent The agent to get schemas for
     */
    private function buildAvailableSchemasSection(?Agent $agent): ?string
    {
        // Get available schemas for this agent
        $schemas = $this->getAvailableSchemas($agent);

        if ($schemas->isEmpty()) {
            return null;
        }

        $lines = [];
        $lines[] = "## Available Memory Schemas\n";
        $lines[] = "You have access to the following memory schemas. Use `--schema=<name>` with all memory tools.\n";

        foreach ($schemas as $schema) {
            $shortName = $schema->schema_name;
            $lines[] = "- **{$shortName}**" . ($schema->description ? ": {$schema->description}" : "");
        }

        $lines[] = "\nExample: `php artisan memory:query --schema={$schemas->first()->schema_name} --sql=\"SELECT * FROM {$schemas->first()->getFullSchemaName()}.schema_registry\"`";
        $lines[] = "";

        return implode("\n", $lines);
    }

    /**
     * Get available memory schemas for an agent.
     * If no agent, returns all schemas. If agent has workspace, filters by workspace then agent access.
     * Respects the agent's inherit_workspace_schemas setting.
     */
    private function getAvailableSchemas(?Agent $agent): Collection
    {
        if (!$agent) {
            // No agent context - return all schemas
            return MemoryDatabase::orderBy('name')->get();
        }

        // Use getEnabledMemoryDatabases() which respects inherit_workspace_schemas:
        // - If inheriting: returns all workspace-enabled schemas
        // - Otherwise: returns only explicitly granted schemas that are workspace-enabled
        return $agent->getEnabledMemoryDatabases()->sortBy('name')->values();
    }

    /**
     * Format category name for display.
     */
    private function formatCategoryTitle(string $category): string
    {
        return match ($category) {
            PocketTool::CATEGORY_MEMORY => 'Memory System',
            PocketTool::CATEGORY_MEMORY_SCHEMA => 'Memory Schema Operations',
            PocketTool::CATEGORY_MEMORY_DATA => 'Memory Data Operations',
            PocketTool::CATEGORY_TOOLS => 'Tool Management',
            PocketTool::CATEGORY_FILE_OPS => 'File Operations',
            PocketTool::CATEGORY_CUSTOM => 'Custom Tools',
            default => ucfirst(str_replace('_', ' ', $category)),
        };
    }

    /**
     * Get tool instructions with CLI or API examples appended.
     *
     * @param Tool $tool The tool
     * @param bool $isCli Whether to use CLI examples (true) or API examples (false)
     * @return string The combined instructions
     */
    private function getToolInstructions(Tool $tool, bool $isCli): string
    {
        $base = $tool->instructions ?? $tool->description;
        $examples = $isCli ? $tool->cliExamples : $tool->apiExamples;

        if ($examples) {
            return $base . "\n\n" . $examples;
        }

        return $base;
    }

    /**
     * Build the invocation guide section for CLI providers.
     */
    private function buildInvocationGuide(): string
    {
        return <<<'GUIDE'
## How to Invoke

**Built-in commands (memory, tool management):**
```bash
php artisan memory:query --sql="SELECT id, name FROM memory_structures"
php artisan memory:create --structure=project --name="My Project" --data='{"status":"active"}'
php artisan tool:list
```

**User-created tools:**
```bash
php artisan tool:run <slug> -- --arg1=value1 --arg2=value2
```

**Important:** Only `tool:run` requires the `--` separator, and it must appear before the tool arguments.

GUIDE;
    }

    /**
     * Get memory schema tools.
     */
    public function getMemorySchemaTools(): Collection
    {
        return $this->getAllTools()
            ->filter(fn(Tool $tool) => $tool->category === PocketTool::CATEGORY_MEMORY_SCHEMA)
            ->sortBy('name')
            ->values();
    }

    /**
     * Get memory data tools.
     */
    public function getMemoryDataTools(): Collection
    {
        return $this->getAllTools()
            ->filter(fn(Tool $tool) => $tool->category === PocketTool::CATEGORY_MEMORY_DATA)
            ->sortBy('name')
            ->values();
    }

    /**
     * Format tool parameters from inputSchema for display in system prompt.
     */
    private function formatToolParameters(Tool $tool): string
    {
        $properties = $tool->inputSchema['properties'] ?? [];

        if (empty($properties)) {
            return '';
        }

        $required = $tool->inputSchema['required'] ?? [];
        $lines = ['', '**Parameters:**'];

        foreach ($properties as $name => $def) {
            $type = $def['type'] ?? 'any';
            $desc = $def['description'] ?? '';
            $isRequired = in_array($name, $required) ? ' *(required)*' : '';

            if ($desc) {
                $lines[] = "- `--{$name}`: {$desc} [{$type}]{$isRequired}";
            } else {
                $lines[] = "- `--{$name}`: [{$type}]{$isRequired}";
            }
        }

        return implode("\n", $lines);
    }
}
