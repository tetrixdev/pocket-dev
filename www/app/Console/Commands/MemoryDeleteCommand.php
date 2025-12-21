<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryDeleteTool;
use Illuminate\Console\Command;

class MemoryDeleteCommand extends Command
{
    protected $signature = 'memory:delete
        {--id= : The UUID of the object to delete}
        {--cascade : Also delete child objects}';

    protected $description = 'Delete a memory object';

    public function handle(): int
    {
        $id = $this->option('id');
        $cascade = $this->option('cascade');

        if (empty($id)) {
            return $this->outputError('The --id option is required');
        }

        $input = [
            'id' => $id,
            'cascade' => $cascade,
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
