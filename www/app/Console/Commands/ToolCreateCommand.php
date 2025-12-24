<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\ToolCreateTool;
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
        $tool = new ToolCreateTool();

        // Build input from options
        $input = [];

        if ($this->option('slug') !== null) {
            $input['slug'] = $this->option('slug');
        }

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

        if ($this->option('disabled')) {
            $input['disabled'] = true;
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
