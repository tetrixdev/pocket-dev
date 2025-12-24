<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryStructureCreateTool;
use Illuminate\Console\Command;

class MemoryStructureCreateCommand extends Command
{
    protected $signature = 'memory:structure:create
                            {--name= : Structure name (e.g., "Character")}
                            {--slug= : URL-friendly slug (auto-generated from name if omitted)}
                            {--description= : What this structure represents}
                            {--schema= : JSON Schema defining the structure fields}
                            {--icon= : Icon name (optional)}
                            {--color= : Hex color (optional, e.g., "#2196F3")}';

    protected $description = 'Create a new memory structure (schema/template for memory objects)';

    public function handle(): int
    {
        $tool = new MemoryStructureCreateTool();

        // Build input from options
        $input = [];

        if ($this->option('name') !== null) {
            $input['name'] = $this->option('name');
        }

        if ($this->option('slug') !== null) {
            $input['slug'] = $this->option('slug');
        }

        if ($this->option('description') !== null) {
            $input['description'] = $this->option('description');
        }

        if ($this->option('schema') !== null) {
            $schema = json_decode($this->option('schema'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->outputJson([
                    'output' => 'Invalid JSON in --schema: ' . json_last_error_msg(),
                    'is_error' => true,
                ]);
                return Command::FAILURE;
            }
            $input['schema'] = $schema;
        }

        if ($this->option('icon') !== null) {
            $input['icon'] = $this->option('icon');
        }

        if ($this->option('color') !== null) {
            $input['color'] = $this->option('color');
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
