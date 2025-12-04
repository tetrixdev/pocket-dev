<?php

namespace App\Services;

use App\Tools\ExecutionContext;
use App\Tools\Tool;
use App\Tools\ToolResult;

/**
 * Registry for available tools.
 * Manages tool registration, lookup, and execution.
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * Register a tool.
     */
    public function register(Tool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    /**
     * Get a tool by name.
     */
    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Check if a tool exists.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Get all registered tools.
     *
     * @return array<string, Tool>
     */
    public function all(): array
    {
        return $this->tools;
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
            array_values($this->tools)
        );
    }

    /**
     * Get combined instructions from all tools.
     * Only includes tools that have instructions.
     */
    public function getInstructions(): string
    {
        $instructions = [];

        foreach ($this->tools as $tool) {
            if ($tool->instructions !== null) {
                $instructions[] = "## {$tool->name} Tool\n\n{$tool->instructions}";
            }
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
        return count($this->tools);
    }
}
