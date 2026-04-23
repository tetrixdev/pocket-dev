<?php

namespace App\Tools;

use App\Models\Conversation;
use App\Models\SubAgentTask;
use App\Services\StreamManager;

class SubAgentCancelTool extends Tool
{
    public string $name = 'SubAgentCancel';

    public string $description = 'Cancel a running background sub-agent task.';

    public string $category = 'custom';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'task_id' => [
                'type' => 'string',
                'description' => 'The task UUID returned by SubAgent when launched with background=true.',
            ],
        ],
        'required' => ['task_id'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Cancel a running background sub-agent task by its task ID.

## When to Use
- After launching a sub-agent with background=true and deciding to stop it
- When a background task is no longer needed

## How It Works
Sets an abort flag on the child conversation. The running sub-agent detects the flag
within ~1 second on its next event-loop tick and shuts down cleanly.
Cancellation is non-blocking — this tool returns immediately.

## Notes
- Only tasks you spawned (from this conversation) can be cancelled
- If the task has already completed or failed, cancellation is a no-op
- After cancellation the task status becomes "failed" (same as a user-initiated stop)

## Parameters
- task_id: The UUID returned by SubAgent when background=true
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
pd subagent:cancel --task-id=a1b2c3d4-e5f6-7890-abcd-ef1234567890
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "task_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
}
```
API;

    public function getArtisanCommand(): ?string
    {
        return 'subagent:cancel';
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $taskId = trim($input['task_id'] ?? '');

        if (empty($taskId)) {
            return ToolResult::error('task_id is required');
        }

        $task = SubAgentTask::with('childConversation')->find($taskId);

        if (!$task) {
            return ToolResult::error("Sub-agent task '{$taskId}' not found.");
        }

        // Guard: only allow cancellation of tasks spawned by this conversation.
        // Prevents one conversation from aborting another conversation's subagents.
        if ($context->conversationUuid && $task->parent_conversation_uuid !== $context->conversationUuid) {
            return ToolResult::error("Task '{$taskId}' was not spawned by this conversation.");
        }

        $conversation = $task->childConversation;

        if (!$conversation) {
            return ToolResult::error("Sub-agent task '{$taskId}' has no associated conversation.");
        }

        // If already terminal, nothing to cancel
        $terminalStatuses = [
            Conversation::STATUS_IDLE,
            Conversation::STATUS_ARCHIVED,
            Conversation::STATUS_FAILED,
        ];

        if (in_array($conversation->status, $terminalStatuses, true)) {
            $status = $task->getStatus();
            return ToolResult::success(json_encode([
                'task_id'   => $task->id,
                'cancelled' => false,
                'status'    => $status,
                'message'   => "Task is already {$status} — nothing to cancel.",
            ], JSON_PRETTY_PRINT));
        }

        // Set the abort flag on the child conversation's stream.
        // ProcessConversationStream checks this flag in its event loop and will
        // shut down the child cleanly within ~1 second.
        $streamManager = app(StreamManager::class);
        $streamManager->setAbortFlag($conversation->uuid);

        return ToolResult::success(json_encode([
            'task_id'   => $task->id,
            'cancelled' => true,
            'status'    => 'cancelling',
            'message'   => "Abort signal sent to sub-agent task '{$task->id}'. "
                . 'The task will stop within ~1 second. '
                . "Use SubAgentOutput with task_id '{$task->id}' to confirm.",
        ], JSON_PRETTY_PRINT));
    }
}
