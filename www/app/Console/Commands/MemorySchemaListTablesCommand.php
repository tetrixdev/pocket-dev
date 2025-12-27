<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemorySchemaListTablesTool;
use Illuminate\Console\Command;

class MemorySchemaListTablesCommand extends Command
{
    protected $signature = 'memory:schema:list-tables
        {--table= : Show details for a specific table only}
        {--show-columns=true : Include column details}';

    protected $description = 'List all tables in the memory schema with columns and metadata';

    public function handle(): int
    {
        $table = $this->option('table');
        $showColumns = $this->option('show-columns') !== 'false';

        $input = [
            'show_columns' => $showColumns,
        ];

        if ($table) {
            $input['table'] = $table;
        }

        $tool = new MemorySchemaListTablesTool();
        $context = new ExecutionContext(getcwd());
        $result = $tool->execute($input, $context);

        $this->outputResult($result->toArray());

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    private function outputResult(array $result): void
    {
        $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT));
    }
}
