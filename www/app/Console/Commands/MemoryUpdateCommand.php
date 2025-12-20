<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryUpdateTool;
use Illuminate\Console\Command;

class MemoryUpdateCommand extends Command
{
    protected $signature = 'memory:update
        {--id= : The UUID of the object to update}
        {--name= : New name for the object}
        {--data= : Fields to update as JSON (merged with existing)}
        {--replace-data : Replace all data instead of merging}
        {--parent-id= : New parent object ID (use "null" to remove)}';

    protected $description = 'Update an existing memory object';

    public function handle(): int
    {
        $id = $this->option('id');
        $name = $this->option('name');
        $data = $this->option('data');
        $replaceData = $this->option('replace-data');
        $parentId = $this->option('parent-id');

        if (empty($id)) {
            return $this->outputError('The --id option is required');
        }

        $input = ['id' => $id];

        if ($name) {
            $input['name'] = $name;
        }

        if ($data) {
            $decodedData = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->outputError('Invalid JSON in --data: ' . json_last_error_msg());
            }
            $input['data'] = $decodedData;
        }

        if ($replaceData) {
            $input['replace_data'] = true;
        }

        if ($parentId !== null) {
            $input['parent_id'] = $parentId === 'null' ? null : $parentId;
        }

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
