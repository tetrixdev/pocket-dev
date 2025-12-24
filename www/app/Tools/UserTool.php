<?php

namespace App\Tools;

use App\Models\PocketTool;
use Illuminate\Support\Facades\Process;

/**
 * Wrapper class that makes PocketTool database records behave like Tool classes.
 *
 * This allows user-created tools (stored in the database) to be used
 * interchangeably with built-in Tool classes through the ToolRegistry.
 */
class UserTool extends Tool
{
    public string $name;

    public string $description;

    public array $inputSchema = [];

    public ?string $instructions = null;

    public string $category = 'custom';

    private PocketTool $pocketTool;

    /**
     * Create a UserTool from a PocketTool model.
     */
    public function __construct(PocketTool $pocketTool)
    {
        $this->pocketTool = $pocketTool;

        // Map PocketTool properties to Tool properties
        // Use slug as name for API compatibility (OpenAI requires ^[a-zA-Z0-9_-]+$)
        $this->name = $this->pocketTool->slug;
        $this->description = $this->pocketTool->description;
        $this->instructions = $this->pocketTool->system_prompt;
        $this->category = $this->pocketTool->category ?? 'custom';

        // Handle input_schema
        if ($this->pocketTool->input_schema) {
            $this->inputSchema = $this->pocketTool->input_schema;
        } else {
            // Default schema for tools without defined input
            $this->inputSchema = [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ];
        }
    }

    /**
     * Get the underlying PocketTool model.
     */
    public function getPocketTool(): PocketTool
    {
        return $this->pocketTool;
    }

    /**
     * Get URL/CLI-friendly slug.
     */
    public function getSlug(): string
    {
        return $this->pocketTool->slug;
    }

    /**
     * Get the human-readable display name.
     */
    public function getDisplayName(): string
    {
        return $this->pocketTool->name;
    }

    /**
     * Execute the user tool by running its bash script.
     */
    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        if (!$this->pocketTool->hasScript()) {
            return ToolResult::error("Tool '{$this->pocketTool->slug}' has no script defined");
        }

        // Validate required parameters if tool has input_schema
        if ($this->pocketTool->input_schema && !empty($this->pocketTool->input_schema['properties'])) {
            $schema = $this->pocketTool->input_schema;
            $required = $schema['required'] ?? [];

            foreach ($required as $param) {
                if (!isset($input[$param])) {
                    return ToolResult::error("Missing required parameter: {$param}");
                }
            }
        }

        // Build environment variables from input (uppercase with TOOL_ prefix)
        $envVars = [];
        foreach ($input as $name => $value) {
            $envName = 'TOOL_' . strtoupper(str_replace('-', '_', $name));
            $envVars[$envName] = is_string($value) ? $value : json_encode($value);
        }

        $tempFile = null;
        try {
            // Create temp script file
            $tempFile = tempnam(sys_get_temp_dir(), 'pocket_tool_');
            if ($tempFile === false) {
                return ToolResult::error('Failed to create temporary script file');
            }
            file_put_contents($tempFile, $this->pocketTool->script);
            chmod($tempFile, 0755);

            // Execute the script with environment variables
            $result = Process::timeout(300)->env($envVars)->path($context->workingDirectory)->run($tempFile);

            $output = $result->output();
            $errorOutput = $result->errorOutput();

            if ($result->failed()) {
                $message = $errorOutput ?: $output ?: 'Script execution failed with exit code ' . $result->exitCode();
                return ToolResult::error($message);
            }

            return ToolResult::success(trim($output));
        } catch (\Exception $e) {
            return ToolResult::error('Failed to run tool: ' . $e->getMessage());
        } finally {
            // Clean up temp file
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Get the artisan command equivalent.
     */
    public function getArtisanCommand(): ?string
    {
        return "tool:run {$this->pocketTool->slug}";
    }

    /**
     * Check if the underlying tool is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->pocketTool->enabled;
    }

    /**
     * Convert to array for UI/API responses.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'display_name' => $this->getDisplayName(),
            'slug' => $this->getSlug(),
            'description' => $this->description,
            'category' => $this->category,
            'instructions' => $this->instructions,
            'input_schema' => $this->inputSchema,
            'source' => 'user',
            'enabled' => $this->pocketTool->enabled,
        ];
    }
}
