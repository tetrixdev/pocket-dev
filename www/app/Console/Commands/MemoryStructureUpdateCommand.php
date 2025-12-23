<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryStructureUpdateTool;
use Illuminate\Console\Command;

class MemoryStructureUpdateCommand extends Command
{
    protected $signature = 'memory:structure:update
                            {slug : Structure slug to update}
                            {--name= : New structure name}
                            {--description= : New description}
                            {--schema= : New JSON Schema (use with caution)}
                            {--icon= : New icon name}
                            {--color= : New hex color}
                            {--regenerate-embeddings : Regenerate embeddings for all objects}';

    protected $description = 'Update an existing memory structure';

    public function handle(): int
    {
        $tool = new MemoryStructureUpdateTool();

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

        if ($this->option('regenerate-embeddings')) {
            $input['regenerate_embeddings'] = true;
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
