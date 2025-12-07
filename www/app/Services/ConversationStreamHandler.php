<?php

namespace App\Services;

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
use App\Models\Message;
use App\Streaming\StreamEvent;
use App\Tools\ExecutionContext;
use App\Tools\ToolResult;
use Generator;

/**
 * Orchestrates streaming conversations with tool execution.
 *
 * Responsibilities:
 * - Save user messages to database
 * - Stream responses from AI provider
 * - Accumulate content blocks for storage
 * - Execute tools when requested
 * - Continue conversation with tool results
 * - Track token usage
 */
class ConversationStreamHandler
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private SystemPromptBuilder $systemPromptBuilder,
    ) {}

    /**
     * Stream a conversation turn and handle tool execution.
     *
     * @param Conversation $conversation The conversation
     * @param string $prompt The user's message
     * @param AIProviderInterface $provider The AI provider
     * @param array $options Additional options (thinking_level, max_tokens, etc.)
     * @return Generator<StreamEvent>
     */
    public function stream(
        Conversation $conversation,
        string $prompt,
        AIProviderInterface $provider,
        array $options = []
    ): Generator {
        // Mark conversation as processing
        $conversation->startProcessing();

        try {
            // Save user message first
            $this->saveUserMessage($conversation, $prompt);

            // Start the streaming loop (handles tool execution recursively)
            yield from $this->streamWithToolLoop($conversation, $provider, $options, false);

            // Mark conversation as idle
            $conversation->completeProcessing();
        } catch (\Throwable $e) {
            $conversation->markFailed();
            yield StreamEvent::error($e->getMessage());
        }
    }

    /**
     * Continue streaming after tool results (no new user message).
     */
    public function continueWithToolResults(
        Conversation $conversation,
        AIProviderInterface $provider,
        array $options = []
    ): Generator {
        yield from $this->streamWithToolLoop($conversation, $provider, $options, true);
    }

    /**
     * Stream and handle tool execution loop.
     *
     * @param bool $isToolContinuation Whether this is continuing after tool execution
     */
    private function streamWithToolLoop(
        Conversation $conversation,
        AIProviderInterface $provider,
        array $options,
        bool $isToolContinuation = false
    ): Generator {
        // Reload messages to ensure we have latest
        $conversation->load('messages');

        // Build system prompt with tool instructions
        $systemPrompt = $this->systemPromptBuilder->build($conversation, $this->toolRegistry);

        // Get tool definitions
        $toolDefinitions = $this->toolRegistry->getDefinitions();

        // Prepare provider options
        $providerOptions = array_merge($options, [
            'system' => $systemPrompt,
            'tools' => $toolDefinitions,
        ]);

        // Stream from provider (all messages are already in the conversation)
        $contentBlocks = [];
        $pendingToolUses = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheCreationTokens = null;
        $cacheReadTokens = null;
        $stopReason = null;
        $currentToolInput = [];

        foreach ($provider->streamMessage($conversation, $providerOptions) as $event) {
            // Forward event to frontend
            yield $event;

            // Process event for state tracking
            switch ($event->type) {
                case StreamEvent::USAGE:
                    $inputTokens = $event->metadata['input_tokens'] ?? $inputTokens;
                    $outputTokens = $event->metadata['output_tokens'] ?? $outputTokens;
                    $cacheCreationTokens = $event->metadata['cache_creation_tokens'] ?? $cacheCreationTokens;
                    $cacheReadTokens = $event->metadata['cache_read_tokens'] ?? $cacheReadTokens;
                    break;

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
                    // Parse the accumulated JSON input
                    if (isset($currentToolInput[$event->blockIndex])) {
                        $inputJson = $currentToolInput[$event->blockIndex];
                        $parsedInput = json_decode($inputJson, true) ?? [];
                        $contentBlocks[$event->blockIndex]['input'] = $parsedInput;
                        $pendingToolUses[] = $contentBlocks[$event->blockIndex];
                    }
                    break;

                case StreamEvent::DONE:
                    $stopReason = $event->metadata['stop_reason'] ?? 'end_turn';
                    break;

                case StreamEvent::ERROR:
                    // Error already yielded, just stop
                    return;
            }
        }

        // Reindex content blocks to remove gaps
        $contentBlocks = array_values($contentBlocks);

        // Save assistant message
        $this->saveAssistantMessage(
            $conversation,
            $contentBlocks,
            $inputTokens,
            $outputTokens,
            $cacheCreationTokens,
            $cacheReadTokens,
            $stopReason
        );

        // Handle tool execution
        if ($stopReason === 'tool_use' && !empty($pendingToolUses)) {
            $toolResults = $this->executeTools($pendingToolUses, $conversation);

            // Yield tool results to frontend
            foreach ($toolResults as $result) {
                yield StreamEvent::toolResult(
                    $result['tool_use_id'],
                    $result['content'],
                    $result['is_error'] ?? false
                );
            }

            // Save tool results as user message (Anthropic format)
            $this->saveToolResultMessage($conversation, $toolResults);

            // Continue conversation with tool results
            yield from $this->streamWithToolLoop($conversation, $provider, $options, true);
        }
    }

    /**
     * Save user message to database.
     */
    private function saveUserMessage(Conversation $conversation, string $prompt): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => $prompt, // Store as string for simple prompts
        ]);
    }

    /**
     * Save assistant message to database.
     */
    private function saveAssistantMessage(
        Conversation $conversation,
        array $contentBlocks,
        int $inputTokens,
        int $outputTokens,
        ?int $cacheCreationTokens,
        ?int $cacheReadTokens,
        ?string $stopReason
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
        ]);

        // Update conversation token totals
        $conversation->addTokenUsage($inputTokens, $outputTokens);

        return $message;
    }

    /**
     * Save tool results as a user message (Anthropic format).
     */
    private function saveToolResultMessage(Conversation $conversation, array $toolResults): Message
    {
        // Anthropic expects tool_result blocks in a user message
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

    /**
     * Execute pending tool uses.
     *
     * @param array $toolUses Array of tool_use blocks
     * @param Conversation $conversation For execution context
     * @return array Array of tool result arrays
     */
    private function executeTools(array $toolUses, Conversation $conversation): array
    {
        $context = new ExecutionContext($conversation->working_directory);
        $results = [];

        foreach ($toolUses as $toolUse) {
            $result = $this->toolRegistry->execute(
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
