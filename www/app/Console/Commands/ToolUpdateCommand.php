<?php

namespace App\Console\Commands;

use App\Models\PocketTool;
use Illuminate\Console\Command;

class ToolUpdateCommand extends Command
{
    protected $signature = 'tool:update
        {slug : The slug of the tool to update}
        {--name= : New display name}
        {--description= : New description}
        {--system-prompt= : New system prompt instructions}
        {--script= : New bash script content}
        {--script-file= : Path to file containing new bash script}
        {--category= : New category}
        {--input-schema= : New JSON Schema for input parameters}';

    protected $description = 'Update an existing user tool';

    public function handle(): int
    {
        $slug = $this->argument('slug');

        $tool = PocketTool::where('slug', $slug)->first();

        if (!$tool) {
            return $this->outputError("Tool '{$slug}' not found");
        }

        if ($tool->isPocketdev()) {
            return $this->outputError("Cannot modify PocketDev tool '{$slug}'. Only user-created tools can be modified.");
        }

        $changes = [];

        if ($this->option('name') !== null) {
            $tool->name = $this->option('name');
            $changes[] = 'name';
        }

        if ($this->option('description') !== null) {
            $tool->description = $this->option('description');
            $changes[] = 'description';
        }

        if ($this->option('system-prompt') !== null) {
            $tool->system_prompt = $this->option('system-prompt');
            $changes[] = 'system_prompt';
        }

        if ($this->option('script-file') !== null) {
            $scriptFile = $this->option('script-file');
            if (!file_exists($scriptFile)) {
                return $this->outputError("Script file not found: {$scriptFile}");
            }
            $tool->script = file_get_contents($scriptFile);
            $changes[] = 'script';
        } elseif ($this->option('script') !== null) {
            $tool->script = $this->option('script');
            $changes[] = 'script';
        }

        if ($this->option('category') !== null) {
            $tool->category = $this->option('category');
            $changes[] = 'category';
        }

        if ($this->option('input-schema') !== null) {
            $inputSchema = json_decode($this->option('input-schema'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->outputError('Invalid JSON in --input-schema: ' . json_last_error_msg());
            }
            $tool->input_schema = $inputSchema;
            $changes[] = 'input_schema';
        }

        if (empty($changes)) {
            return $this->outputError('No changes specified');
        }

        try {
            $tool->save();

            $this->outputResult([
                'output' => "Updated tool: {$tool->name} ({$slug})\nChanged: " . implode(', ', $changes),
                'is_error' => false,
                'tool' => [
                    'id' => $tool->id,
                    'slug' => $tool->slug,
                    'name' => $tool->name,
                ],
                'changes' => $changes,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->outputError('Failed to update tool: ' . $e->getMessage());
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
