<?php

namespace App\Jobs;

use App\Contracts\AIProviderInterface;
use App\Enums\Provider;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ModelRepository;
use App\Services\ProviderFactory;
use App\Services\StreamManager;
use App\Services\SystemPromptBuilder;
use App\Services\ToolRegistry;
use App\Streaming\StreamEvent;
use App\Tools\ExecutionContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background job that handles streaming conversation with AI provider.
 *
 * This job:
 * - Streams responses from the AI provider
 * - Publishes events to Redis for real-time frontend updates
 * - Saves the final response to the database
 * - Handles tool execution loops
 */
class ProcessConversationStream implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes max (agentic AI can take a while)
    public int $tries = 1;      // Don't retry failed streams

    // Note: Redis job reservation timeout is controlled by REDIS_QUEUE_RETRY_AFTER in .env
    // (default 90s is too short for long-running streams). See .env.example for details.

    public function __construct(
        public string $conversationUuid,
        public string $prompt,
        public array $options = [],
    ) {}

    /**
     * Unique ID for job deduplication.
     * Prevents multiple jobs for the same conversation from being queued.
     */
    public function uniqueId(): string
    {
        return $this->conversationUuid;
    }

    public function handle(
        ProviderFactory $providerFactory,
        StreamManager $streamManager,
        ToolRegistry $toolRegistry,
        SystemPromptBuilder $systemPromptBuilder,
        ModelRepository $modelRepository,
    ): void {
        $conversation = Conversation::where('uuid', $this->conversationUuid)->firstOrFail();

        // Note: Stream state is already initialized by the controller to prevent race conditions
        // We don't call startStream() here to avoid overwriting the state

        // Mark conversation as processing
        $conversation->startProcessing();

        try {
            // Save user message and keep reference for abort sync
            $userMessage = $this->saveUserMessage($conversation, $this->prompt);

            // Get provider
            $provider = $providerFactory->make($conversation->provider_type);

            if (!$provider->isAvailable()) {
                throw new \RuntimeException("Provider '{$conversation->provider_type}' is not available");
            }

            // Stream with tool execution loop
            $this->streamWithToolLoop(
                $conversation,
                $provider,
                $streamManager,
                $toolRegistry,
                $systemPromptBuilder,
                $modelRepository,
                $this->options,
                userMessage: $userMessage
            );

            // Only finalize if not already completed/aborted inside streamWithToolLoop
            if ($conversation->fresh()->status === Conversation::STATUS_PROCESSING) {
                // Calculate and store turns while still locked
                $this->calculateAndStoreTurns($conversation);

                // Mark conversation as completed (releases lock)
                $conversation->completeProcessing();
                $streamManager->completeStream($this->conversationUuid);

                // Dispatch async embedding job (runs after lock released)
                GenerateConversationEmbeddings::dispatch($conversation);
            }

        } catch (\Throwable $e) {
            Log::error('ProcessConversationStream failed', [
                'conversation' => $this->conversationUuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $conversation->markFailed();
            $streamManager->failStream($this->conversationUuid, $e->getMessage());
        }
    }

    /**
     * Handle job failure (called by Laravel when job fails outside handle()).
     *
     * This catches failures like timeouts, worker restarts, or MaxAttemptsExceededException.
     * Creates an error block in the conversation so the user knows what happened.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessConversationStream: Job failed', [
            'conversation' => $this->conversationUuid,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
        ]);

        $streamManager = app(StreamManager::class);

        $conversation = Conversation::where('uuid', $this->conversationUuid)->first();
        if ($conversation) {
            // Create error message so user sees what happened
            // Use ROLE_ERROR so it renders as expandable error block in UI
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => Message::ROLE_ERROR,
                'content' => [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]],
                'stop_reason' => 'error',
            ]);

            $conversation->markFailed();
        }

        // Send error event (shows popup if SSE connected) and cleanup
        $streamManager->failStream($this->conversationUuid, $exception->getMessage());
        $streamManager->clearAbortFlag($this->conversationUuid);
    }

    private const MAX_TOOL_ITERATIONS = 25;

    /**
     * Stream from provider and handle tool execution.
     */
    private function streamWithToolLoop(
        Conversation $conversation,
        AIProviderInterface $provider,
        StreamManager $streamManager,
        ToolRegistry $toolRegistry,
        SystemPromptBuilder $systemPromptBuilder,
        ModelRepository $modelRepository,
        array $options,
        int $iteration = 0,
        ?Message $userMessage = null,
    ): void {
        // Safety guard against infinite tool loops
        if ($iteration >= self::MAX_TOOL_ITERATIONS) {
            Log::warning('ProcessConversationStream: Max tool iterations reached', [
                'conversation' => $this->conversationUuid,
                'iterations' => $iteration,
            ]);
            $streamManager->appendEvent(
                $this->conversationUuid,
                StreamEvent::error('Maximum tool execution iterations reached (safety limit)')
            );
            // Throw exception to trigger the failStream logic in the catch block
            throw new \RuntimeException('Maximum tool execution iterations reached (safety limit)');
        }
        // Reload messages
        $conversation->load('messages');

        // Check for context reminders (interruption, agent change) on first iteration only
        $contextReminders = [];
        if ($iteration === 0) {
            if ($reminder = $this->getInterruptionReminder($conversation)) {
                $contextReminders[] = $reminder;
            }
            if ($reminder = $this->getAgentChangeReminder($conversation)) {
                $contextReminders[] = $reminder;
            }
        }
        // Combine all reminders into one string for injection
        $combinedReminder = !empty($contextReminders) ? implode("\n\n", $contextReminders) : null;

        // Build system prompt with tool instructions
        // CLI providers (Claude Code, Codex) use artisan commands instead of native tools
        $providerType = $provider->getProviderType();
        $providerEnum = Provider::tryFrom($providerType);
        if ($providerEnum?->isCliProvider()) {
            $systemPrompt = $systemPromptBuilder->buildForCliProvider($conversation, $providerType);
        } else {
            $systemPrompt = $systemPromptBuilder->build($conversation, $toolRegistry);
        }

        // Prepare provider options
        $providerOptions = array_merge($options, [
            'system' => $systemPrompt,
            'tools' => $toolRegistry->getDefinitions(),
            'interruption_reminder' => $combinedReminder,
        ]);

        // Track state for this turn
        $contentBlocks = [];
        $pendingToolUses = [];
        $streamedToolResults = []; // Tool results from providers that execute tools internally (Claude Code, Codex)
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheCreationTokens = null;
        $cacheReadTokens = null;
        $turnCost = 0.0;
        $stopReason = null;
        $currentToolInput = [];

        // Get the model for cost calculation
        $aiModel = $modelRepository->findByModelId($conversation->model);
        if (!$aiModel) {
            Log::warning('ProcessConversationStream: Model not found for cost calculation', [
                'conversation' => $this->conversationUuid,
                'model' => $conversation->model,
            ]);
        }

        // Stream from provider
        foreach ($provider->streamMessage($conversation, $providerOptions) as $event) {
            // Check abort flag BEFORE processing each event
            if ($streamManager->checkAbortFlag($this->conversationUuid)) {
                Log::info('ProcessConversationStream: Abort requested', [
                    'conversation' => $this->conversationUuid,
                ]);

                // Terminate the provider's stream
                $provider->abort();

                try {
                    // Save partial response - filter out incomplete blocks
                    // 1. Remove thinking blocks without signatures (required for multi-turn)
                    // 2. Remove incomplete tool_use blocks (empty input)
                    $contentBlocks = array_values(array_filter($contentBlocks, function ($block) {
                        $type = $block['type'] ?? '';

                        // Filter out thinking blocks without signatures
                        if ($type === 'thinking' && empty($block['signature'])) {
                            return false;
                        }

                        // Filter out incomplete tool_use blocks (never received TOOL_USE_STOP)
                        if ($type === 'tool_use') {
                            $input = $block['input'] ?? null;
                            // Check if input is an empty stdClass (has no properties)
                            if ($input instanceof \stdClass && empty((array) $input)) {
                                return false;
                            }
                        }

                        // Keep all complete blocks
                        return true;
                    }));

                    // Add an "interrupted" marker for UI display (filtered out when sent to API)
                    $contentBlocks[] = [
                        'type' => 'interrupted',
                        'reason' => 'user_abort',
                    ];

                    $assistantMessage = null;
                    if (!empty($contentBlocks)) {
                        $assistantMessage = $this->saveAssistantMessage(
                            $conversation,
                            $contentBlocks,
                            $inputTokens,
                            $outputTokens,
                            $cacheCreationTokens,
                            $cacheReadTokens,
                            'aborted',
                            $turnCost > 0 ? $turnCost : null
                        );

                        // Sync aborted message to provider's native storage (if applicable)
                        // This allows CLI providers to maintain session continuity on next resume
                        // BUT skip if the abort happened after tool execution completed
                        // (in that case, CLI already has complete data)
                        $shouldSkipSync = $streamManager->shouldSkipSyncOnAbort($this->conversationUuid);

                        if (!$shouldSkipSync) {
                            // Use the user message passed from handle() - no query needed
                            // This avoids ordering issues with the messages() relationship
                            if ($userMessage && $assistantMessage) {
                                $provider->syncAbortedMessage($conversation, $userMessage, $assistantMessage);
                            }
                        } else {
                            Log::info('ProcessConversationStream: Skipping sync (abort after tool completion)', [
                                'conversation' => $this->conversationUuid,
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('ProcessConversationStream: Error during abort save', [
                        'conversation' => $this->conversationUuid,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    // Calculate turns while still locked (even for aborted streams)
                    $this->calculateAndStoreTurns($conversation);

                    // Always complete stream and cleanup, even if save failed
                    $conversation->completeProcessing();
                    $streamManager->appendEvent($this->conversationUuid, StreamEvent::done('aborted'));
                    $streamManager->completeStream($this->conversationUuid, 'aborted');
                    $streamManager->clearAbortFlag($this->conversationUuid);

                    // Dispatch async embedding job
                    GenerateConversationEmbeddings::dispatch($conversation);
                }

                return; // Exit the method entirely
            }

            // For usage events, calculate cost and emit enriched event
            if ($event->type === StreamEvent::USAGE) {
                $inputTokens = $event->metadata['input_tokens'] ?? $inputTokens;
                $outputTokens = $event->metadata['output_tokens'] ?? $outputTokens;
                $cacheCreationTokens = $event->metadata['cache_creation_tokens'] ?? $cacheCreationTokens;
                $cacheReadTokens = $event->metadata['cache_read_tokens'] ?? $cacheReadTokens;

                // Calculate cost using model pricing
                $cost = null;
                if ($aiModel) {
                    $cost = $modelRepository->calculateCost(
                        $conversation->model,
                        $inputTokens,
                        $outputTokens,
                        $cacheCreationTokens,
                        $cacheReadTokens
                    );
                    $turnCost = $cost;
                }

                // Emit enriched usage event with cost and context info
                $enrichedEvent = StreamEvent::usage(
                    $inputTokens,
                    $outputTokens,
                    $cacheCreationTokens,
                    $cacheReadTokens,
                    $cost,
                    $conversation->context_window_size
                );
                $streamManager->appendEvent($this->conversationUuid, $enrichedEvent);
                continue;
            }

            // Publish other events to Redis for frontend
            $streamManager->appendEvent($this->conversationUuid, $event);

            // Track state
            switch ($event->type) {

                case StreamEvent::THINKING_START:
                    $contentBlocks[$event->blockIndex] = [
                        'type' => 'thinking',
                        'thinking' => '',
                    ];
                    break;

                case StreamEvent::THINKING_DELTA:
                    if (isset($contentBlocks[$event->blockIndex])) {
                        $contentBlocks[$event->blockIndex]['thinking'] .= $event->content;
                    }
                    break;

                case StreamEvent::THINKING_SIGNATURE:
                    if (isset($contentBlocks[$event->blockIndex])) {
                        $contentBlocks[$event->blockIndex]['signature'] = $event->content;
                    }
                    break;

                case StreamEvent::TEXT_START:
                    $contentBlocks[$event->blockIndex] = [
                        'type' => 'text',
                        'text' => '',
                    ];
                    break;

                case StreamEvent::TEXT_DELTA:
                    if (isset($contentBlocks[$event->blockIndex])) {
                        $contentBlocks[$event->blockIndex]['text'] .= $event->content;
                    }
                    break;

                case StreamEvent::TOOL_USE_START:
                    $contentBlocks[$event->blockIndex] = [
                        'type' => 'tool_use',
                        'id' => $event->metadata['tool_id'],
                        'name' => $event->metadata['tool_name'],
                        'input' => new \stdClass(),
                    ];
                    $currentToolInput[$event->blockIndex] = '';
                    break;

                case StreamEvent::TOOL_USE_DELTA:
                    if (isset($currentToolInput[$event->blockIndex])) {
                        $currentToolInput[$event->blockIndex] .= $event->content;
                    }
                    break;

                case StreamEvent::TOOL_USE_STOP:
                    if (isset($currentToolInput[$event->blockIndex])) {
                        $inputJson = $currentToolInput[$event->blockIndex];
                        $parsedInput = json_decode($inputJson, true);

                        if ($parsedInput === null && json_last_error() !== JSON_ERROR_NONE) {
                            Log::warning('ProcessConversationStream: Failed to parse tool input JSON', [
                                'block_index' => $event->blockIndex,
                                'tool_name' => $contentBlocks[$event->blockIndex]['name'] ?? 'unknown',
                                'tool_id' => $contentBlocks[$event->blockIndex]['id'] ?? 'unknown',
                                'json_error' => json_last_error_msg(),
                                'input_preview' => substr($inputJson, 0, 200),
                            ]);
                            $parsedInput = new \stdClass();
                        }

                        // Ensure input is an object (not array) for Anthropic API compatibility
                        // Empty arrays [] get encoded as JSON arrays, but API requires objects {}
                        $input = $parsedInput ?? new \stdClass();
                        if (is_array($input) && empty($input)) {
                            $input = new \stdClass();
                        }
                        $contentBlocks[$event->blockIndex]['input'] = $input;
                        $pendingToolUses[] = $contentBlocks[$event->blockIndex];
                        Log::channel('api')->info('ProcessConversationStream: Tool use completed', [
                            'block_index' => $event->blockIndex,
                            'tool_name' => $contentBlocks[$event->blockIndex]['name'] ?? 'unknown',
                            'tool_id' => $contentBlocks[$event->blockIndex]['id'] ?? 'unknown',
                            'input_json_length' => strlen($inputJson),
                            'pending_count' => count($pendingToolUses),
                        ]);
                    }
                    break;

                case StreamEvent::TOOL_RESULT:
                    // Collect tool results from providers that execute tools internally (Claude Code, Codex)
                    // These will be saved after the message completes if executeTools() doesn't run
                    $toolId = $event->metadata['tool_id'] ?? null;
                    if ($toolId) {
                        $streamedToolResults[] = [
                            'tool_use_id' => $toolId,
                            'content' => $event->content,
                            'is_error' => $event->metadata['is_error'] ?? false,
                        ];
                    } else {
                        Log::channel('api')->warning('ProcessConversationStream: TOOL_RESULT missing tool_id', [
                            'conversation' => $this->conversationUuid,
                        ]);
                    }
                    break;

                case StreamEvent::DONE:
                    $stopReason = $event->metadata['stop_reason'] ?? 'end_turn';
                    Log::channel('api')->info('ProcessConversationStream: Received DONE event', [
                        'stop_reason' => $stopReason,
                        'pending_tool_uses_count' => count($pendingToolUses),
                        'streamed_tool_results_count' => count($streamedToolResults),
                        'conversation' => $this->conversationUuid,
                    ]);
                    break;

                case StreamEvent::ERROR:
                    throw new \RuntimeException($event->content ?? 'Unknown streaming error');
            }
        }

        // Reindex content blocks
        $contentBlocks = array_values($contentBlocks);

        // Save assistant message
        $this->saveAssistantMessage(
            $conversation,
            $contentBlocks,
            $inputTokens,
            $outputTokens,
            $cacheCreationTokens,
            $cacheReadTokens,
            $stopReason,
            $turnCost > 0 ? $turnCost : null
        );

        // Handle tool execution
        Log::channel('api')->info('ProcessConversationStream: Checking tool execution', [
            'stop_reason' => $stopReason,
            'pending_tool_uses_count' => count($pendingToolUses),
            'will_execute' => ($stopReason === 'tool_use' && !empty($pendingToolUses)),
        ]);
        if ($stopReason === 'tool_use' && !empty($pendingToolUses)) {
            $toolResults = $this->executeTools($pendingToolUses, $conversation, $toolRegistry);

            // Publish tool results to Redis
            foreach ($toolResults as $result) {
                $streamManager->appendEvent(
                    $this->conversationUuid,
                    StreamEvent::toolResult(
                        $result['tool_use_id'],
                        $result['content'],
                        $result['is_error'] ?? false
                    )
                );
            }

            // Save tool results as user message
            $this->saveToolResultMessage($conversation, $toolResults);

            // Continue with tool results (recursive)
            // Pass the original user message for abort sync consistency
            $this->streamWithToolLoop(
                $conversation,
                $provider,
                $streamManager,
                $toolRegistry,
                $systemPromptBuilder,
                $modelRepository,
                $options,
                $iteration + 1,
                $userMessage
            );
        } elseif (!empty($streamedToolResults)) {
            // Save tool results from providers that execute tools internally (Claude Code, Codex)
            // These providers stream TOOL_RESULT events but use stopReason='end_turn',
            // so the executeTools path above doesn't run
            Log::channel('api')->info('ProcessConversationStream: Saving streamed tool results', [
                'count' => count($streamedToolResults),
                'conversation' => $this->conversationUuid,
            ]);
            $this->saveToolResultMessage($conversation, $streamedToolResults);
        }
    }

    private function saveUserMessage(Conversation $conversation, string $prompt): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => $prompt,
        ]);
    }

    private function saveAssistantMessage(
        Conversation $conversation,
        array $contentBlocks,
        int $inputTokens,
        int $outputTokens,
        ?int $cacheCreationTokens,
        ?int $cacheReadTokens,
        ?string $stopReason,
        ?float $cost = null
    ): Message {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $contentBlocks,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'stop_reason' => $stopReason,
            'model' => $conversation->model,
            'agent_id' => $conversation->agent_id,
            'cost' => $cost,
        ]);

        $conversation->addTokenUsage($inputTokens, $outputTokens);

        // Update context window tracking
        // Context estimate = input_tokens + output_tokens
        // This slightly overestimates because thinking tokens (in output) are stripped
        // from previous turns. But overestimating is safer than underestimating.
        if ($inputTokens > 0) {
            $conversation->updateContextUsage($inputTokens, $outputTokens);

            // Ensure context_window_size is set (for existing conversations that don't have it)
            if (!$conversation->context_window_size) {
                $provider = app(\App\Services\ProviderFactory::class)->make($conversation->provider_type);
                try {
                    $contextWindow = $provider->getContextWindow($conversation->model);
                    $conversation->updateContextWindowSize($contextWindow);
                } catch (\Exception $e) {
                    // Model not found or provider error - skip (will retry on next turn)
                    Log::debug('ProcessConversationStream: Failed to get context window', [
                        'conversation' => $this->conversationUuid,
                        'model' => $conversation->model,
                        'provider' => $conversation->provider_type,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $message;
    }

    private function saveToolResultMessage(Conversation $conversation, array $toolResults): Message
    {
        $content = [];
        foreach ($toolResults as $result) {
            $content[] = [
                'type' => 'tool_result',
                'tool_use_id' => $result['tool_use_id'],
                'content' => $result['content'],
                'is_error' => $result['is_error'] ?? false,
            ];
        }

        return Message::create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => $content,
        ]);
    }

    private function executeTools(array $toolUses, Conversation $conversation, ToolRegistry $toolRegistry): array
    {
        // Pass workspace to context so tools can access workspace-specific credentials
        $context = new ExecutionContext(
            $conversation->working_directory,
            workspace: $conversation->workspace
        );
        $results = [];

        foreach ($toolUses as $toolUse) {
            $result = $toolRegistry->execute(
                $toolUse['name'],
                $toolUse['input'],
                $context
            );

            $results[] = [
                'tool_use_id' => $toolUse['id'],
                'content' => $result->output,
                'is_error' => $result->isError,
            ];
        }

        return $results;
    }

    /**
     * Check if the previous assistant response was interrupted and return a reminder string.
     */
    private function getInterruptionReminder(Conversation $conversation): ?string
    {
        // Get the second-to-last assistant message (the one before the current user message)
        // We need to check if that assistant response has an 'interrupted' block
        $lastAssistantMessage = $conversation->messages()
            ->where('role', 'assistant')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastAssistantMessage) {
            return null;
        }

        $content = $lastAssistantMessage->content;
        if (!is_array($content)) {
            return null;
        }

        // Check for 'interrupted' block
        foreach ($content as $block) {
            if (isset($block['type']) && $block['type'] === 'interrupted') {
                return '<system-reminder>Your previous response was interrupted by the user. Completed content blocks have been retained. You may continue from where you left off or address the user\'s new message as appropriate.</system-reminder>';
            }
        }

        return null;
    }

    /**
     * Check if the agent has changed since the last assistant message.
     *
     * Returns a system reminder if the conversation's current agent differs
     * from the agent that produced the last assistant response.
     */
    private function getAgentChangeReminder(Conversation $conversation): ?string
    {
        // Get the last assistant message
        $lastAssistantMessage = $conversation->messages()
            ->where('role', 'assistant')
            ->orderBy('created_at', 'desc')
            ->first();

        // No previous assistant message - nothing to compare
        if (!$lastAssistantMessage) {
            return null;
        }

        // Compare agent IDs
        $previousAgentId = $lastAssistantMessage->agent_id;
        $currentAgentId = $conversation->agent_id;

        // No change if both null or same ID
        if ($previousAgentId === $currentAgentId) {
            return null;
        }

        // Agent has changed - build reminder with new agent name
        $newAgent = $conversation->agent;
        $agentName = $newAgent?->name ?? 'a different agent';

        return "<system-reminder>The user has switched to the '{$agentName}' agent. Your system prompt and available tools may have changed. Briefly acknowledge this context shift and proceed with your new instructions. If you're unsure how to apply your instructions to the current context, ask the user what is expected of you.</system-reminder>";
    }

    /**
     * Calculate turns and update turn_number on messages.
     * A turn = real user message → all messages until next real user message (with response).
     */
    private function calculateAndStoreTurns(Conversation $conversation): void
    {
        $turns = $this->calculateTurns($conversation);

        if (empty($turns)) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($turns) {
            foreach ($turns as $turnNumber => $messages) {
                $messageIds = collect($messages)->pluck('id');
                Message::whereIn('id', $messageIds)
                    ->update(['turn_number' => $turnNumber]);
            }
        });
    }

    /**
     * Calculate turns from conversation messages.
     * Returns array of turn_number => messages[]
     */
    private function calculateTurns(Conversation $conversation): array
    {
        $messages = $conversation->messages()->orderBy('sequence')->get();
        $turns = [];
        $currentTurn = null;
        $turnNumber = 0;
        $hasResponse = false;

        foreach ($messages as $message) {
            $isRealUserMessage = $message->role === 'user'
                && $this->hasRealUserContent($message);

            if ($isRealUserMessage) {
                if ($currentTurn !== null && $hasResponse) {
                    // Previous turn is complete, save it
                    $turns[$turnNumber] = $currentTurn;
                    $turnNumber++;
                    $currentTurn = [];
                    $hasResponse = false;
                }

                // Start or continue building current turn
                $currentTurn = $currentTurn ?? [];
                $currentTurn[] = $message;
            } else {
                // Assistant or tool_result message
                if ($currentTurn !== null) {
                    $currentTurn[] = $message;

                    if ($message->role === 'assistant') {
                        $hasResponse = true;
                    }
                }
            }
        }

        // Don't forget the last turn (if it has a response)
        if ($currentTurn !== null && $hasResponse) {
            $turns[$turnNumber] = $currentTurn;
        }

        return $turns;
    }

    /**
     * Check if message has real user text (not just tool_result).
     */
    private function hasRealUserContent(Message $message): bool
    {
        $content = $message->content;

        if (!is_array($content)) {
            return is_string($content) && !empty($content);
        }

        return collect($content)
            ->contains(fn($block) => ($block['type'] ?? '') === 'text');
    }
}
