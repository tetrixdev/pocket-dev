<?php

namespace App\Console\Commands;

use App\Tools\ConversationGetTurnsTool;
use App\Tools\ExecutionContext;
use Illuminate\Console\Command;

class ConversationGetTurnsCommand extends Command
{
    protected $signature = 'conversation:get-turns
        {--turns= : JSON array of turns to retrieve, e.g. [{"conversation_uuid":"abc","turn_number":5}]}';

    protected $description = 'Retrieve full content for specific conversation turns';

    public function handle(): int
    {
        $turnsJson = $this->option('turns');

        if (empty($turnsJson)) {
            return $this->outputError('The --turns option is required');
        }

        $turns = json_decode($turnsJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->outputError('Invalid JSON in --turns option: ' . json_last_error_msg());
        }

        if (!is_array($turns)) {
            return $this->outputError('--turns must be a JSON array');
        }

        $input = ['turns' => $turns];

        $tool = new ConversationGetTurnsTool();
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
