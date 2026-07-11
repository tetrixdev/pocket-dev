<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryDeleteTool;
use Illuminate\Console\Command;

class MemoryDeleteCommand extends Command
{
    protected $signature = 'memory:delete
        {--schema= : Memory schema short name (e.g., "default")}
        {--table= : Table name (without schema prefix)}
        {--where= : WHERE clause (without WHERE keyword)}';

    protected $description = 'Delete rows from a memory table and their associated embeddings';

    public function handle(): int
    {
        $table = $this->option('table');
        $where = $this->option('where');

        if (empty($table)) {
            return $this->outputError('The --table option is required');
        }

        if (empty($where)) {
            return $this->outputError('The --where option is required');
        }

        $input = [
            'schema' => $this->option('schema'),
            'table' => $table,
            'where' => $where,
        ];

        $tool = new MemoryDeleteTool();
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
