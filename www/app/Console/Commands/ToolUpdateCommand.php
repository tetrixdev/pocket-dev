<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\ToolUpdateTool;
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
        {--blade-template= : New Blade template content for panel tools}
        {--blade-template-file= : Path to file containing new Blade template}
        {--category= : New category}
        {--input-schema= : New JSON Schema for input parameters}';

    protected $description = 'Update an existing user tool';

    public function handle(): int
    {
        $tool = new ToolUpdateTool();

        // Build input from arguments/options
        $input = [
            'slug' => $this->argument('slug'),
        ];

        if ($this->option('name') !== null) {
            $input['name'] = $this->option('name');
        }

        if ($this->option('description') !== null) {
            $input['description'] = $this->option('description');
        }

        if ($this->option('system-prompt') !== null) {
            $input['system_prompt'] = $this->option('system-prompt');
        }

        // Handle script from file if provided
        if ($this->option('script-file') !== null) {
            $scriptFile = $this->option('script-file');
            if (!file_exists($scriptFile)) {
                $this->outputJson([
                    'output' => "Script file not found: {$scriptFile}",
                    'is_error' => true,
                ]);
                return Command::FAILURE;
            }
            $input['script'] = file_get_contents($scriptFile);
        } elseif ($this->option('script') !== null) {
            $input['script'] = $this->option('script');
        }

        // Handle blade template from file if provided
        if ($this->option('blade-template-file') !== null) {
            $templateFile = $this->option('blade-template-file');
            if (!file_exists($templateFile)) {
                $this->outputJson([
                    'output' => "Blade template file not found: {$templateFile}",
                    'is_error' => true,
                ]);
                return Command::FAILURE;
            }
            $input['blade_template'] = file_get_contents($templateFile);
        } elseif ($this->option('blade-template') !== null) {
            $input['blade_template'] = $this->option('blade-template');
        }

        if ($this->option('category') !== null) {
            $input['category'] = $this->option('category');
        }

        if ($this->option('input-schema') !== null) {
            $inputSchema = json_decode($this->option('input-schema'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->outputJson([
                    'output' => 'Invalid JSON in --input-schema: ' . json_last_error_msg(),
                    'is_error' => true,
                ]);
                return Command::FAILURE;
            }
            $input['input_schema'] = $inputSchema;
        }

        $context = new ExecutionContext(getcwd() ?: '/var/www');
        $result = $tool->execute($input, $context);

        $this->outputJson($result->toArray());

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
