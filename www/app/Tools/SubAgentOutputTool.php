<?php

namespace App\Tools;

use App\Models\SubAgentTask;

class SubAgentOutputTool extends Tool
{
    public string $name = 'SubAgentOutput';

    public string $description = 'Retrieve the status and output of a sub-agent task.';

    public string $category = 'custom';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'task_id' => [
                'type' => 'string',
                'description' => 'The task UUID returned by SubAgent when using background mode.',
            ],
        ],
        'required' => ['task_id'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Retrieve the status and output of a background sub-agent task.

## When to Use
- After launching a sub-agent with background=true
- To poll for completion of a running sub-agent
- To retrieve the final output once a sub-agent completes

## Status Values
- **running**: Sub-agent is still processing
- **completed**: Sub-agent finished, output is included in the response
- **failed**: Sub-agent encountered an error, error details are included
- **pending**: Sub-agent has not started yet (rare, transient state)

## Polling Pattern
For background tasks, poll periodically until status is "completed" or "failed":
1. Launch sub-agent with background=true, get task_id
2. Do other work
3. Check status with SubAgentOutput
4. If still "running", wait and check again
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
pd subagent:output --task-id=a1b2c3d4-e5f6-7890-abcd-ef1234567890
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
        return 'subagent:output';
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

        $status = $task->getStatus();

        $result = [
            'task_id' => $task->id,
            'status' => $status,
            'agent_id' => $task->agent_id,
            'is_background' => $task->is_background,
            'created_at' => $task->created_at?->toIso8601String(),
        ];

        if ($status === 'completed') {
            $result['output'] = $task->collectOutput();
        } elseif ($status === 'failed') {
            $result['error'] = $task->getError();
        } elseif ($status === 'running') {
            $result['message'] = 'Sub-agent is still processing. Check again later.';
        }

        return ToolResult::success(json_encode($result, JSON_PRETTY_PRINT));
    }
}
