<?php

namespace App\Tools;

/**
 * Abstract base class for all tools.
 * Tools use public properties instead of getter methods.
 */
abstract class Tool
{
    /** Tool name (matches API tool_use.name) */
    public string $name;

    /** Brief description for API tools array */
    public string $description;

    /** JSON Schema for input parameters */
    public array $inputSchema;

    /** Detailed instructions for system prompt (optional) */
    public ?string $instructions = null;

    /**
     * Execute the tool with the given input.
     *
     * @param array $input The tool input parameters
     * @param ExecutionContext $context Execution context (working directory, etc.)
     * @return ToolResult The result of the tool execution
     */
    abstract public function execute(array $input, ExecutionContext $context): ToolResult;

    /**
     * Convert to API tool definition format.
     */
    public function toDefinition(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];
    }
}
