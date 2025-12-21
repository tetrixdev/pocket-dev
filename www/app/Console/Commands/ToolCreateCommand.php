<?php

namespace App\Console\Commands;

use App\Models\PocketTool;
use Illuminate\Console\Command;

class ToolCreateCommand extends Command
{
    protected $signature = 'tool:create
        {--slug= : Unique identifier for the tool}
        {--name= : Display name for the tool}
        {--description= : Short description of what the tool does}
        {--system-prompt= : Detailed instructions for AI (added to system prompt when enabled)}
        {--script= : Bash script content}
        {--script-file= : Path to file containing bash script}
        {--category=custom : Tool category}
        {--input-schema= : JSON Schema for input parameters}
        {--disabled : Create the tool in disabled state}';

    protected $description = 'Create a new user tool';

    public function handle(): int
    {
        $slug = $this->option('slug');
        $name = $this->option('name');
        $description = $this->option('description');
        $systemPrompt = $this->option('system-prompt');
        $script = $this->option('script');
        $scriptFile = $this->option('script-file');
        $category = $this->option('category');
        $inputSchema = $this->option('input-schema');
        $disabled = $this->option('disabled');

        // Validation
        if (empty($slug)) {
            return $this->outputError('The --slug option is required');
        }

        if (empty($name)) {
            return $this->outputError('The --name option is required');
        }

        if (empty($description)) {
            return $this->outputError('The --description option is required');
        }

        if (empty($systemPrompt)) {
            return $this->outputError('The --system-prompt option is required');
        }

        // Get script from file if provided
        if ($scriptFile) {
            if (!file_exists($scriptFile)) {
                return $this->outputError("Script file not found: {$scriptFile}");
            }
            $script = file_get_contents($scriptFile);
        }

        if (empty($script)) {
            return $this->outputError('Either --script or --script-file is required');
        }

        // Check for duplicate slug
        if (PocketTool::where('slug', $slug)->exists()) {
            return $this->outputError("A tool with slug '{$slug}' already exists");
        }

        // Parse input schema if provided
        $parsedInputSchema = null;
        if ($inputSchema) {
            $parsedInputSchema = json_decode($inputSchema, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->outputError('Invalid JSON in --input-schema: ' . json_last_error_msg());
            }
        }

        try {
            $tool = PocketTool::create([
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'system_prompt' => $systemPrompt,
                'script' => $script,
                'source' => PocketTool::SOURCE_USER,
                'category' => $category,
                'capability' => PocketTool::CAPABILITY_CUSTOM,
                'enabled' => !$disabled,
                'input_schema' => $parsedInputSchema,
            ]);

            $this->outputResult([
                'output' => "Created tool: {$name} ({$slug})\nID: {$tool->id}\nEnabled: " . ($tool->enabled ? 'yes' : 'no'),
                'is_error' => false,
                'tool' => [
                    'id' => $tool->id,
                    'slug' => $tool->slug,
                    'name' => $tool->name,
                    'enabled' => $tool->enabled,
                ],
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->outputError('Failed to create tool: ' . $e->getMessage());
        }
    }

    private function outputError(string $message): int
    {
        $this->output->writeln(json_encode([
            'output' => $message,
            'is_error' => true,
        ], JSON_PRETTY_PRINT));

        return Command::FAILURE;
    }

    private function outputResult(array $result): void
    {
        $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT));
    }
}
