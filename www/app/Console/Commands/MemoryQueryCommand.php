<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\MemoryQueryTool;
use Illuminate\Console\Command;

class MemoryQueryCommand extends Command
{
    protected $signature = 'memory:query
        {--schema= : Memory schema short name (e.g., "default")}
        {--sql= : SQL SELECT query}
        {--search-text= : Text to convert to embedding for semantic search}
        {--limit=50 : Maximum number of results (max 100)}';

    protected $description = 'Query memory objects using SQL with optional semantic search';

    public function handle(): int
    {
        $sql = $this->option('sql');
        $searchText = $this->option('search-text');
        $limit = (int) $this->option('limit');

        if (empty($sql)) {
            return $this->outputError('The --sql option is required');
        }

        $input = [
            'schema' => $this->option('schema'),
            'sql' => $sql,
            'limit' => $limit,
        ];

        if ($searchText) {
            $input['search_text'] = $searchText;
        }

        $tool = new MemoryQueryTool();
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
