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
     * Build the messages array for the API request (for reference/debugging).
     */
    public function buildMessagesFromConversation(Conversation $conversation): array
    {
        $messages = [];

        foreach ($conversation->messages as $message) {
            $content = $message->content;

            // Handle array content (extract text)
            if (is_array($content)) {
                $textParts = [];
                foreach ($content as $block) {
                    if (isset($block['type']) && $block['type'] === 'text') {
                        $textParts[] = $block['text'];
                    }
                }
                $content = implode("\n", $textParts);
            }

            if (empty($content)) {
                continue;
            }

            $messages[] = [
                'role' => $message->role,
                'content' => $content,
            ];
        }

        return $messages;
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

        $client = new \GuzzleHttp\Client();

        Log::channel('api')->info('OpenAIProvider: RESPONSES API REQUEST', [
            'url' => $url,
            'model' => $model,
            'input_count' => count($input),
            'max_output_tokens' => $maxTokens,
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
            $textStarted = false;
            $eventCount = 0;

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
                            if ($textStarted) {
                                yield StreamEvent::textStop(0);
                            }
                            yield StreamEvent::done('end_turn');
                            continue;
                        }

                        $eventCount++;
                        yield from $this->parseResponsesApiEvent($data, $textStarted);
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
    private function parseResponsesApiEvent(string $data, bool &$textStarted): Generator
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
        if ($type !== 'response.output_text.delta') {
            Log::channel('api')->debug('OpenAIProvider: RESPONSES API EVENT', [
                'type' => $type,
            ]);
        }

        switch ($type) {
            case 'response.output_text.delta':
                if (!$textStarted) {
                    yield StreamEvent::textStart(0);
                    $textStarted = true;
                }
                $delta = $payload['delta'] ?? '';
                if ($delta !== '') {
                    yield StreamEvent::textDelta(0, $delta);
                }
                break;

            case 'response.output_text.done':
                if ($textStarted) {
                    yield StreamEvent::textStop(0);
                    $textStarted = false;
                }
                break;

            case 'response.completed':
                // Extract usage from completed event
                $usage = $payload['response']['usage'] ?? $payload['usage'] ?? null;
                if ($usage) {
                    yield StreamEvent::usage(
                        $usage['input_tokens'] ?? 0,
                        $usage['output_tokens'] ?? 0
                    );
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
