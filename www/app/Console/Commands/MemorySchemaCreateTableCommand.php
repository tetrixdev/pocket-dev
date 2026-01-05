<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemorySchemaCreateTableTool;
use Illuminate\Console\Command;

class MemorySchemaCreateTableCommand extends Command
{
    protected $signature = 'memory:schema:create-table
        {--schema= : Memory schema short name (e.g., "default")}
        {--name= : Table name (without schema prefix)}
        {--description= : Description of what this table stores}
        {--sql= : CREATE TABLE SQL statement}
        {--embed-fields= : Comma-separated list of fields to auto-embed}
        {--column-descriptions= : JSON object mapping column names to descriptions}';

    protected $description = 'Create a table in the memory schema with embedded field configuration';

    public function handle(): int
    {
        $name = $this->option('name');
        $description = $this->option('description');
        $sql = $this->option('sql');
        $embedFields = $this->option('embed-fields');
        $columnDescriptions = $this->option('column-descriptions');

        if (empty($name)) {
            return $this->outputError('The --name option is required');
        }

        if (empty($description)) {
            return $this->outputError('The --description option is required');
        }

        if (empty($sql)) {
            return $this->outputError('The --sql option is required');
        }

        if ($embedFields === null) {
            return $this->outputError('The --embed-fields option is required (use empty string if no embedding needed)');
        }

        $input = [
            'schema' => $this->option('schema'),
            'name' => $name,
            'description' => $description,
            'sql' => $sql,
            'embed_fields' => $embedFields,
        ];

        if ($columnDescriptions) {
            $input['column_descriptions'] = $columnDescriptions;
        }

        $tool = new MemorySchemaCreateTableTool();
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
