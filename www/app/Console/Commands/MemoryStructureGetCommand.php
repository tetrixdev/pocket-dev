<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryStructureGetTool;
use Illuminate\Console\Command;

class MemoryStructureGetCommand extends Command
{
    protected $signature = 'memory:structure:get
                            {slug? : Structure slug to retrieve (optional - lists all if omitted)}
                            {--include-schema : Include the full JSON Schema in output}';

    protected $description = 'Get memory structure(s) - retrieve one by slug or list all';

    public function handle(): int
    {
        $tool = new MemoryStructureGetTool();

        // Build input from arguments/options
        $input = [];

        if ($this->argument('slug') !== null) {
            $input['slug'] = $this->argument('slug');
        }

        if ($this->option('include-schema')) {
            $input['include_schema'] = true;
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
