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
     * Get memory tools.
     */
    public function getMemoryTools(): Collection
    {
        return $this->getAllTools()
            ->filter(fn(Tool $tool) => $tool->category === PocketTool::CATEGORY_MEMORY)
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

        if ($isCliProvider) {
            $systemTools = $tools->filter(fn(Tool $tool) => !($tool instanceof UserTool));
            $customTools = $tools->filter(fn(Tool $tool) => $tool instanceof UserTool);

            if ($systemTools->isNotEmpty()) {
                $sections[] = "## System Tools\n";
                $sections[] = "Built-in PocketDev tools.\n";

                foreach ($systemTools as $tool) {
                    $artisanCommand = $tool->getArtisanCommand();
                    $sections[] = "### {$artisanCommand}\n";
                    $sections[] = $this->getToolInstructions($tool, true);
                    $sections[] = $this->formatToolParameters($tool);
                    $sections[] = "";
                }
            }

            if ($customTools->isNotEmpty()) {
                $sections[] = "## Custom Tools\n";
                $sections[] = "User-created tools invoked via `tool:run`.\n";

                foreach ($customTools as $tool) {
                    $artisanCommand = $tool->getArtisanCommand();
                    $sections[] = "### {$artisanCommand}\n";
                    $sections[] = $this->getToolInstructions($tool, true);
                    $sections[] = $this->formatToolParameters($tool);
                    $sections[] = "";
                }
            }
        } else {
            // Group by category for API providers
            $grouped = $tools->groupBy('category');

            foreach ($grouped as $category => $categoryTools) {
                $categoryTitle = $this->formatCategoryTitle($category);
                $sections[] = "## {$categoryTitle}\n";

                foreach ($categoryTools as $tool) {
                    $sections[] = "### {$tool->name}\n";
                    $sections[] = $this->getToolInstructions($tool, false);
                    $sections[] = $this->formatToolParameters($tool);
                    $sections[] = "";
                }
            }
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
     */
    private function getAvailableSchemas(?Agent $agent): Collection
    {
        if (!$agent) {
            // No agent context - return all schemas
            return MemoryDatabase::orderBy('name')->get();
        }

        // Get schemas the agent has explicitly enabled
        $agentSchemas = $agent->memoryDatabases()->orderBy('name')->get();

        // If agent has no schemas selected, return empty (explicit opt-in required)
        return $agentSchemas;
    }

    /**
     * Get category-level instructions (shared guidance for a category).
     */
    private function getCategoryInstructions(string $category): ?string
    {
        return match ($category) {
            PocketTool::CATEGORY_MEMORY_SCHEMA => $this->getMemorySchemaInstructions(),
            default => null,
        };
    }

    /**
     * Get shared instructions for memory_schema category tools.
     */
    private function getMemorySchemaInstructions(): string
    {
        return <<<'MD'
### Schema Change Guidelines

**ALTER TABLE is supported** for non-protected tables (add/drop/rename columns, rename tables).

For table renames, also update related metadata:
1. Rename table: `ALTER TABLE memory.old_name RENAME TO new_name`
2. Update embeddings: `UPDATE memory.embeddings SET source_table = 'new_name' WHERE source_table = 'old_name'`
3. Update registry: `memory:update --table=schema_registry --data='{"table_name":"new_name"}' --where="table_name = 'old_name'"`

**Protected tables** (embeddings, schema_registry) cannot be altered.

### Extensions Available

**PostGIS (Spatial):**
```sql
coordinates GEOGRAPHY(Point, 4326)
ST_DWithin(coordinates, ST_MakePoint(-122.4, 37.8)::geography, 50000)
```

**pg_trgm (Fuzzy Text):**
```sql
CREATE INDEX idx_name_trgm ON memory.table USING GIN (name gin_trgm_ops);
WHERE name % 'Gandolf'  -- Finds "Gandalf"
```
MD;
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
