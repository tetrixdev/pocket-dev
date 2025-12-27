<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryInsertTool;
use Illuminate\Console\Command;

class MemoryInsertCommand extends Command
{
    protected $signature = 'memory:insert
        {--table= : Table name (without schema prefix)}
        {--data= : JSON object with column => value pairs}';

    protected $description = 'Insert a row into a memory table with auto-embedding';

    public function handle(): int
    {
        $table = $this->option('table');
        $data = $this->option('data');

        if (empty($table)) {
            return $this->outputError('The --table option is required');
        }

        if (empty($data)) {
            return $this->outputError('The --data option is required');
        }

        $input = [
            'table' => $table,
            'data' => $data,
        ];

        $tool = new MemoryInsertTool();
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
