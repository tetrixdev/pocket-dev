<?php

namespace App\Services;

use App\Models\PocketTool;
use App\Tools\ExecutionContext;
use App\Tools\Tool;
use App\Tools\ToolResult;
use App\Tools\UserTool;
use Illuminate\Support\Facades\Log;

/**
 * Registry for available tools.
 * Manages tool registration, lookup, and execution.
 *
 * Built-in tools (from app/Tools) are cached in the singleton.
 * User tools (from database) are always fetched fresh to ensure
 * newly created tools are available without restarting the queue worker.
 */
class ToolRegistry
{
    /** @var array<string, Tool> Built-in tools (cached) */
    private array $builtInTools = [];

    /**
     * Register a built-in tool.
     */
    public function register(Tool $tool): void
    {
        $this->builtInTools[$tool->name] = $tool;
    }

    /**
     * Get fresh user tools from the database.
     *
     * @return array<string, Tool>
     */
    private function getUserTools(): array
    {
        $userTools = [];

        try {
            $pocketTools = PocketTool::user()->enabled()->get();

            foreach ($pocketTools as $pocketTool) {
                $userTool = new UserTool($pocketTool);
                $userTools[$userTool->name] = $userTool;
            }
        } catch (\Throwable $e) {
            // Database might not be available during some scenarios
            Log::debug('ToolRegistry: Could not load user tools', [
                'error' => $e->getMessage(),
            ]);
        }

        return $userTools;
    }

    /**
     * Get a tool by name.
     * Checks built-in tools first, then user tools.
     */
    public function get(string $name): ?Tool
    {
        // Check built-in tools first (cached)
        if (isset($this->builtInTools[$name])) {
            return $this->builtInTools[$name];
        }

        // Check user tools (fresh from DB)
        $userTools = $this->getUserTools();
        return $userTools[$name] ?? null;
    }

    /**
     * Check if a tool exists.
     */
    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Get all registered tools (built-in + fresh user tools).
     *
     * @return array<string, Tool>
     */
    public function all(): array
    {
        return array_merge($this->builtInTools, $this->getUserTools());
    }

    /**
     * Get tool definitions for the API.
     * Returns array of tool definition objects.
     *
     * @return array<array{name: string, description: string, input_schema: array}>
     */
    public function getDefinitions(): array
    {
        return array_map(
            fn(Tool $tool) => $tool->toDefinition(),
            array_values($this->all())
        );
    }

    /**
     * Get combined instructions from all tools.
     * Only includes tools that have instructions.
     *
     * @param array|null $allowedTools Tool slugs to allow (null = all, [] = none, [...] = specific)
     */
    public function getInstructions(?array $allowedTools = null): string
    {
        $instructions = [];
        $allowedSlugs = $allowedTools !== null
            ? array_map('strtolower', $allowedTools)
            : null;

        foreach ($this->all() as $tool) {
            if ($tool->instructions === null) {
                continue;
            }

            // Filter by allowed tools if specified (case-insensitive)
            if ($allowedSlugs !== null) {
                if (!in_array(strtolower($tool->getSlug()), $allowedSlugs, true)) {
                    continue;
                }
            }

            $instructions[] = "## {$tool->name} Tool\n\n{$tool->instructions}";
        }

        return implode("\n\n", $instructions);
    }

    /**
     * Execute a tool by name.
     */
    public function execute(string $name, array $input, ExecutionContext $context): ToolResult
    {
        $tool = $this->get($name);

        if ($tool === null) {
            return ToolResult::error("Unknown tool: {$name}");
        }

        try {
            return $tool->execute($input, $context);
        } catch (\Throwable $e) {
            return ToolResult::error("Tool execution failed: {$e->getMessage()}");
        }
    }

    /**
     * Get the count of registered tools.
     */
    public function count(): int
    {
        return count($this->all());
    }
}
