<?php

namespace App\Console\Commands;

use App\Tools\ConversationSearchTool;
use App\Tools\ExecutionContext;
use Illuminate\Console\Command;

class ConversationSearchCommand extends Command
{
    protected $signature = 'conversation:search
        {--query= : Natural language search query}
        {--limit=5 : Maximum results to return (max 50)}';

    protected $description = 'Search past conversations semantically';

    public function handle(): int
    {
        $query = $this->option('query');
        $limit = (int) $this->option('limit');

        if (empty($query)) {
            return $this->outputError('The --query option is required');
        }

        $input = [
            'query' => $query,
            'limit' => $limit,
        ];

        $tool = new ConversationSearchTool();
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
