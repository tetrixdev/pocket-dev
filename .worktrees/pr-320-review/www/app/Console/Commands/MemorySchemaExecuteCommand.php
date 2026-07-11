<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemorySchemaExecuteTool;
use Illuminate\Console\Command;

class MemorySchemaExecuteCommand extends Command
{
    protected $signature = 'memory:schema:execute
        {--schema= : Memory schema short name (e.g., "default")}
        {--sql= : DDL SQL statement to execute}';

    protected $description = 'Execute DDL SQL on the memory schema (CREATE INDEX, DROP TABLE, etc.)';

    public function handle(): int
    {
        $sql = $this->option('sql');

        if (empty($sql)) {
            return $this->outputError('The --sql option is required');
        }

        $input = [
            'schema' => $this->option('schema'),
            'sql' => $sql,
        ];

        $tool = new MemorySchemaExecuteTool();
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
