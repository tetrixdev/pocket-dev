# Streaming Implementation

Real-time streaming of AI responses using Server-Sent Events (SSE) with Redis-backed event buffering.

## Architecture

```
Frontend                    Backend                          Provider APIs
────────                    ───────                          ─────────────
POST /stream ─────────────► ConversationController::stream()
                            │
                            ▼
                            Dispatch ProcessConversationStream job
                            │
                            ▼
                            Job runs in background
                            │
                            ├─► StreamManager::startStream()
                            │
                            ▼
                            ProviderFactory::make() ──────► AnthropicProvider
                            │                              or OpenAIProvider
                            ▼
                            Provider::streamMessage() ────► API (streaming)
                            │
                            ▼ (yields StreamEvent)
                            StreamManager::appendEvent() ◄─ Generator loop
                            │
                            ▼
                            Redis event buffer
                            │
GET /stream-events ◄────────┘ (SSE polling)
```

## StreamEvent

All providers normalize their API-specific events to `StreamEvent` objects.

**File:** `app/Streaming/StreamEvent.php`

```php
class StreamEvent
{
    // Event type constants
    public const THINKING_START = 'thinking_start';
    public const THINKING_DELTA = 'thinking_delta';
    public const THINKING_STOP = 'thinking_stop';
    public const TEXT_START = 'text_start';
    public const TEXT_DELTA = 'text_delta';
    public const TEXT_STOP = 'text_stop';
    public const TOOL_USE_START = 'tool_use_start';
    public const TOOL_USE_DELTA = 'tool_use_delta';
    public const TOOL_USE_STOP = 'tool_use_stop';
    public const TOOL_RESULT = 'tool_result';
    public const USAGE = 'usage';
    public const DONE = 'done';
    public const ERROR = 'error';

    public function __construct(
        public string $type,
        public ?int $blockIndex = null,
        public ?string $content = null,
        public ?array $metadata = null,
    ) {}

    // Static factory methods
    public static function textDelta(int $blockIndex, string $content): self;
    public static function thinkingDelta(int $blockIndex, string $content): self;
    public static function toolUseStart(int $blockIndex, string $toolId, string $toolName): self;
    public static function usage(int $inputTokens, int $outputTokens, ...): self;
    public static function done(string $stopReason): self;
    public static function error(string $message): self;
    // ... etc
}
```

## Provider Generator Pattern

Providers implement `streamMessage()` which returns a `Generator<StreamEvent>`.

**File:** `app/Services/Providers/AnthropicProvider.php`

```php
public function streamMessage(
    Conversation $conversation,
    array $options = []
): Generator {
    // Build request body from conversation messages
    $body = $this->buildRequestBody($conversation, $options);

    // Stream from API and yield normalized events
    yield from $this->streamRequest($body);
}

private function streamRequest(array $body): Generator
{
    $response = $client->post($url, [
        'headers' => [...],
        'json' => $body,
        'stream' => true,
    ]);

    $stream = $response->getBody();

    while (!$stream->eof()) {
        $chunk = $stream->read(64);
        // Parse SSE and yield StreamEvents
        yield from $this->parseSSEEvent($event, $currentBlocks);
    }
}

private function parseSSEEvent(string $event, array &$currentBlocks): Generator
{
    // Parse the SSE event
    switch ($eventType) {
        case 'content_block_start':
            yield StreamEvent::textStart($index);
            break;
        case 'content_block_delta':
            yield StreamEvent::textDelta($index, $delta['text']);
            break;
        case 'message_delta':
            yield StreamEvent::done($payload['delta']['stop_reason']);
            break;
        // ... etc
    }
}
```

## ProcessConversationStream Job

The background job consumes the generator and publishes events to Redis.

**File:** `app/Jobs/ProcessConversationStream.php`

```php
private function streamWithToolLoop(
    Conversation $conversation,
    AIProviderInterface $provider,
    StreamManager $streamManager,
    // ...
): void {
    // Stream from provider - this is a generator
    foreach ($provider->streamMessage($conversation, $providerOptions) as $event) {
        // Publish each event to Redis for frontend
        $streamManager->appendEvent($this->conversationUuid, $event);

        // Track state based on event type
        switch ($event->type) {
            case StreamEvent::TEXT_DELTA:
                $contentBlocks[$event->blockIndex]['text'] .= $event->content;
                break;

            case StreamEvent::TOOL_USE_START:
                $contentBlocks[$event->blockIndex] = [
                    'type' => 'tool_use',
                    'id' => $event->metadata['tool_id'],
                    'name' => $event->metadata['tool_name'],
                ];
                break;

            case StreamEvent::DONE:
                $stopReason = $event->metadata['stop_reason'];
                break;

            // ... handle all event types
        }
    }

    // Save assistant message after stream completes
    $this->saveAssistantMessage($conversation, $contentBlocks, ...);

    // If stop_reason is 'tool_use', execute tools and recurse
    if ($stopReason === 'tool_use' && !empty($pendingToolUses)) {
        $toolResults = $this->executeTools($pendingToolUses, ...);
        $this->saveToolResultMessage($conversation, $toolResults);
        $this->streamWithToolLoop(...); // Continue conversation
    }
}
```

## SSE Endpoint

**File:** `app/Http/Controllers/Api/ConversationController.php`

```php
public function streamEvents(Request $request, Conversation $conversation): StreamedResponse
{
    $fromIndex = max(0, (int) $request->query('from_index', 0));

    return response()->stream(function () use ($conversation, $fromIndex) {
        $sse = new SseWriter();
        $currentIndex = $fromIndex;

        // Send buffered events first
        $events = $this->streamManager->getEvents($conversation->uuid, $fromIndex);
        foreach ($events as $event) {
            $sse->writeRaw(json_encode(array_merge($event, ['index' => $currentIndex])));
            $currentIndex++;
        }

        // Poll for new events until stream completes
        while ($this->streamManager->getStatus($conversation->uuid) === 'streaming') {
            $newEvents = $this->streamManager->getEvents($conversation->uuid, $currentIndex);
            foreach ($newEvents as $event) {
                $sse->writeRaw(json_encode(array_merge($event, ['index' => $currentIndex])));
                $currentIndex++;
            }
            usleep(100000); // 100ms
            flush();
        }

        // Send final status
        $sse->writeRaw(json_encode([
            'type' => 'stream_status',
            'status' => $this->streamManager->getStatus($conversation->uuid),
        ]));
    }, 200, SseWriter::headers());
}
```

## Stream Manager

**File:** `app/Services/StreamManager.php`

Redis-backed event storage:

```php
class StreamManager
{
    public function startStream(string $uuid, array $metadata): void;
    public function appendEvent(string $uuid, StreamEvent $event): void;
    public function getEvents(string $uuid, int $fromIndex = 0): array;
    public function getStatus(string $uuid): ?string;
    public function completeStream(string $uuid): void;
    public function failStream(string $uuid, string $error): void;
    public function cleanup(string $uuid): void;
}
```

## Frontend (JavaScript)

```javascript
async startStream(prompt) {
    // Start the stream
    const response = await fetch(`/api/conversations/${uuid}/stream`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ prompt })
    });

    // Connect to SSE endpoint
    this.connectToStreamEvents();
}

connectToStreamEvents(fromIndex = 0) {
    const url = `/api/conversations/${uuid}/stream-events?from_index=${fromIndex}`;
    const eventSource = new EventSource(url);

    eventSource.onmessage = (event) => {
        const data = JSON.parse(event.data);
        this.handleStreamEvent(data);
    };
}
```

## Event Types

### `text_start` / `text_delta` / `text_stop`

Text content blocks:

```json
{"type": "text_start", "block_index": 0}
{"type": "text_delta", "block_index": 0, "content": "Hello..."}
{"type": "text_stop", "block_index": 0}
```

### `thinking_start` / `thinking_delta` / `thinking_stop`

Extended thinking content (when enabled):

```json
{"type": "thinking_start", "block_index": 0}
{"type": "thinking_delta", "block_index": 0, "content": "Let me think..."}
{"type": "thinking_stop", "block_index": 0}
```

### `tool_use_start` / `tool_use_delta` / `tool_use_stop`

Tool invocation:

```json
{"type": "tool_use_start", "block_index": 1, "metadata": {"tool_id": "tool_123", "tool_name": "Read"}}
{"type": "tool_use_delta", "block_index": 1, "content": "{\"file_path\": \"/..."}
{"type": "tool_use_stop", "block_index": 1}
```

### `tool_result`

Tool execution result (sent after job executes tool):

```json
{"type": "tool_result", "content": "file contents...", "metadata": {"tool_id": "tool_123", "is_error": false}}
```

### `usage`

Token usage data:

```json
{"type": "usage", "metadata": {"input_tokens": 1234, "output_tokens": 567}}
```

### `done`

Stream completion:

```json
{"type": "done", "metadata": {"stop_reason": "end_turn"}}
```

Stop reasons: `end_turn`, `tool_use`, `max_tokens`

### `stream_status`

Final stream status (sent by SSE endpoint):

```json
{"type": "stream_status", "status": "completed"}
```

## Reconnection Handling

The SSE endpoint supports reconnection via `from_index`:

1. Frontend tracks last received event index
2. On disconnect, reconnect with `?from_index=N`
3. Backend sends all events from index N onwards
4. No data loss during temporary disconnections

## Error Handling

```php
// In ProcessConversationStream job
try {
    $this->streamWithToolLoop(...);
    $streamManager->completeStream($uuid);
} catch (\Throwable $e) {
    $streamManager->failStream($uuid, $e->getMessage());
}
```

Frontend receives error via `stream_status`:

```javascript
handleStreamEvent(data) {
    if (data.type === 'stream_status' && data.status === 'failed') {
        this.showError('Stream failed');
    }
}
```

## Debugging

View stream events in Redis:

```bash
redis-cli LRANGE stream:{uuid}:events 0 -1
redis-cli GET stream:{uuid}:status
```
