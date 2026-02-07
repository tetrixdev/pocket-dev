<?php

namespace App\Services;

use App\Enums\Provider;
use App\Models\Agent;
use App\Models\MemoryDatabase;
use App\Models\PocketTool;
use App\Models\ToolConflict;
use App\Models\Workspace;
use App\Services\MemorySchemaService;
use App\Panels\PanelRegistry;
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
        private ToolRegistry $registry,
        private PanelRegistry $panelRegistry
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
     * Note: Memory schemas are now built separately via buildMemorySection().
     *
     * @param string $provider The provider type
     * @param array|null $allowedTools Tool slugs to allow (null = all, [] = none, [...] = specific)
     * @param Workspace|null $workspace Workspace for tool filtering
     */
    public function buildSystemPrompt(
        string $provider,
        ?array $allowedTools = null,
        ?Workspace $workspace = null
    ): string {
        // Build hierarchy (single source of truth) then flatten to string
        $hierarchy = $this->buildToolsPreviewHierarchy($provider, $allowedTools, $workspace);

        if (empty($hierarchy['children'])) {
            return '';
        }

        return $this->flattenHierarchy($hierarchy);
    }

    /**
     * Flatten a hierarchical structure into a single string.
     * Recursively concatenates content from all nodes.
     */
    private function flattenHierarchy(array $node): string
    {
        $parts = [];

        // Add this node's content
        if (!empty($node['content'])) {
            $parts[] = $node['content'];
        }

        // Recursively add children's content
        if (!empty($node['children'])) {
            foreach ($node['children'] as $child) {
                $parts[] = $this->flattenHierarchy($child);
            }
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * @deprecated Use buildSystemPrompt() which now uses the hierarchy as single source of truth.
     * Kept temporarily for comparison/debugging. Remove once hierarchy approach is verified.
     */
    public function buildSystemPromptLegacy(
        string $provider,
        ?array $allowedTools = null,
        ?Workspace $workspace = null
    ): string {
        $tools = $this->getToolsForSystemPrompt($provider, $workspace);
        $tools = ToolFilterHelper::filterCollection($tools, $allowedTools);

        if ($tools->isEmpty()) {
            return '';
        }

        $sections = [];
        $providerEnum = Provider::tryFrom($provider);
        $isCliProvider = $providerEnum?->isCliProvider() ?? false;

        // Add preamble for CLI providers
        if ($isCliProvider) {
            $sections[] = "# PocketDev Tools\n";
            $sections[] = "These PocketDev tools are invoked via PHP Artisan commands. Use your Bash tool to execute them.\n";
            $sections[] = $this->buildInvocationGuide();
        } else {
            $sections[] = "# PocketDev Tools\n";
        }

        // Build sections using tool groups from config
        $groups = collect(config('tool-groups', []))->sortBy('sort_order');
        $allGroupedCategories = $groups->flatMap(fn($g) => $g['categories'] ?? [])->all();

        foreach ($groups as $group) {
            $groupCategories = $group['categories'] ?? [];
            $groupTools = $tools->filter(fn(Tool $t) => in_array($t->category, $groupCategories));
            $groupName = $group['name'] ?? ucfirst(array_search($group, config('tool-groups', [])));

            if ($groupTools->isNotEmpty()) {
                $sections[] = "## {$groupName}\n";
                if ($group['system_prompt_active'] ?? null) {
                    $sections[] = $group['system_prompt_active']."\n";
                }
                // Build tool list
                foreach ($groupTools->sortBy('name') as $tool) {
                    $artisanCommand = $tool->getArtisanCommand();
                    $sections[] = "#### ".($isCliProvider ? $artisanCommand : $tool->name)."\n";
                    $sections[] = $this->getToolInstructions($tool, $isCliProvider);
                    $sections[] = $this->formatToolParameters($tool);
                    $sections[] = "";
                }
            } elseif ($group['system_prompt_inactive'] ?? null) {
                $sections[] = "## {$groupName}\n";
                $sections[] = $group['system_prompt_inactive'];
                $sections[] = "";
            }
        }

        // Handle ungrouped/custom tools
        $ungroupedTools = $tools->filter(fn(Tool $t) => !in_array($t->category, $allGroupedCategories));
        $customTools = $ungroupedTools->filter(fn(Tool $t) => $t instanceof UserTool);

        if ($customTools->isNotEmpty()) {
            $sections[] = "## Custom Tools\n";
            $sections[] = "User-created tools invoked via `tool:run`.\n";
            foreach ($customTools->sortBy('name') as $tool) {
                $artisanCommand = $tool->getArtisanCommand();
                $sections[] = "#### ".($isCliProvider ? $artisanCommand : $tool->name)."\n";
                $sections[] = $this->getToolInstructions($tool, $isCliProvider);
                $sections[] = $this->formatToolParameters($tool);
                $sections[] = "";
            }
        }

        return implode("\n", $sections);
    }

    /**
     * Build the memory section for the system prompt.
     * This is a separate top-level section (not nested under tools).
     *
     * TODO: Use $isCliProvider to conditionally format examples for non-CLI providers
     * (currently always generates CLI/artisan examples, which may not suit API providers)
     *
     * Uses buildMemoryPreviewHierarchy() as single source of truth, then flattens it.
     *
     * @param Agent|null $agent The agent to get schemas for
     * @param bool $isCliProvider Whether to use CLI examples (currently unused)
     */
    public function buildMemorySection(?Agent $agent, bool $isCliProvider = true): ?string
    {
        $hierarchy = $this->buildMemoryPreviewHierarchy($agent);

        if (!$hierarchy) {
            return null;
        }

        return $this->flattenHierarchy($hierarchy);
    }

    /**
     * Build hierarchical preview data for tools.
     * Returns nested structure: groups → categories → tools.
     *
     * @return array{title: string, content: string, children: array}
     */
    public function buildToolsPreviewHierarchy(
        string $provider,
        ?array $allowedTools = null,
        ?Workspace $workspace = null
    ): array {
        $tools = $this->getToolsForSystemPrompt($provider, $workspace);
        $tools = ToolFilterHelper::filterCollection($tools, $allowedTools);

        $providerEnum = Provider::tryFrom($provider);
        $isCliProvider = $providerEnum?->isCliProvider() ?? false;

        // Build preamble
        $preamble = "# PocketDev Tools\n\n";
        if ($isCliProvider) {
            $preamble .= "These PocketDev tools are invoked via PHP Artisan commands. Use your Bash tool to execute them.\n\n";
            $preamble .= $this->buildInvocationGuide();
        }

        $groupChildren = [];
        $groups = collect(config('tool-groups', []))->sortBy('sort_order');
        $allGroupedCategories = $groups->flatMap(fn($g) => $g['categories'] ?? [])->all();

        foreach ($groups as $groupKey => $group) {
            $groupCategories = $group['categories'] ?? [];
            $groupTools = $tools->filter(fn(Tool $t) => in_array($t->category, $groupCategories));
            $groupName = $group['name'] ?? ucfirst($groupKey);

            if ($groupTools->isNotEmpty()) {
                // Build tool children directly under group (no category level)
                $toolChildren = [];
                foreach ($groupTools->sortBy('name') as $tool) {
                    $toolTitle = $isCliProvider ? $tool->getArtisanCommand() : $tool->name;
                    $toolContent = "#### {$toolTitle}\n\n";
                    $toolContent .= $this->getToolInstructions($tool, $isCliProvider);
                    $toolContent .= $this->formatToolParameters($tool);

                    $toolChildren[] = [
                        'title' => $toolTitle,
                        'content' => $toolContent,
                        'source' => $tool->getSlug(),
                        'collapsed' => true,
                        'chars' => strlen($toolContent),
                        'estimated_tokens' => (int) ceil(strlen($toolContent) / 4),
                        'children' => [],
                    ];
                }

                // Build group - content includes header + system_prompt_active
                $groupContent = "## {$groupName}\n\n";
                if ($group['system_prompt_active'] ?? null) {
                    $groupContent .= $group['system_prompt_active'];
                }

                $groupChars = array_sum(array_column($toolChildren, 'chars')) + strlen($groupContent);

                $groupChildren[] = [
                    'title' => $groupName,
                    'content' => $groupContent,
                    'source' => $groupKey,
                    'collapsed' => true,
                    'chars' => $groupChars,
                    'estimated_tokens' => (int) ceil($groupChars / 4),
                    'children' => $toolChildren,
                ];
            } elseif ($group['system_prompt_inactive'] ?? null) {
                // Group has no enabled tools - show inactive message
                $groupContent = "## {$groupName}\n\n" . $group['system_prompt_inactive'];

                $groupChildren[] = [
                    'title' => $groupName . ' (disabled)',
                    'content' => $groupContent,
                    'source' => $groupKey,
                    'collapsed' => true,
                    'chars' => strlen($groupContent),
                    'estimated_tokens' => (int) ceil(strlen($groupContent) / 4),
                    'children' => [],
                ];
            }
        }

        // Handle ungrouped/custom tools
        $ungroupedTools = $tools->filter(fn(Tool $t) => !in_array($t->category, $allGroupedCategories));
        $customTools = $ungroupedTools->filter(fn(Tool $t) => $t instanceof UserTool);

        if ($customTools->isNotEmpty()) {
            $toolChildren = [];
            foreach ($customTools->sortBy('name') as $tool) {
                $toolTitle = $isCliProvider ? $tool->getArtisanCommand() : $tool->name;
                $toolContent = "#### {$toolTitle}\n\n";
                $toolContent .= $this->getToolInstructions($tool, $isCliProvider);
                $toolContent .= $this->formatToolParameters($tool);

                $toolChildren[] = [
                    'title' => $toolTitle,
                    'content' => $toolContent,
                    'source' => $tool->getSlug(),
                    'collapsed' => true,
                    'chars' => strlen($toolContent),
                    'estimated_tokens' => (int) ceil(strlen($toolContent) / 4),
                    'children' => [],
                ];
            }

            $groupContent = "## Custom Tools\n\nUser-created tools invoked via `tool:run`.";
            $childChars = array_sum(array_column($toolChildren, 'chars'));
            $childTokens = array_sum(array_column($toolChildren, 'estimated_tokens'));

            $groupChildren[] = [
                'title' => 'Custom Tools',
                'content' => $groupContent,
                'source' => 'custom',
                'collapsed' => true,
                'chars' => strlen($groupContent) + $childChars,
                'estimated_tokens' => (int) ceil(strlen($groupContent) / 4) + $childTokens,
                'children' => $toolChildren,
            ];
        }

        // Add system panels section
        if ($systemPanelsSection = $this->buildSystemPanelsSection($isCliProvider)) {
            $groupChildren[] = $systemPanelsSection;
        }

        $totalTokens = (int) ceil(strlen($preamble) / 4) + array_sum(array_column($groupChildren, 'estimated_tokens'));

        return [
            'title' => 'PocketDev Tools',
            'content' => $preamble,
            'source' => $allowedTools === null ? 'Dynamic (all tools)' : 'Dynamic (filtered)',
            'collapsed' => true,
            'chars' => strlen($preamble) + array_sum(array_column($groupChildren, 'chars')),
            'estimated_tokens' => $totalTokens,
            'children' => $groupChildren,
        ];
    }

    /**
     * Build hierarchical preview data for memory.
     * Returns nested structure: schemas → tables.
     */
    public function buildMemoryPreviewHierarchy(?Agent $agent): ?array
    {
        $schemas = $this->getAvailableSchemas($agent);

        if ($schemas->isEmpty()) {
            return null;
        }

        $schemaChildren = [];

        foreach ($schemas as $schema) {
            // Get tables for this schema using the service
            $memorySchemaService = app(MemorySchemaService::class);
            $memorySchemaService->setMemoryDatabase($schema);
            $tables = $memorySchemaService->listTables();
            $tableChildren = [];

            foreach ($tables as $table) {
                $tableName = $table['table_name'];
                $tableContent = "**{$tableName}**";
                if (!empty($table['description'])) {
                    $tableContent .= "\n\n{$table['description']}";
                }
                $tableContent .= "\n\n**Rows**: {$table['row_count']}";
                if (!empty($table['embeddable_fields'])) {
                    $tableContent .= "\n**Embed fields**: ".implode(', ', $table['embeddable_fields']);
                }

                $tableChildren[] = [
                    'title' => $tableName,
                    'content' => $tableContent,
                    'source' => "{$schema->schema_name}.{$tableName}",
                    'collapsed' => true,
                    'chars' => strlen($tableContent),
                    'estimated_tokens' => (int) ceil(strlen($tableContent) / 4),
                    'children' => [],
                ];
            }

            $schemaContent = "**{$schema->schema_name}**";
            if ($schema->description) {
                $schemaContent .= ": {$schema->description}";
            }
            $schemaContent .= "\n\n".count($tableChildren)." tables";

            $childChars = array_sum(array_column($tableChildren, 'chars'));
            $childTokens = array_sum(array_column($tableChildren, 'estimated_tokens'));

            $schemaChildren[] = [
                'title' => $schema->schema_name,
                'content' => $schemaContent,
                'source' => $schema->getFullSchemaName(),
                'collapsed' => true,
                'chars' => strlen($schemaContent) + $childChars,
                'estimated_tokens' => (int) ceil(strlen($schemaContent) / 4) + $childTokens,
                'children' => $tableChildren,
            ];
        }

        // Build intro content
        $introContent = "# Memory\n\n";
        $introContent .= "PocketDev provides a PostgreSQL-based memory system for persistent storage.\n\n";
        $introContent .= "## Schema Details\n\n";
        $introContent .= "**Naming**: Tables use `{schema}.tablename` format\n";
        $introContent .= "**System tables**: `schema_registry`, `embeddings`\n";
        $introContent .= "**Extensions**: PostGIS (spatial), pg_trgm (fuzzy text)\n\n";
        $introContent .= "Use `--schema=<name>` with all memory tools.";

        $totalTokens = (int) ceil(strlen($introContent) / 4) + array_sum(array_column($schemaChildren, 'estimated_tokens'));

        return [
            'title' => 'Memory',
            'content' => $introContent,
            'source' => 'Dynamic (based on selected schemas)',
            'collapsed' => true,
            'chars' => strlen($introContent) + array_sum(array_column($schemaChildren, 'chars')),
            'estimated_tokens' => $totalTokens,
            'children' => $schemaChildren,
        ];
    }

    /**
     * Build the available memory schemas section for the system prompt.
     * Shows which schemas the agent has access to.
     *
     * @param Agent|null $agent The agent to get schemas for
     * @deprecated Use buildMemorySection() instead
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

        $lines[] = "\nExample: `pd memory:query --schema={$schemas->first()->schema_name} --sql=\"SELECT * FROM {$schemas->first()->getFullSchemaName()}.schema_registry\"`";
        $lines[] = "";

        return implode("\n", $lines);
    }

    /**
     * Get available memory schemas for an agent.
     * If no agent, returns all schemas. If agent has workspace, filters by workspace then agent access.
     * Respects the agent's inherit_workspace_schemas setting.
     *
     * For preview agents (with pre-loaded memoryDatabases relation but no persisted ID),
     * returns the pre-loaded schemas directly.
     */
    public function getAvailableSchemas(?Agent $agent): Collection
    {
        if (!$agent) {
            // No agent context - return all schemas
            return MemoryDatabase::orderBy('name')->get();
        }

        // For preview agents (no ID means not persisted), use pre-loaded relation
        if (!$agent->exists && $agent->relationLoaded('memoryDatabases')) {
            return $agent->memoryDatabases->sortBy('name')->values();
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

Use the `pd` command (PocketDev wrapper) to run artisan commands from any directory:

**Built-in commands (memory, tool management):**
```bash
pd memory:query --schema=default --sql="SELECT * FROM memory_default.schema_registry"
pd memory:insert --schema=default --table=example --data='{"name":"Test"}'
pd tool:list
```

**User-created tools:**
```bash
pd tool:run <slug> -- --arg1=value1 --arg2=value2
```

**Important:** Only `tool:run` requires the `--` separator before tool arguments.

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

    /**
     * Build the skills section for the system prompt.
     * Skills are slash commands that can be invoked via /name.
     *
     * @param Agent|null $agent The agent to get skills for
     */
    public function buildSkillsSection(?Agent $agent): ?string
    {
        $hierarchy = $this->buildSkillsPreviewHierarchy($agent);

        if (!$hierarchy) {
            return null;
        }

        return $this->flattenHierarchy($hierarchy);
    }

    /**
     * Build hierarchical preview data for skills.
     * Returns nested structure for preview display.
     */
    public function buildSkillsPreviewHierarchy(?Agent $agent): ?array
    {
        $schemas = $this->getAvailableSchemas($agent);

        if ($schemas->isEmpty()) {
            return null;
        }

        // Gather skills grouped by schema
        $skillsBySchema = [];

        foreach ($schemas as $schema) {
            $fullSchemaName = $schema->getFullSchemaName();

            try {
                $skills = \Illuminate\Support\Facades\DB::connection('pgsql_readonly')
                    ->table("{$fullSchemaName}.skills")
                    ->select(['name', 'when_to_use'])
                    ->orderBy('name')
                    ->get();

                if ($skills->isNotEmpty()) {
                    $skillsBySchema[$schema->schema_name] = [
                        'schema' => $schema,
                        'skills' => $skills,
                    ];
                }
            } catch (\Illuminate\Database\QueryException $e) {
                $msg = $e->getMessage();
                // Missing skills table in older schemas - skip silently
                if (str_contains($msg, 'relation') && str_contains($msg, 'skills')) {
                    continue;
                }
                // Log unexpected DB errors
                \Illuminate\Support\Facades\Log::warning('Failed to load skills for prompt', [
                    'schema' => $schema->schema_name,
                    'error' => $msg,
                ]);
                continue;
            }
        }

        if (empty($skillsBySchema)) {
            return null;
        }

        // Build schema children, each containing skill children
        $schemaChildren = [];
        foreach ($skillsBySchema as $schemaName => $data) {
            $schema = $data['schema'];
            $skills = $data['skills'];
            $fullSchemaName = $schema->getFullSchemaName();

            // Build skill children for this schema
            $skillChildren = [];
            foreach ($skills as $skill) {
                $whenToUse = $skill->when_to_use ?? '(not specified)';
                $skillContent = "- name: {$skill->name}, when_to_use: {$whenToUse}";

                $skillChildren[] = [
                    'title' => $skill->name,
                    'content' => $skillContent,
                    'source' => "{$schemaName}.skills",
                    'collapsed' => true,
                    'chars' => strlen($skillContent),
                    'estimated_tokens' => (int) ceil(strlen($skillContent) / 4),
                    'children' => [],
                ];
            }

            // Build schema group with example command
            $schemaContent = "**Schema `{$schemaName}`** (query with `--schema={$schemaName}`):";

            $childChars = array_sum(array_column($skillChildren, 'chars'));
            $childTokens = array_sum(array_column($skillChildren, 'estimated_tokens'));

            $schemaChildren[] = [
                'title' => "Schema: {$schemaName}",
                'content' => $schemaContent,
                'source' => $fullSchemaName,
                'collapsed' => true,
                'chars' => strlen($schemaContent) + $childChars,
                'estimated_tokens' => (int) ceil(strlen($schemaContent) / 4) + $childTokens,
                'children' => $skillChildren,
            ];
        }

        // Build intro content
        $introContent = "# Skills\n\n";
        $introContent .= "PocketDev Skills should **always** be retrieved by querying the `skills` table in memory, matching by exact `name`.\n";
        $introContent .= "Use the Bash tool to run the artisan command:\n";
        $introContent .= "```bash\npd memory:query --schema=<schema> --sql=\"SELECT instructions FROM memory_<schema>.skills WHERE name = 'skill-name'\"\n```\n\n";
        $introContent .= "Replace `<schema>` with the schema name indicated for each skill below.\n\n";
        $introContent .= "Available skills:";

        $totalChars = strlen($introContent) + array_sum(array_column($schemaChildren, 'chars'));
        $totalTokens = (int) ceil(strlen($introContent) / 4) + array_sum(array_column($schemaChildren, 'estimated_tokens'));

        return [
            'title' => 'Skills',
            'content' => $introContent,
            'source' => 'Dynamic (from memory schemas)',
            'collapsed' => true,
            'chars' => $totalChars,
            'estimated_tokens' => $totalTokens,
            'children' => $schemaChildren,
        ];
    }

    /**
     * Get a specific skill by name from available schemas.
     * Returns the full skill content if found.
     *
     * @param Agent|null $agent The agent context
     * @param string $skillName The skill name to look up
     * @return array|null The skill data or null if not found
     */
    public function getSkillByName(?Agent $agent, string $skillName): ?array
    {
        $schemas = $this->getAvailableSchemas($agent);

        foreach ($schemas as $schema) {
            $fullSchemaName = $schema->getFullSchemaName();

            try {
                $skill = \Illuminate\Support\Facades\DB::connection('pgsql_readonly')
                    ->table("{$fullSchemaName}.skills")
                    ->where('name', $skillName)
                    ->first();

                if ($skill) {
                    return [
                        'name' => $skill->name,
                        'when_to_use' => $skill->when_to_use,
                        'instructions' => $skill->instructions,
                        'schema' => $schema->schema_name,
                    ];
                }
            } catch (\Illuminate\Database\QueryException $e) {
                $msg = $e->getMessage();
                // Missing skills table in older schemas - skip silently
                if (str_contains($msg, 'relation') && str_contains($msg, 'skills')) {
                    continue;
                }
                // Log unexpected DB errors
                \Illuminate\Support\Facades\Log::warning('Failed to get skill by name', [
                    'schema' => $schema->schema_name,
                    'skill' => $skillName,
                    'error' => $msg,
                ]);
                continue;
            }
        }

        return null;
    }

    /**
     * Get all skills from available schemas.
     *
     * @param Agent|null $agent The agent context
     * @return Collection Collection of skill arrays
     */
    public function getAllSkills(?Agent $agent): Collection
    {
        $schemas = $this->getAvailableSchemas($agent);
        $allSkills = collect();

        foreach ($schemas as $schema) {
            $fullSchemaName = $schema->getFullSchemaName();

            try {
                $skills = \Illuminate\Support\Facades\DB::connection('pgsql_readonly')
                    ->table("{$fullSchemaName}.skills")
                    ->select(['name', 'when_to_use', 'instructions'])
                    ->orderBy('name')
                    ->get();

                foreach ($skills as $skill) {
                    $allSkills->push([
                        'name' => $skill->name,
                        'when_to_use' => $skill->when_to_use,
                        'instructions' => $skill->instructions,
                        'schema' => $schema->schema_name,
                    ]);
                }
            } catch (\Illuminate\Database\QueryException $e) {
                $msg = $e->getMessage();
                // Missing skills table in older schemas - skip silently
                if (str_contains($msg, 'relation') && str_contains($msg, 'skills')) {
                    continue;
                }
                // Log unexpected DB errors
                \Illuminate\Support\Facades\Log::warning('Failed to get all skills', [
                    'schema' => $schema->schema_name,
                    'error' => $msg,
                ]);
                continue;
            }
        }

        return $allSkills;
    }

    /**
     * Build the system panels section for the tools hierarchy.
     * Returns a group node containing all system panels from PanelRegistry.
     *
     * @param bool $isCliProvider Whether to format for CLI (artisan commands)
     * @return array|null The section node or null if no system panels
     */
    private function buildSystemPanelsSection(bool $isCliProvider): ?array
    {
        $systemPanels = $this->panelRegistry->all();

        if (empty($systemPanels)) {
            return null;
        }

        $panelChildren = [];
        foreach ($systemPanels as $panel) {
            $toolTitle = $isCliProvider ? "tool:run {$panel->slug}" : $panel->name;
            $toolContent = "#### {$toolTitle}\n\n";
            $toolContent .= $panel->getSystemPrompt();

            $panelChildren[] = [
                'title' => $toolTitle,
                'content' => $toolContent,
                'source' => "system-panel:{$panel->slug}",
                'collapsed' => true,
                'chars' => strlen($toolContent),
                'estimated_tokens' => (int) ceil(strlen($toolContent) / 4),
                'children' => [],
            ];
        }

        $groupContent = "## System Panels\n\nBuilt-in interactive UI panels. Use `pd panel:peek <slug>` to see current visible state.";
        $childChars = array_sum(array_column($panelChildren, 'chars'));
        $childTokens = array_sum(array_column($panelChildren, 'estimated_tokens'));

        return [
            'title' => 'System Panels',
            'content' => $groupContent,
            'source' => 'system-panels',
            'collapsed' => true,
            'chars' => strlen($groupContent) + $childChars,
            'estimated_tokens' => (int) ceil(strlen($groupContent) / 4) + $childTokens,
            'children' => $panelChildren,
        ];
    }
}
