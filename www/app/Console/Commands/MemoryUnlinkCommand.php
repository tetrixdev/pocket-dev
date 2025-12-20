<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryUnlinkTool;
use Illuminate\Console\Command;

class MemoryUnlinkCommand extends Command
{
    protected $signature = 'memory:unlink
        {--source-id= : UUID of the source object}
        {--target-id= : UUID of the target object}
        {--type= : Type of relationship to remove (omit to remove all)}
        {--bidirectional : Also remove the inverse relationship}';

    protected $description = 'Remove a relationship between two memory objects';

    public function handle(): int
    {
        $sourceId = $this->option('source-id');
        $targetId = $this->option('target-id');
        $type = $this->option('type');
        $bidirectional = $this->option('bidirectional');

        if (empty($sourceId)) {
            return $this->outputError('The --source-id option is required');
        }

        if (empty($targetId)) {
            return $this->outputError('The --target-id option is required');
        }

        $input = [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'bidirectional' => $bidirectional,
        ];

        if ($type) {
            $input['relationship_type'] = $type;
        }

        $tool = new MemoryUnlinkTool();
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
