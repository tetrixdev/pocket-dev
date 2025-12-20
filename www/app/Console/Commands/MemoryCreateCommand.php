<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryCreateTool;
use Illuminate\Console\Command;

class MemoryCreateCommand extends Command
{
    protected $signature = 'memory:create
        {--structure= : The structure slug (e.g., "character", "location")}
        {--name= : Name of the object}
        {--data= : Object data as JSON}
        {--parent-id= : Optional parent object ID}';

    protected $description = 'Create a new memory object';

    public function handle(): int
    {
        $structure = $this->option('structure');
        $name = $this->option('name');
        $data = $this->option('data');
        $parentId = $this->option('parent-id');

        if (empty($structure)) {
            return $this->outputError('The --structure option is required');
        }

        if (empty($name)) {
            return $this->outputError('The --name option is required');
        }

        $input = [
            'structure' => $structure,
            'name' => $name,
        ];

        if ($data) {
            $decodedData = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->outputError('Invalid JSON in --data: ' . json_last_error_msg());
            }
            $input['data'] = $decodedData;
        }

        if ($parentId) {
            $input['parent_id'] = $parentId;
        }

        $tool = new MemoryCreateTool();
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
