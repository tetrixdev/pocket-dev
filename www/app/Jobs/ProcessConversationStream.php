<?php

namespace App\Jobs;

use App\Contracts\AIProviderInterface;
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
class ProcessConversationStream implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes max
    public int $tries = 1;     // Don't retry failed streams

    public function __construct(
        public string $conversationUuid,
        public string $prompt,
        public array $options = [],
    ) {}

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
            // Save user message
            $this->saveUserMessage($conversation, $this->prompt);

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
                $this->options
            );

            // Mark conversation as completed
            $conversation->completeProcessing();
            $streamManager->completeStream($this->conversationUuid);

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

        // Build system prompt with tool instructions
        $systemPrompt = $systemPromptBuilder->build($conversation, $toolRegistry);

        // Prepare provider options
        $providerOptions = array_merge($options, [
            'system' => $systemPrompt,
            'tools' => $toolRegistry->getDefinitions(),
        ]);

        // Track state for this turn
        $contentBlocks = [];
        $pendingToolUses = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheCreationTokens = null;
        $cacheReadTokens = null;
        $turnCost = 0.0;
        $stopReason = null;
        $currentToolInput = [];

        // Get the model for cost calculation
        $aiModel = $modelRepository->findByModelId($conversation->model);

        // Stream from provider
        foreach ($provider->streamMessage($conversation, $providerOptions) as $event) {
            // For usage events, calculate cost and emit enriched event
            if ($event->type === StreamEvent::USAGE) {
                $inputTokens = $event->metadata['input_tokens'] ?? $inputTokens;
                $outputTokens = $event->metadata['output_tokens'] ?? $outputTokens;
                $cacheCreationTokens = $event->metadata['cache_creation_tokens'] ?? $cacheCreationTokens;
                $cacheReadTokens = $event->metadata['cache_read_tokens'] ?? $cacheReadTokens;

                // Calculate cost using model pricing
                $cost = null;
                if ($aiModel) {
                    $cost = $aiModel->calculateCost(
                        $inputTokens,
                        $outputTokens,
                        $cacheCreationTokens,
                        $cacheReadTokens
                    );
                    $turnCost = $cost;
                }

                // Emit enriched usage event with cost
                $enrichedEvent = StreamEvent::usage(
                    $inputTokens,
                    $outputTokens,
                    $cacheCreationTokens,
                    $cacheReadTokens,
                    $cost
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
                        'input' => [],
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
                            $parsedInput = [];
                        }

                        $contentBlocks[$event->blockIndex]['input'] = $parsedInput ?? [];
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

                case StreamEvent::DONE:
                    $stopReason = $event->metadata['stop_reason'] ?? 'end_turn';
                    Log::channel('api')->info('ProcessConversationStream: Received DONE event', [
                        'stop_reason' => $stopReason,
                        'pending_tool_uses_count' => count($pendingToolUses),
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
            $this->streamWithToolLoop(
                $conversation,
                $provider,
                $streamManager,
                $toolRegistry,
                $systemPromptBuilder,
                $modelRepository,
                $options,
                $iteration + 1
            );
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
            'cost' => $cost,
        ]);

        $conversation->addTokenUsage($inputTokens, $outputTokens);

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
        $context = new ExecutionContext($conversation->working_directory);
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
}
