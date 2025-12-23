<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryStructureDeleteTool;
use Illuminate\Console\Command;

class MemoryStructureDeleteCommand extends Command
{
    protected $signature = 'memory:structure:delete
                            {slug : Structure slug to delete}';

    protected $description = 'Delete a memory structure (only if it has no objects)';

    public function handle(): int
    {
        $tool = new MemoryStructureDeleteTool();

        $input = [
            'slug' => $this->argument('slug'),
        ];

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
