<?php

namespace App\Tools;

use App\Contracts\ToolInterface;

/**
 * Abstract base class for all tools.
 * Tools use public properties instead of getter methods.
 */
abstract class Tool implements ToolInterface
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
