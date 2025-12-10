<?php

namespace App\Services\Providers;

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI API provider.
 * Uses the Responses API for all models.
 */
class OpenAIProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private ModelRepository $models;

    public function __construct(ModelRepository $models)
    {
        $this->apiKey = config('ai.providers.openai.api_key', '');
        $this->baseUrl = config('ai.providers.openai.base_url', 'https://api.openai.com');
        $this->models = $models;
    }

    public function getProviderType(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getModels(): array
    {
        return $this->models->getModelsArray('openai');
    }

    public function getContextWindow(string $model): int
    {
        return $this->models->getContextWindow($model);
    }

    /**
     * Stream a message and yield StreamEvent objects.
     */
    public function streamMessage(
        Conversation $conversation,
        array $options = []
    ): Generator {
        if (!$this->isAvailable()) {
            yield StreamEvent::error('OpenAI API key not configured');

            return;
        }

        yield from $this->streamResponsesApi($conversation, $options);
    }

    /**
     * Build the input array for the OpenAI Responses API.
     *
     * Converts Anthropic-style messages to OpenAI Responses API format:
     * - User text → { role: "user", content: "text" }
     * - Assistant text → { role: "assistant", content: "text" }
     * - Assistant tool_use → { type: "function_call", call_id, name, arguments }
     * - User tool_result → { type: "function_call_output", call_id, output }
     */
    public function buildMessagesFromConversation(Conversation $conversation): array
    {
        $input = [];

        foreach ($conversation->messages as $message) {
            // Skip system messages - handled via 'instructions' parameter
            if ($message->role === 'system') {
                continue;
            }

            $content = $message->content;

            // Handle string content (simple user messages)
            if (is_string($content)) {
                $input[] = [
                    'role' => $message->role,
                    'content' => $content,
                ];
                continue;
            }

            // Handle array content (complex messages with blocks)
            if (is_array($content)) {
                foreach ($content as $block) {
                    $type = $block['type'] ?? null;

                    switch ($type) {
                        case 'text':
                            // Text block from assistant
                            $input[] = [
                                'role' => $message->role,
                                'content' => $block['text'],
                            ];
                            break;

                        case 'tool_use':
                            // Assistant's function call - convert to OpenAI format
                            $input[] = [
                                'type' => 'function_call',
                                'call_id' => $block['id'],
                                'name' => $block['name'],
                                'arguments' => is_array($block['input'])
                                    ? json_encode($block['input'])
                                    : ($block['input'] ?? '{}'),
                            ];
                            break;

                        case 'tool_result':
                            // Tool result - convert to OpenAI function_call_output
                            $input[] = [
                                'type' => 'function_call_output',
                                'call_id' => $block['tool_use_id'],
                                'output' => $block['content'] ?? '',
                            ];
                            break;

                        case 'thinking':
                            // Skip thinking blocks - OpenAI handles reasoning internally
                            break;
                    }
                }
            }
        }

        return $input;
    }

    /**
     * Stream using the Responses API.
     */
    private function streamResponsesApi(Conversation $conversation, array $options): Generator
    {
        $model = $conversation->model ?? config('ai.providers.openai.default_model');
        $url = rtrim($this->baseUrl, '/') . '/v1/responses';

        // Build input array from conversation messages
        $input = $this->buildMessagesFromConversation($conversation);

        // Response level determines max_output_tokens
        $responseLevel = $options['response_level'] ?? config('ai.response.default_level', 1);
        $responseConfig = config("ai.response.levels.{$responseLevel}");
        $maxTokens = $responseConfig['tokens'] ?? 8192;

        $body = [
            'model' => $model,
            'input' => $input,
            'stream' => true,
            'max_output_tokens' => $maxTokens,
        ];

        // Add system instructions if provided
        if (!empty($options['system'])) {
            $body['instructions'] = $options['system'];
        }

        // Add tools if provided
        if (!empty($options['tools'])) {
            $body['tools'] = $this->convertToolsToResponsesApi($options['tools']);
        }

        // Add reasoning configuration from conversation settings
        $reasoningConfig = $conversation->getReasoningConfig();
        $effort = $reasoningConfig['effort'] ?? 'none';

        if ($effort !== 'none') {
            $body['reasoning'] = [
                'effort' => $effort,
            ];

            // Add summary only if user wants to see thinking
            $summary = $reasoningConfig['summary'] ?? null;
            if ($summary !== null) {
                $body['reasoning']['summary'] = $summary;
            }
        }

        $client = new \GuzzleHttp\Client();

        Log::channel('api')->info('OpenAIProvider: RESPONSES API REQUEST', [
            'url' => $url,
            'model' => $model,
            'input_count' => count($input),
            'max_output_tokens' => $maxTokens,
            'has_reasoning' => isset($body['reasoning']),
            'has_tools' => isset($body['tools']),
        ]);

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'json' => $body,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            $buffer = '';
            $eventCount = 0;

            // Track state for different content types
            $state = [
                'textStarted' => false,
                'thinkingStarted' => false,
                'currentFunctionCall' => null, // {id, name, call_id, arguments}
                'blockIndex' => 0,
                'hasToolCalls' => false, // Track if any tool calls were made
                'doneEmitted' => false, // Track if done event was already emitted
            ];

            while (!$stream->eof()) {
                $chunk = $stream->read(64);

                if ($chunk === '') {
                    usleep(1000);
                    continue;
                }

                $buffer .= $chunk;

                // Parse SSE events (single newline separator for Responses API)
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    if (empty(trim($line))) {
                        continue;
                    }

                    if (str_starts_with($line, 'data:')) {
                        $data = trim(substr($line, 5));

                        if ($data === '[DONE]') {
                            // [DONE] is a fallback - response.completed should have already emitted done
                            if ($state['doneEmitted']) {
                                Log::channel('api')->debug('OpenAIProvider: [DONE] received but done already emitted');
                                continue;
                            }
                            Log::channel('api')->info('OpenAIProvider: [DONE] received', [
                                'hasToolCalls' => $state['hasToolCalls'],
                                'textStarted' => $state['textStarted'],
                                'thinkingStarted' => $state['thinkingStarted'],
                                'blockIndex' => $state['blockIndex'],
                            ]);
                            if ($state['textStarted']) {
                                yield StreamEvent::textStop($state['blockIndex']);
                            }
                            if ($state['thinkingStarted']) {
                                yield StreamEvent::thinkingStop($state['blockIndex']);
                            }
                            // Use 'tool_use' stop reason if any tool calls were made
                            $stopReason = $state['hasToolCalls'] ? 'tool_use' : 'end_turn';
                            Log::channel('api')->info('OpenAIProvider: Emitting done event from [DONE]', [
                                'stop_reason' => $stopReason,
                            ]);
                            yield StreamEvent::done($stopReason);
                            $state['doneEmitted'] = true;
                            continue;
                        }

                        $eventCount++;
                        $prevHasToolCalls = $state['hasToolCalls'];
                        yield from $this->parseResponsesApiEvent($data, $state);
                        if ($state['hasToolCalls'] !== $prevHasToolCalls) {
                            Log::channel('api')->info('OpenAIProvider: hasToolCalls changed after parseResponsesApiEvent', [
                                'from' => $prevHasToolCalls,
                                'to' => $state['hasToolCalls'],
                            ]);
                        }
                    }
                }
            }

            Log::debug('OpenAIProvider: Responses API stream completed', [
                'event_count' => $eventCount,
            ]);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($responseBody, true);
            $errorMessage = $errorData['error']['message'] ?? $e->getMessage();

            Log::channel('api')->error('OpenAIProvider: RESPONSES API ERROR', [
                'status' => $e->getResponse()->getStatusCode(),
                'error' => $errorMessage,
                'raw_response' => $responseBody,
                'model' => $model,
            ]);

            yield StreamEvent::error($errorMessage);
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();
            Log::channel('api')->error('OpenAIProvider: SERVER ERROR', [
                'status' => $e->getResponse()->getStatusCode(),
                'raw_response' => $responseBody,
            ]);
            yield StreamEvent::error('OpenAI server error: ' . $e->getResponse()->getStatusCode());
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::channel('api')->error('OpenAIProvider: CONNECTION ERROR', [
                'error' => $e->getMessage(),
            ]);
            yield StreamEvent::error('Failed to connect to OpenAI API');
        } catch (\Exception $e) {
            Log::channel('api')->error('OpenAIProvider: UNEXPECTED ERROR', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            yield StreamEvent::error($e->getMessage());
        }
    }

    /**
     * Parse a Responses API streaming event.
     */
    private function parseResponsesApiEvent(string $data, array &$state): Generator
    {
        $payload = json_decode($data, true);

        if ($payload === null) {
            Log::warning('OpenAIProvider: Failed to parse JSON', [
                'data' => substr($data, 0, 500),
            ]);
            return;
        }

        $type = $payload['type'] ?? '';

        // Log non-delta events for debugging
        if (!str_contains($type, '.delta')) {
            Log::channel('api')->debug('OpenAIProvider: RESPONSES API EVENT', [
                'type' => $type,
                'payload_keys' => array_keys($payload),
            ]);
        }

        switch ($type) {
            // === Text output events ===
            case 'response.output_text.delta':
                if (!$state['textStarted']) {
                    yield StreamEvent::textStart($state['blockIndex']);
                    $state['textStarted'] = true;
                }
                $delta = $payload['delta'] ?? '';
                if ($delta !== '') {
                    yield StreamEvent::textDelta($state['blockIndex'], $delta);
                }
                break;

            case 'response.output_text.done':
                if ($state['textStarted']) {
                    yield StreamEvent::textStop($state['blockIndex']);
                    $state['textStarted'] = false;
                    $state['blockIndex']++;
                }
                break;

            // === Reasoning/thinking events ===
            // Note: OpenAI sends both summary_text and summary_part events for the same content.
            // We only handle summary_part events to avoid duplicate thinking blocks.
            case 'response.reasoning_text.delta':
                if (!$state['thinkingStarted']) {
                    yield StreamEvent::thinkingStart($state['blockIndex']);
                    $state['thinkingStarted'] = true;
                }
                $delta = $payload['delta'] ?? '';
                if ($delta !== '') {
                    yield StreamEvent::thinkingDelta($state['blockIndex'], $delta);
                }
                break;

            case 'response.reasoning_text.done':
                if ($state['thinkingStarted']) {
                    yield StreamEvent::thinkingStop($state['blockIndex']);
                    $state['thinkingStarted'] = false;
                    $state['blockIndex']++;
                }
                break;

            // Reasoning summary part events - these contain the summary text
            case 'response.reasoning_summary_part.added':
                if (!$state['thinkingStarted']) {
                    yield StreamEvent::thinkingStart($state['blockIndex']);
                    $state['thinkingStarted'] = true;
                }
                break;

            case 'response.reasoning_summary_text.delta':
                // Stream the summary text as it arrives
                if (!$state['thinkingStarted']) {
                    yield StreamEvent::thinkingStart($state['blockIndex']);
                    $state['thinkingStarted'] = true;
                }
                $delta = $payload['delta'] ?? '';
                if ($delta !== '') {
                    yield StreamEvent::thinkingDelta($state['blockIndex'], $delta);
                }
                break;

            case 'response.reasoning_summary_part.done':
                // Close the thinking block when the part is done
                if ($state['thinkingStarted']) {
                    yield StreamEvent::thinkingStop($state['blockIndex']);
                    $state['thinkingStarted'] = false;
                    $state['blockIndex']++;
                }
                break;

            // Ignore these - they duplicate the summary_part events
            case 'response.reasoning_summary_text.done':
                break;

            // === Function call events ===
            case 'response.output_item.added':
                $item = $payload['item'] ?? [];
                Log::channel('api')->debug('OpenAIProvider: output_item.added', [
                    'item_type' => $item['type'] ?? 'unknown',
                    'item_keys' => array_keys($item),
                    'item' => $item,
                ]);
                if (($item['type'] ?? '') === 'function_call') {
                    $state['hasToolCalls'] = true;
                    $state['currentFunctionCall'] = [
                        'id' => $item['id'] ?? '',
                        'call_id' => $item['call_id'] ?? '',
                        'name' => $item['name'] ?? '',
                        'arguments' => '',
                    ];
                    Log::channel('api')->info('OpenAIProvider: Starting tool use - SET hasToolCalls=true', [
                        'tool_name' => $state['currentFunctionCall']['name'],
                        'call_id' => $state['currentFunctionCall']['call_id'],
                        'hasToolCalls' => $state['hasToolCalls'],
                    ]);
                    yield StreamEvent::toolUseStart(
                        $state['blockIndex'],
                        $state['currentFunctionCall']['call_id'],
                        $state['currentFunctionCall']['name']
                    );
                }
                break;

            case 'response.function_call_arguments.delta':
                $delta = $payload['delta'] ?? '';
                if ($delta !== '' && $state['currentFunctionCall'] !== null) {
                    $state['currentFunctionCall']['arguments'] .= $delta;
                    yield StreamEvent::toolUseDelta($state['blockIndex'], $delta);
                }
                break;

            case 'response.function_call_arguments.done':
                if ($state['currentFunctionCall'] !== null) {
                    // The done event contains the full arguments - emit them if we haven't already
                    $arguments = $payload['arguments'] ?? '';
                    if ($arguments !== '' && $state['currentFunctionCall']['arguments'] === '') {
                        // No deltas were received, emit the full arguments now
                        yield StreamEvent::toolUseDelta($state['blockIndex'], $arguments);
                    }
                    Log::channel('api')->debug('OpenAIProvider: Tool use complete', [
                        'tool_name' => $state['currentFunctionCall']['name'],
                        'arguments' => $arguments,
                    ]);
                    yield StreamEvent::toolUseStop($state['blockIndex']);
                    $state['currentFunctionCall'] = null;
                    $state['blockIndex']++;
                }
                break;

            // === Completion events ===
            case 'response.completed':
                // Close any open blocks
                if ($state['textStarted']) {
                    yield StreamEvent::textStop($state['blockIndex']);
                    $state['textStarted'] = false;
                }
                if ($state['thinkingStarted']) {
                    yield StreamEvent::thinkingStop($state['blockIndex']);
                    $state['thinkingStarted'] = false;
                }

                // Extract usage from completed event
                $usage = $payload['response']['usage'] ?? $payload['usage'] ?? null;
                if ($usage) {
                    yield StreamEvent::usage(
                        $usage['input_tokens'] ?? 0,
                        $usage['output_tokens'] ?? 0
                    );
                }

                // Emit done event with correct stop reason
                // OpenAI Responses API uses 'response.completed' instead of [DONE]
                if (!$state['doneEmitted']) {
                    $stopReason = $state['hasToolCalls'] ? 'tool_use' : 'end_turn';
                    Log::channel('api')->info('OpenAIProvider: response.completed - emitting done', [
                        'hasToolCalls' => $state['hasToolCalls'],
                        'stop_reason' => $stopReason,
                    ]);
                    yield StreamEvent::done($stopReason);
                    $state['doneEmitted'] = true;
                }
                break;
        }
    }

    /**
     * Convert tools to Responses API format.
     */
    private function convertToolsToResponsesApi(array $tools): array
    {
        return array_map(function ($tool) {
            return [
                'type' => 'function',
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $tool['input_schema'] ?? ['type' => 'object', 'properties' => []],
            ];
        }, $tools);
    }
}
