<?php

namespace App\Tools;

use App\Jobs\ProcessConversationStream;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\SubAgentTask;
use App\Models\Workspace;
use App\Services\ConversationFactory;
use App\Services\StreamManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubAgentTool extends Tool
{
    public string $name = 'SubAgent';

    public string $description = 'Spawn a child PocketDev agent to handle a task. Supports cross-provider delegation (e.g. Claude Code can call Codex and vice versa).';

    public string $category = 'custom';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'agent' => [
                'type' => 'string',
                'description' => 'Agent slug or UUID. See "Available Agents" in the system prompt for valid values.',
            ],
            'prompt' => [
                'type' => 'string',
                'description' => 'The task/prompt to send to the sub-agent.',
            ],
            'background' => [
                'type' => 'boolean',
                'description' => 'If true, return immediately with a task_id. If false (default), wait for the sub-agent to complete and return its output.',
            ],
        ],
        'required' => ['agent', 'prompt'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Spawn a child PocketDev agent to handle a task using any configured provider. This enables cross-provider delegation: Claude Code can call a Codex agent, Codex can call a Claude Code agent, etc.

## When to Use
- Delegate specialized tasks to a different AI model or provider
- Run multiple tasks in parallel using background mode
- Break complex work into sub-tasks handled by purpose-built agents

## Foreground Mode (default)
Blocks until the sub-agent completes and returns its full output. Use for tasks where you need the result before continuing.

## Background Mode
Returns immediately with a task_id. Use SubAgentOutput to check status and retrieve results later. Ideal for parallelism.

## Parameters
- agent: Agent slug or UUID (see "Available Agents" section in this prompt)
- prompt: The task/prompt to send. Be specific and self-contained — the sub-agent has no context from your conversation
- background: Optional boolean (default: false). Set to true for background execution

## Important Notes
- The sub-agent starts a fresh conversation — it does NOT see your conversation history
- Write self-contained prompts with all necessary context
- The sub-agent shares your working directory and workspace
- Foreground mode has a 10-minute timeout
- For background tasks, use SubAgentOutput to poll for completion
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
# Foreground: wait for result
pd subagent:run --agent=codex-default --prompt="List all TODO comments in the codebase"

# Background: get task ID immediately
pd subagent:run --agent=claude-code-default --prompt="Refactor the auth module" --background

# Then check output later
pd subagent:output --task-id=<uuid-from-above>
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "agent": "codex-default",
  "prompt": "Find and fix all TypeScript type errors",
  "background": true
}
```
API;

    public function getArtisanCommand(): ?string
    {
        return 'subagent:run';
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $agentIdentifier = trim($input['agent'] ?? '');
        $prompt = trim($input['prompt'] ?? '');
        $isBackground = (bool) ($input['background'] ?? false);

        if (empty($agentIdentifier)) {
            return ToolResult::error('agent is required. See "Available Agents" in the system prompt.');
        }
        if (empty($prompt)) {
            return ToolResult::error('prompt is required');
        }

        // Resolve agent by slug first, then fall back to UUID lookup
        $agent = Agent::where('slug', $agentIdentifier)->first();
        if (!$agent && Str::isUuid($agentIdentifier)) {
            $agent = Agent::find($agentIdentifier);
        }

        if (!$agent) {
            return ToolResult::error("Agent '{$agentIdentifier}' not found. Use an agent slug or UUID from the Available Agents list.");
        }

        if (!$agent->enabled) {
            return ToolResult::error("Agent '{$agent->name}' is disabled.");
        }

        // Determine workspace
        $workspace = $context->getWorkspace() ?? $agent->workspace;
        $workspaceId = $workspace?->id;
        $workingDirectory = $context->workingDirectory;

        try {
            // Create child conversation via the factory
            $factory = app(ConversationFactory::class);
            $conversation = $factory->createFromAgent(
                $agent,
                $workingDirectory,
                $workspaceId,
                'Subagent: ' . Str::limit($prompt, 60)
            );

            // Create the subagent task record
            $task = SubAgentTask::create([
                'parent_conversation_uuid' => $context->conversationUuid,
                'child_conversation_uuid' => $conversation->uuid,
                'agent_id' => $agent->id,
                'prompt' => $prompt,
                'is_background' => $isBackground,
            ]);

            // Initialize the Redis stream
            $streamManager = app(StreamManager::class);
            $streamManager->startStream($conversation->uuid, [
                'model' => $conversation->model,
                'provider' => $conversation->provider_type,
                'subagent_task_id' => $task->id,
            ]);

            // Dispatch the streaming job
            ProcessConversationStream::dispatch(
                $conversation->uuid,
                $prompt,
            );

            Log::info('SubAgent task started', [
                'task_id' => $task->id,
                'parent_conversation' => $context->conversationUuid,
                'child_conversation' => $conversation->uuid,
                'agent' => $agent->slug,
                'background' => $isBackground,
            ]);

            // Background mode: return immediately
            if ($isBackground) {
                return ToolResult::success(json_encode([
                    'task_id' => $task->id,
                    'status' => 'running',
                    'agent' => $agent->slug,
                    'provider' => $agent->provider,
                    'model' => $conversation->model,
                    'message' => "Background sub-agent started. Use SubAgentOutput with task_id '{$task->id}' to check status and retrieve output.",
                ], JSON_PRETTY_PRINT));
            }

            // Foreground mode: poll until complete
            return $this->waitForCompletion($task, $conversation);

        } catch (\Exception $e) {
            Log::error('SubAgent failed to start', [
                'agent' => $agentIdentifier,
                'error' => $e->getMessage(),
            ]);
            return ToolResult::error('Failed to start sub-agent: ' . $e->getMessage());
        }
    }

    /**
     * Poll the conversation until it completes or times out.
     */
    private function waitForCompletion(SubAgentTask $task, Conversation $conversation): ToolResult
    {
        $maxWaitSeconds = 600; // 10 minutes
        $pollIntervalMicroseconds = 1_000_000; // 1 second
        $startTime = time();

        while (time() - $startTime < $maxWaitSeconds) {
            $conversation->refresh();

            if ($conversation->status === Conversation::STATUS_IDLE) {
                // Reload task's relationship so collectOutput sees the final messages
                $task->unsetRelation('childConversation');
                $output = $task->collectOutput();
                return ToolResult::success(json_encode([
                    'task_id' => $task->id,
                    'status' => 'completed',
                    'output' => $output,
                ], JSON_PRETTY_PRINT));
            }

            if ($conversation->status === Conversation::STATUS_FAILED) {
                $task->unsetRelation('childConversation');
                $error = $task->getError();
                return ToolResult::error("Sub-agent task '{$task->id}' failed: {$error}");
            }

            usleep($pollIntervalMicroseconds);
        }

        // Timed out — returned as success because the task is still running
        // and the caller can poll via SubAgentOutput later.
        return ToolResult::success(json_encode([
            'task_id' => $task->id,
            'status' => 'timeout',
            'message' => "Sub-agent did not complete within {$maxWaitSeconds} seconds. Use SubAgentOutput with task_id '{$task->id}' to check status later.",
        ], JSON_PRETTY_PRINT));
    }
}
