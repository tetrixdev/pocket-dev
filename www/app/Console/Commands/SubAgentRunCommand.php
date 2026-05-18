<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Models\Workspace;
use App\Tools\ExecutionContext;
use App\Tools\SubAgentTool;
use Illuminate\Console\Command;

class SubAgentRunCommand extends Command
{
    protected $signature = 'subagent:run
        {--agent= : Agent slug or UUID}
        {--prompt= : The task/prompt to send to the sub-agent}
        {--background : Return immediately with a task ID (default: wait for completion)}';

    protected $description = 'Spawn a child PocketDev agent to handle a task';

    public function handle(): int
    {
        $tool = new SubAgentTool();

        $input = [
            'agent' => $this->option('agent') ?? '',
            'prompt' => $this->option('prompt') ?? '',
            'background' => $this->option('background'),
        ];

        // Build execution context from environment
        $session = null;
        $workspace = null;
        $conversationUuid = null;

        $sessionId = getenv('POCKETDEV_SESSION_ID') ?: null;
        if ($sessionId) {
            $session = Session::find($sessionId);
        }

        $workspaceId = getenv('POCKETDEV_WORKSPACE_ID') ?: null;
        if ($workspaceId) {
            $workspace = Workspace::find($workspaceId);
        }

        $conversationUuid = getenv('POCKETDEV_CONVERSATION_UUID') ?: null;

        $context = new ExecutionContext(
            getcwd() ?: '/var/www',
            workspace: $workspace,
            session: $session,
            conversationUuid: $conversationUuid,
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
