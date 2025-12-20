<?php

namespace App\Services\Providers;

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
use App\Services\AppSettingsService;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Direct Anthropic API provider.
 * Makes streaming requests to the Messages API and converts events to StreamEvent.
 */
class AnthropicProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private string $apiVersion;
    private ModelRepository $models;

    public function __construct(ModelRepository $models, AppSettingsService $settings)
    {
        // API key from database (set via UI)
        $this->apiKey = $settings->getAnthropicApiKey() ?? '';
        $this->baseUrl = config('ai.providers.anthropic.base_url', 'https://api.anthropic.com');
        $this->apiVersion = config('ai.providers.anthropic.api_version', '2023-06-01');
        $this->models = $models;
    }

    public function getProviderType(): string
    {
        return 'anthropic';
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getModels(): array
    {
        return $this->models->getModelsArray('anthropic');
    }

    public function getContextWindow(string $model): int
    {
        return $this->models->getContextWindow($model);
    }

    /**
     * Stream a message and yield StreamEvent objects.
     *
     * @param Conversation $conversation The conversation context (messages should be saved first)
     * @param array $options Additional options (tools, thinking level, system prompt, etc.)
     * @return Generator<StreamEvent>
     */
    public function streamMessage(
        Conversation $conversation,
        array $options = []
    ): Generator {
        if (!$this->isAvailable()) {
            yield StreamEvent::error('Anthropic API key not configured');

            return;
        }

        // Build request body
        $body = $this->buildRequestBody($conversation, $options);

        // Make streaming request
        yield from $this->streamRequest($body);
    }

    /**
     * Build the messages array for the API request.
     * Reads from the conversation's stored messages.
     *
     * Handles both string content (simple user messages) and
     * array content (assistant messages with thinking/tool blocks).
     * Preserves thinking signatures for multi-turn conversations.
     */
    public function buildMessagesFromConversation(Conversation $conversation): array
    {
        $messages = [];

        foreach ($conversation->messages as $message) {
            // Skip system messages (handled separately in Anthropic API)
            if ($message->role === 'system') {
                continue;
            }

            $content = $message->content;

            // Ensure user messages with simple strings are properly formatted
            // Anthropic accepts both string content and array of content blocks
            if ($message->role === 'user' && is_string($content)) {
                $content = [['type' => 'text', 'text' => $content]];
            }

            $messages[] = [
                'role' => $message->role,
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * Build the full request body for the Anthropic API.
     */
    private function buildRequestBody(Conversation $conversation, array $options): array
    {
        // Get all messages from conversation (should already include new user message)
        $messages = $this->buildMessagesFromConversation($conversation);

        // Get reasoning config from conversation (provider-specific)
        $reasoningConfig = $conversation->getReasoningConfig();
        $budgetTokens = $reasoningConfig['budget_tokens'] ?? 0;

        // Response level from conversation or options
        $responseLevel = $conversation->response_level ?? $options['response_level'] ?? config('ai.response.default_level', 1);
        $responseConfig = config("ai.response.levels.{$responseLevel}");

        // Response budget from selected response level
        $responseTokens = $responseConfig['tokens'] ?? 8192;

        // max_tokens = thinking budget + response budget
        $maxTokens = $budgetTokens + $responseTokens;

        $body = [
            'model' => $conversation->model ?? config('ai.providers.anthropic.default_model'),
            'max_tokens' => $maxTokens,
            'stream' => true,
            'messages' => $messages,
        ];

        // Add system prompt if provided
        if (!empty($options['system'])) {
            $body['system'] = $options['system'];
        }

        // Add tools if provided
        if (!empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Add thinking configuration if enabled
        if ($budgetTokens > 0) {
            $body['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => $budgetTokens,
            ];
        }

        return $body;
    }

    /**
     * Make a streaming request to the Anthropic API and yield StreamEvent objects.
     *
     * Uses Guzzle with streaming response for real-time SSE.
     */
    private function streamRequest(array $body): Generator
    {
        $url = rtrim($this->baseUrl, '/') . '/v1/messages';

        $client = new \GuzzleHttp\Client();

        // Log full raw request for debugging
        Log::channel('api')->info('AnthropicProvider: RAW REQUEST', [
            'url' => $url,
            'body' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);

        Log::debug('AnthropicProvider: Starting stream request', [
            'model' => $body['model'] ?? 'unknown',
            'max_tokens' => $body['max_tokens'] ?? 0,
            'has_thinking' => isset($body['thinking']),
            'thinking_budget' => $body['thinking']['budget_tokens'] ?? 0,
            'message_count' => count($body['messages'] ?? []),
        ]);

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => $this->apiVersion,
                ],
                'json' => $body,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            $buffer = '';
            $currentBlocks = [];
            $eventCount = 0;
            $doneEmitted = false;

            // Read stream in chunks
            while (!$stream->eof()) {
                $chunk = $stream->read(64);

                if ($chunk === '') {
                    usleep(1000);
                    continue;
                }

                $buffer .= $chunk;

                // Parse SSE events from buffer
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    if (empty(trim($event))) {
                        continue;
                    }

                    $eventCount++;
                    yield from $this->parseSSEEvent($event, $currentBlocks, $doneEmitted);
                }
            }

            // Process any remaining buffer
            if (!empty(trim($buffer))) {
                $eventCount++;
                yield from $this->parseSSEEvent($buffer, $currentBlocks, $doneEmitted);
            }

            Log::debug('AnthropicProvider: Stream completed', [
                'event_count' => $eventCount,
                'block_count' => count($currentBlocks),
            ]);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($responseBody, true);
            $errorMessage = $errorData['error']['message'] ?? $e->getMessage();
            Log::error('AnthropicProvider: Client exception', [
                'error' => $errorMessage,
                'status' => $e->getResponse()->getStatusCode(),
            ]);
            yield StreamEvent::error($errorMessage);
        } catch (\Exception $e) {
            Log::error('AnthropicProvider: Exception', ['error' => $e->getMessage()]);
            yield StreamEvent::error($e->getMessage());
        }
    }

    /**
     * Parse a single SSE event and yield StreamEvent(s).
     *
     * Note: SSE data can span multiple lines. We concatenate all data: lines.
     */
    private function parseSSEEvent(
        string $event,
        array &$currentBlocks,
        bool &$doneEmitted
    ): Generator {
        $lines = explode("\n", $event);
        $eventType = null;
        $dataLines = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'event:')) {
                $eventType = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                // Concatenate all data lines (SSE spec allows multi-line data)
                $dataLines[] = trim(substr($line, 5));
            }
        }

        if (empty($dataLines)) {
            return;
        }

        // Join data lines (Anthropic typically sends single-line JSON, but be safe)
        $data = implode('', $dataLines);

        // Log raw SSE event for debugging (skip delta events to reduce noise)
        if (!in_array($eventType, ['content_block_delta'])) {
            Log::channel('api')->debug('AnthropicProvider: RAW SSE EVENT', [
                'event_type' => $eventType,
                'data' => $data,
            ]);
        }

        $payload = json_decode($data, true);

        if ($payload === null) {
            Log::warning('AnthropicProvider: Failed to parse JSON', [
                'event_type' => $eventType,
                'data' => substr($data, 0, 500),
            ]);
            return;
        }

        switch ($eventType) {
            case 'message_start':
                // Initial usage is in message_start
                if (isset($payload['message']['usage'])) {
                    yield StreamEvent::usage(
                        $payload['message']['usage']['input_tokens'] ?? 0,
                        $payload['message']['usage']['output_tokens'] ?? 0,
                        $payload['message']['usage']['cache_creation_input_tokens'] ?? null,
                        $payload['message']['usage']['cache_read_input_tokens'] ?? null
                    );
                }
                break;

            case 'content_block_start':
                $index = $payload['index'];
                $block = $payload['content_block'];
                $currentBlocks[$index] = $block;

                switch ($block['type']) {
                    case 'thinking':
                        yield StreamEvent::thinkingStart($index);
                        break;
                    case 'text':
                        yield StreamEvent::textStart($index);
                        break;
                    case 'tool_use':
                        yield StreamEvent::toolUseStart(
                            $index,
                            $block['id'],
                            $block['name']
                        );
                        break;
                }
                break;

            case 'content_block_delta':
                $index = $payload['index'];
                $delta = $payload['delta'];

                switch ($delta['type']) {
                    case 'thinking_delta':
                        yield StreamEvent::thinkingDelta($index, $delta['thinking']);
                        break;
                    case 'signature_delta':
                        yield StreamEvent::thinkingSignature($index, $delta['signature']);
                        break;
                    case 'text_delta':
                        yield StreamEvent::textDelta($index, $delta['text']);
                        break;
                    case 'input_json_delta':
                        yield StreamEvent::toolUseDelta($index, $delta['partial_json']);
                        break;
                }
                break;

            case 'content_block_stop':
                $index = $payload['index'];
                $blockType = $currentBlocks[$index]['type'] ?? null;

                switch ($blockType) {
                    case 'thinking':
                        yield StreamEvent::thinkingStop($index);
                        break;
                    case 'text':
                        yield StreamEvent::textStop($index);
                        break;
                    case 'tool_use':
                        yield StreamEvent::toolUseStop($index);
                        break;
                }
                break;

            case 'message_delta':
                // Final usage update
                if (isset($payload['usage'])) {
                    yield StreamEvent::usage(
                        $payload['usage']['input_tokens'] ?? 0,
                        $payload['usage']['output_tokens'] ?? 0
                    );
                }
                // stop_reason is in delta
                if (isset($payload['delta']['stop_reason'])) {
                    yield StreamEvent::done($payload['delta']['stop_reason']);
                    $doneEmitted = true;
                }
                break;

            case 'message_stop':
                // Fallback: emit done if it wasn't already emitted in message_delta
                if (!$doneEmitted) {
                    yield StreamEvent::done('end_turn');
                    $doneEmitted = true;
                }
                break;

            case 'error':
                $errorMessage = $payload['error']['message'] ?? 'Unknown error';
                yield StreamEvent::error($errorMessage);
                break;
        }
    }
}
