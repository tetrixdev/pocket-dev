<?php

namespace App\Services;

use App\Enums\Provider;
use App\Models\MemoryStructure;
use App\Models\PocketTool;
use App\Models\ToolConflict;
use App\Tools\Tool;
use App\Tools\UserTool;
use Illuminate\Support\Collection;

/**
 * ToolSelector provides methods for selecting and filtering tools.
 *
 * It uses the ToolRegistry (which auto-discovers Tool classes and wraps
 * user tools from the database) as the source of truth.
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
     * Get all tools available for a specific provider.
     * All tools are available for all providers - filtering is done in getDefaultTools().
     */
    public function getAvailableTools(string $provider): Collection
    {
        return $this->getAllTools()
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
    public function getDefaultTools(string $provider): Collection
    {
        $tools = $this->getAvailableTools($provider);

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
    public function getToolsForSystemPrompt(string $provider): Collection
    {
        $providerEnum = Provider::tryFrom($provider);

        // For CLI providers, exclude file_ops since they have native equivalents
        if ($providerEnum?->isCliProvider()) {
            return $this->getAvailableTools($provider)
                ->filter(fn(Tool $tool) => $tool->category !== PocketTool::CATEGORY_FILE_OPS);
        }

        return $this->getAvailableTools($provider);
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
     */
    public function buildSystemPrompt(string $provider): string
    {
        $tools = $this->getToolsForSystemPrompt($provider);

        if ($tools->isEmpty()) {
            return '';
        }

        $sections = [];
        $providerEnum = Provider::tryFrom($provider);
        $isCliProvider = $providerEnum?->isCliProvider() ?? false;

        // Add preamble for CLI providers explaining these are CLI commands
        if ($isCliProvider) {
            $sections[] = "# PocketDev Tools\n";
            $sections[] = "The following tools are available as artisan commands. Use your Bash tool to execute them.\n";
            $sections[] = $this->buildInvocationGuide();
        } else {
            $sections[] = "# PocketDev Tools\n";
        }

        // Add available memory structures
        $structuresSection = $this->buildStructuresSection();
        if ($structuresSection) {
            $sections[] = $structuresSection;
        }

        // Group by category
        $grouped = $tools->groupBy('category');

        foreach ($grouped as $category => $categoryTools) {
            $categoryTitle = $this->formatCategoryTitle($category);
            $sections[] = "## {$categoryTitle}\n";

            foreach ($categoryTools as $tool) {
                // Use artisan command format for CLI providers, tool name for others
                if ($isCliProvider) {
                    $artisanCommand = $tool->getArtisanCommand();
                    $sections[] = "### {$artisanCommand}\n";
                } else {
                    $sections[] = "### {$tool->name}\n";
                }
                $sections[] = $tool->instructions ?? $tool->description;
                $sections[] = $this->formatToolParameters($tool);
                $sections[] = "";
            }
        }

        return implode("\n", $sections);
    }

    /**
     * Build the memory structures section for the system prompt.
     */
    private function buildStructuresSection(): ?string
    {
        $structures = MemoryStructure::orderBy('name')->get();

        if ($structures->isEmpty()) {
            return null;
        }

        $lines = [];
        $lines[] = "## Available Memory Structures\n";
        $lines[] = "The following memory structures are defined. Use `memory:query` to list objects or `memory:create` to create new ones.\n";
        $lines[] = "Store relationships between objects as UUID fields in the data (e.g., `owner_id`, `location_id`).\n";

        foreach ($structures as $structure) {
            $lines[] = "### {$structure->name} (`{$structure->slug}`)";
            if ($structure->description) {
                $lines[] = $structure->description;
            }

            // Extract field descriptions from schema
            $schema = $structure->schema;
            if (isset($schema['properties']) && is_array($schema['properties'])) {
                $lines[] = "\n**Fields:**";
                foreach ($schema['properties'] as $fieldName => $fieldDef) {
                    $type = $fieldDef['type'] ?? 'any';
                    $description = $fieldDef['description'] ?? '';
                    $embed = isset($fieldDef['x-embed']) && $fieldDef['x-embed'] ? ' [embeddable]' : '';
                    $format = isset($fieldDef['format']) ? " ({$fieldDef['format']})" : '';

                    if ($description) {
                        $lines[] = "- `{$fieldName}` ({$type}{$format}){$embed}: {$description}";
                    } else {
                        $lines[] = "- `{$fieldName}` ({$type}{$format}){$embed}";
                    }
                }
            }
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    /**
     * Format category name for display.
     */
    private function formatCategoryTitle(string $category): string
    {
        return match ($category) {
            PocketTool::CATEGORY_MEMORY => 'Memory System',
            PocketTool::CATEGORY_TOOLS => 'Tool Management',
            PocketTool::CATEGORY_FILE_OPS => 'File Operations',
            PocketTool::CATEGORY_CUSTOM => 'Custom Tools',
            default => ucfirst(str_replace('_', ' ', $category)),
        };
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
php artisan memory:query --sql="SELECT id, name FROM memory_structures" --
php artisan memory:create --structure=project --name="My Project" --data='{"status":"active"}' --
php artisan tool:list --
```

**User-created tools:**
```bash
php artisan tool:run <slug> -- --arg1=value1 --arg2=value2
```

**Important:** Always include a `--` separator in PocketDev artisan commands. For `tool:run`, it goes before tool arguments; for all other commands, put it at the end. Commands are considered invalid without it.

GUIDE;
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
