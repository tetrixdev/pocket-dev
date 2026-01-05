<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryUpdateTool;
use Illuminate\Console\Command;

class MemoryUpdateCommand extends Command
{
    protected $signature = 'memory:update
        {--schema= : Memory schema short name (e.g., "default")}
        {--table= : Table name (without schema prefix)}
        {--data= : JSON object with column => value pairs to update}
        {--where= : WHERE clause (without WHERE keyword)}';

    protected $description = 'Update rows in a memory table with auto-embedding regeneration';

    public function handle(): int
    {
        $table = $this->option('table');
        $data = $this->option('data');
        $where = $this->option('where');

        if (empty($table)) {
            return $this->outputError('The --table option is required');
        }

        if (empty($data)) {
            return $this->outputError('The --data option is required');
        }

        if (empty($where)) {
            return $this->outputError('The --where option is required');
        }

        $input = [
            'schema' => $this->option('schema'),
            'table' => $table,
            'data' => $data,
            'where' => $where,
        ];

        $tool = new MemoryUpdateTool();
        $context = new ExecutionContext(getcwd());
        $result = $tool->execute($input, $context);

        $this->outputResult($result->toArray());

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
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
