<?php

namespace App\Services\Providers;

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
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

    public function __construct()
    {
        $this->apiKey = config('ai.providers.anthropic.api_key', '');
        $this->baseUrl = config('ai.providers.anthropic.base_url', 'https://api.anthropic.com');
        $this->apiVersion = config('ai.providers.anthropic.api_version', '2023-06-01');
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
        return [
            'claude-sonnet-4-5-20250929' => [
                'name' => 'Claude Sonnet 4.5',
                'context_window' => 200000,
            ],
            'claude-opus-4-5-20251101' => [
                'name' => 'Claude Opus 4.5',
                'context_window' => 200000,
            ],
            'claude-3-5-sonnet-20241022' => [
                'name' => 'Claude 3.5 Sonnet',
                'context_window' => 200000,
            ],
            'claude-3-opus-20240229' => [
                'name' => 'Claude 3 Opus',
                'context_window' => 200000,
            ],
            'claude-3-haiku-20240307' => [
                'name' => 'Claude 3 Haiku',
                'context_window' => 200000,
            ],
        ];
    }

    public function getContextWindow(string $model): int
    {
        $windows = config('ai.context_windows', []);

        return $windows[$model] ?? $windows['default'] ?? 200000;
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
     */
    public function buildMessagesFromConversation(Conversation $conversation): array
    {
        $messages = [];

        foreach ($conversation->messages as $message) {
            // Skip system messages (handled separately in Anthropic API)
            if ($message->role === 'system') {
                continue;
            }

            $messages[] = [
                'role' => $message->role,
                'content' => $message->content,
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

        $body = [
            'model' => $conversation->model ?? config('ai.providers.anthropic.default_model'),
            'max_tokens' => $options['max_tokens'] ?? config('ai.providers.anthropic.max_tokens', 8192),
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
        $thinkingLevel = $options['thinking_level'] ?? 0;
        if ($thinkingLevel > 0) {
            $thinkingConfig = config("ai.thinking.levels.{$thinkingLevel}");
            if ($thinkingConfig && $thinkingConfig['budget_tokens'] > 0) {
                $body['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => $thinkingConfig['budget_tokens'],
                ];
            }
        }

        return $body;
    }

    /**
     * Make a streaming request to the Anthropic API and yield StreamEvent objects.
     *
     * Uses proc_open with curl for true real-time SSE streaming.
     */
    private function streamRequest(array $body): Generator
    {
        $url = rtrim($this->baseUrl, '/') . '/v1/messages';

        // Build curl command for streaming
        $jsonBody = json_encode($body);
        $command = sprintf(
            'curl -sS -N -X POST %s ' .
            '-H "Content-Type: application/json" ' .
            '-H "x-api-key: %s" ' .
            '-H "anthropic-version: %s" ' .
            '-d %s 2>&1',
            escapeshellarg($url),
            escapeshellarg($this->apiKey),
            escapeshellarg($this->apiVersion),
            escapeshellarg($jsonBody)
        );

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            yield StreamEvent::error('Failed to start curl process');

            return;
        }

        // Close stdin - we don't need it
        fclose($pipes[0]);

        // Set stdout to non-blocking for responsive reading
        stream_set_blocking($pipes[1], false);

        // Track state for SSE parsing
        $buffer = '';
        $currentBlocks = [];
        $httpError = null;

        // Read stdout incrementally
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);

            if ($chunk === false || $chunk === '') {
                // No data available yet, small sleep to prevent CPU spinning
                usleep(1000);

                continue;
            }

            $buffer .= $chunk;

            // Check for HTTP error response (non-SSE JSON error)
            if ($httpError === null && str_starts_with(trim($buffer), '{')) {
                // Might be an error response, check if it's complete
                $errorData = json_decode(trim($buffer), true);
                if ($errorData !== null && isset($errorData['error'])) {
                    $httpError = $errorData['error']['message'] ?? 'Unknown API error';
                    break;
                }
            }

            // Parse SSE events from buffer
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                if (empty(trim($event))) {
                    continue;
                }

                yield from $this->parseSSEEvent($event, $currentBlocks);
            }
        }

        // Process any remaining buffer
        if (!empty(trim($buffer)) && $httpError === null) {
            // Check if remaining buffer is an error
            $errorData = json_decode(trim($buffer), true);
            if ($errorData !== null && isset($errorData['error'])) {
                $httpError = $errorData['error']['message'] ?? 'Unknown API error';
            } else {
                yield from $this->parseSSEEvent($buffer, $currentBlocks);
            }
        }

        // Read any stderr
        $stderr = stream_get_contents($pipes[2]);

        // Clean up
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Handle errors
        if ($httpError !== null) {
            yield StreamEvent::error($httpError);
        } elseif ($exitCode !== 0) {
            $errorMsg = $stderr ?: "curl exited with code {$exitCode}";
            yield StreamEvent::error($errorMsg);
        }
    }

    /**
     * Parse a single SSE event and yield StreamEvent(s).
     */
    private function parseSSEEvent(
        string $event,
        array &$currentBlocks
    ): Generator {
        $lines = explode("\n", $event);
        $eventType = null;
        $data = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'event:')) {
                $eventType = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));
            }
        }

        if ($data === null) {
            return;
        }

        $payload = json_decode($data, true);

        if ($payload === null) {
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
                }
                break;

            case 'message_stop':
                // Just a marker event, done already emitted from message_delta
                break;

            case 'error':
                $errorMessage = $payload['error']['message'] ?? 'Unknown error';
                yield StreamEvent::error($errorMessage);
                break;
        }
    }
}
