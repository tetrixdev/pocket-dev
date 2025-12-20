<?php

namespace App\Services;

use App\Models\PocketTool;
use App\Models\ToolConflict;
use Illuminate\Support\Collection;

class ToolSelector
{
    /**
     * Get all enabled tools available for a specific provider.
     */
    public function getAvailableTools(string $provider): Collection
    {
        return PocketTool::enabled()
            ->forProvider($provider)
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get default tools for a provider (for new conversations).
     * For Claude Code: excludes tools with native equivalents.
     */
    public function getDefaultTools(string $provider): Collection
    {
        $tools = $this->getAvailableTools($provider);

        // For Claude Code, exclude tools that have native equivalents
        if ($provider === PocketTool::PROVIDER_CLAUDE_CODE) {
            return $tools->filter(fn(PocketTool $tool) => !$tool->hasNativeEquivalent());
        }

        return $tools;
    }

    /**
     * Get tools for system prompt injection (Claude Code).
     * Returns only PocketDev-exclusive tools (no native equivalents).
     */
    public function getToolsForSystemPrompt(string $provider): Collection
    {
        return PocketTool::enabled()
            ->forProvider($provider)
            ->noNativeEquivalent()
            ->orderBy('category')
            ->orderBy('name')
            ->get();
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
        return PocketTool::enabled()
            ->category(PocketTool::CATEGORY_MEMORY)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get tool management tools.
     */
    public function getToolManagementTools(): Collection
    {
        return PocketTool::enabled()
            ->category(PocketTool::CATEGORY_TOOLS)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get user-created tools.
     */
    public function getUserTools(): Collection
    {
        return PocketTool::enabled()
            ->user()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get file operation tools (for Anthropic/OpenAI only).
     */
    public function getFileOperationTools(): Collection
    {
        return PocketTool::enabled()
            ->category(PocketTool::CATEGORY_FILE_OPS)
            ->orderBy('name')
            ->get();
    }

    /**
     * Build system prompt section for PocketDev tools.
     */
    public function buildSystemPrompt(string $provider): string
    {
        $tools = $this->getToolsForSystemPrompt($provider);

        if ($tools->isEmpty()) {
            return '';
        }

        $sections = ["# PocketDev Tools\n"];

        // Group by category
        $grouped = $tools->groupBy('category');

        foreach ($grouped as $category => $categoryTools) {
            $categoryTitle = $this->formatCategoryTitle($category);
            $sections[] = "## {$categoryTitle}\n";

            foreach ($categoryTools as $tool) {
                $sections[] = "### {$tool->name}\n";
                $sections[] = $tool->system_prompt;
                $sections[] = "";
            }
        }

        return implode("\n", $sections);
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
}
