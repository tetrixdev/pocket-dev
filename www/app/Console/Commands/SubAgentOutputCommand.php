<?php

namespace App\Console\Commands;

use App\Tools\ExecutionContext;
use App\Tools\SubAgentOutputTool;
use Illuminate\Console\Command;

class SubAgentOutputCommand extends Command
{
    protected $signature = 'subagent:output
        {--task-id= : The task UUID returned by subagent:run --background}';

    protected $description = 'Retrieve the status and output of a sub-agent task';

    public function handle(): int
    {
        $tool = new SubAgentOutputTool();

        $input = [
            'task_id' => $this->option('task-id') ?? '',
        ];

        $context = new ExecutionContext(
            getcwd() ?: '/var/www',
        );

        $result = $tool->execute($input, $context);

        $this->outputJson($result->toArray());

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
