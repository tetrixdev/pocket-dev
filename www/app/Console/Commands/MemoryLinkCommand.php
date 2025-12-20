<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryLinkTool;
use Illuminate\Console\Command;

class MemoryLinkCommand extends Command
{
    protected $signature = 'memory:link
        {--source-id= : UUID of the source object}
        {--target-id= : UUID of the target object}
        {--type= : Type of relationship (e.g., "owns", "knows", "located_in")}
        {--bidirectional : Also create the inverse relationship}';

    protected $description = 'Create a relationship between two memory objects';

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

        if (empty($type)) {
            return $this->outputError('The --type option is required');
        }

        $input = [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'relationship_type' => $type,
            'bidirectional' => $bidirectional,
        ];

        $tool = new MemoryLinkTool();
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
