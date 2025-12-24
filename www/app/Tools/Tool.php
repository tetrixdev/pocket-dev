<?php

namespace App\Tools;

use Illuminate\Support\Str;

/**
 * Abstract base class for all tools.
 * Tools use public properties instead of getter methods.
 *
 * Tool classes are the single source of truth for tool definitions.
 * They are auto-discovered from the app/Tools directory.
 */
abstract class Tool
{
    /** Tool name (matches API tool_use.name) */
    public string $name;

    /** Brief description for API tools array */
    public string $description;

    /** JSON Schema for input parameters */
    public array $inputSchema = [];

    /** Detailed instructions for system prompt (what Claude sees) */
    public ?string $instructions = null;

    /** Tool category for grouping: memory, tools, file_ops, custom */
    public string $category = 'custom';

    /**
     * Execute the tool with the given input.
     *
     * @param array $input The tool input parameters
     * @param ExecutionContext $context Execution context (working directory, etc.)
     * @return ToolResult The result of the tool execution
     */
    abstract public function execute(array $input, ExecutionContext $context): ToolResult;

    /**
     * Get URL/CLI-friendly slug derived from name.
     */
    public function getSlug(): string
    {
        return Str::slug(Str::snake($this->name), '-');
    }

    /**
     * Get the artisan command equivalent for CLI providers.
     */
    public function getArtisanCommand(): ?string
    {
        // Override in subclasses that have artisan command equivalents
        return null;
    }

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

    /**
     * Convert to array for UI/API responses.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->getSlug(),
            'description' => $this->description,
            'category' => $this->category,
            'instructions' => $this->instructions,
            'input_schema' => $this->inputSchema,
        ];
    }
}
