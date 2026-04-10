<?php

namespace App\Services;

use App\Jobs\ProcessConversationStream;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\SubAgentTask;
use App\Tools\ExecutionContext;
use App\Tools\ToolResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Encapsulates the logic for spawning a sub-agent conversation.
 *
 * Used by both SubAgentTool (generic slug-based dispatch) and AgentTool
 * (per-agent native tool calls). Supports foreground and background modes,
 * as well as resuming an existing conversation.
 */
class SubAgentRunner
{
    public function __construct(
        private readonly ConversationFactory $factory,
        private readonly StreamManager $streamManager,
    ) {}

    /**
     * Run an agent with the given prompt.
     *
     * @param Agent           $agent          The agent to spawn
     * @param string          $prompt         The task/prompt to send
     * @param bool            $isBackground   Return immediately (background) or wait (foreground)
     * @param ExecutionContext $context        Caller's execution context
     * @param string|null     $conversationId Existing conversation UUID to resume (optional)
     */
    public function run(
        Agent $agent,
        string $prompt,
        bool $isBackground,
        ExecutionContext $context,
        ?string $conversationId = null,
    ): ToolResult {
        $workspace = $context->getWorkspace() ?? $agent->workspace;
        $workspaceId = $workspace?->id;
        $workingDirectory = $context->workingDirectory;

        try {
            if ($conversationId !== null) {
                return $this->resume($agent, $prompt, $isBackground, $context, $conversationId, $workspaceId);
            }

            return $this->spawn($agent, $prompt, $isBackground, $context, $workspaceId, $workingDirectory);
        } catch (\Throwable $e) {
            Log::error('SubAgentRunner: failed to start sub-agent', [
                'agent' => $agent->slug,
                'error' => $e->getMessage(),
            ]);
            return ToolResult::error('Failed to start sub-agent: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function spawn(
        Agent $agent,
        string $prompt,
        bool $isBackground,
        ExecutionContext $context,
        ?string $workspaceId,
        string $workingDirectory,
    ): ToolResult {
        [$conversation, $task] = DB::transaction(function () use ($agent, $workingDirectory, $workspaceId, $prompt, $context, $isBackground) {
            $conversation = $this->factory->createFromAgent(
                $agent,
                $workingDirectory,
                $workspaceId,
                'Subagent: ' . Str::limit($prompt, 60)
            );

            $task = SubAgentTask::create([
                'parent_conversation_uuid' => $context->conversationUuid,
                'child_conversation_uuid' => $conversation->uuid,
                'agent_id' => $agent->id,
                'prompt' => $prompt,
                'is_background' => $isBackground,
            ]);

            return [$conversation, $task];
        });

        // Initialize the Redis stream (outside transaction)
        $this->streamManager->startStream($conversation->uuid, [
            'model' => $conversation->model,
            'provider' => $conversation->provider_type,
            'subagent_task_id' => $task->id,
        ]);

        // Mark as processing BEFORE dispatching so waitForCompletion doesn't see
        // the initial 'idle' status and return prematurely before the job starts.
        $conversation->startProcessing();

        ProcessConversationStream::dispatch($conversation->uuid, $prompt);

        Log::info('SubAgentRunner: task started', [
            'task_id' => $task->id,
            'parent_conversation' => $context->conversationUuid,
            'child_conversation' => $conversation->uuid,
            'agent' => $agent->slug,
            'background' => $isBackground,
        ]);

        if ($isBackground) {
            return ToolResult::success(json_encode([
                'task_id' => $task->id,
                'conversation_id' => $conversation->uuid,
                'status' => 'running',
                'agent' => $agent->slug,
                'provider' => $agent->provider,
                'model' => $conversation->model,
                'message' => "Background sub-agent started. Use SubAgentOutput with task_id '{$task->id}' to check status and retrieve output.",
            ], JSON_PRETTY_PRINT));
        }

        return $this->waitForCompletion($task, $conversation);
    }

    /**
     * Resume an existing conversation by adding a new user message.
     */
    private function resume(
        Agent $agent,
        string $prompt,
        bool $isBackground,
        ExecutionContext $context,
        string $conversationId,
        ?string $workspaceId,
    ): ToolResult {
        $conversation = Conversation::where('uuid', $conversationId)
            ->when($workspaceId, fn($q) => $q->where('workspace_id', $workspaceId))
            ->first();

        if (!$conversation) {
            return ToolResult::error("Conversation '{$conversationId}' not found or not accessible.");
        }

        if ($conversation->status === Conversation::STATUS_PROCESSING) {
            return ToolResult::error("Conversation '{$conversationId}' is still running. Wait for it to complete before resuming.");
        }

        // Create a new task linked to this resumed conversation
        $task = SubAgentTask::create([
            'parent_conversation_uuid' => $context->conversationUuid,
            'child_conversation_uuid' => $conversation->uuid,
            'agent_id' => $agent->id,
            'prompt' => $prompt,
            'is_background' => $isBackground,
        ]);

        // Re-initialise the stream for this turn
        $this->streamManager->startStream($conversation->uuid, [
            'model' => $conversation->model,
            'provider' => $conversation->provider_type,
            'subagent_task_id' => $task->id,
        ]);

        ProcessConversationStream::dispatch($conversation->uuid, $prompt);

        Log::info('SubAgentRunner: conversation resumed', [
            'task_id' => $task->id,
            'parent_conversation' => $context->conversationUuid,
            'child_conversation' => $conversation->uuid,
            'agent' => $agent->slug,
            'background' => $isBackground,
        ]);

        if ($isBackground) {
            return ToolResult::success(json_encode([
                'task_id' => $task->id,
                'conversation_id' => $conversation->uuid,
                'status' => 'running',
                'agent' => $agent->slug,
                'message' => "Resumed conversation. Use SubAgentOutput with task_id '{$task->id}' to check status.",
            ], JSON_PRETTY_PRINT));
        }

        return $this->waitForCompletion($task, $conversation);
    }

    /**
     * Poll the conversation until it reaches a terminal state or times out.
     */
    private function waitForCompletion(SubAgentTask $task, Conversation $conversation): ToolResult
    {
        $maxWaitSeconds = 600; // 10 minutes
        $pollIntervalMicroseconds = 1_000_000; // 1 second
        $startTime = time();

        while (time() - $startTime < $maxWaitSeconds) {
            $conversation->refresh();

            if (in_array($conversation->status, [Conversation::STATUS_IDLE, Conversation::STATUS_ARCHIVED], true)) {
                $task->unsetRelation('childConversation');
                $output = $task->collectOutput();
                return ToolResult::success(json_encode([
                    'task_id' => $task->id,
                    'conversation_id' => $conversation->uuid,
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

        return ToolResult::success(json_encode([
            'task_id' => $task->id,
            'conversation_id' => $conversation->uuid,
            'status' => 'timeout',
            'message' => "Sub-agent did not complete within {$maxWaitSeconds} seconds. Use SubAgentOutput with task_id '{$task->id}' to check status later.",
        ], JSON_PRETTY_PRINT));
    }
}
