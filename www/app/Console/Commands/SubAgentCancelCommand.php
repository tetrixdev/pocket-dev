<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\SubAgentCancelTool;
use Illuminate\Console\Command;

class SubAgentCancelCommand extends Command
{
    protected $signature = 'subagent:cancel
        {--task-id= : The task UUID returned by subagent:run --background}';

    protected $description = 'Cancel a running background sub-agent task';

    public function handle(): int
    {
        $tool = new SubAgentCancelTool();

        $input = [
            'task_id' => $this->option('task-id') ?? '',
        ];

        // Note: when called from CLI (not from within a conversation), there is no
        // parent conversation UUID — the ownership guard in the tool is skipped.
        $context = new ExecutionContext(
            getcwd() ?: '/var/www',
        );

        $result = $tool->execute($input, $context);

        $this->outputJson($result->toArray());

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    private function outputJson(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode sub-agent output as JSON: ' . json_last_error_msg());
        }

        $this->output->writeln($json);
    }
}
