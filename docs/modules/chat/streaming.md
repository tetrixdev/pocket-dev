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
                            Provider::stream() ────────────► API (streaming)
                            │
                            ▼
                            StreamManager::pushEvent() ◄─── Parse chunks
                            │
                            ▼
                            Redis event buffer
                            │
GET /stream-events ◄────────┘ (SSE polling)
```

## Backend (PHP)

### Starting a Stream

**File:** `app/Http/Controllers/Api/ConversationController.php:stream()`

```php
public function stream(Request $request, Conversation $conversation): JsonResponse
{
    // Initialize stream state in Redis
    $this->streamManager->startStream($conversation->uuid, [
        'model' => $conversation->model,
        'provider' => $conversation->provider_type,
    ]);

    // Clear old events
    Redis::del("stream:{$conversation->uuid}:events");

    // Dispatch background job
    ProcessConversationStream::dispatch(
        $conversation->uuid,
        $validated['prompt'],
        []
    );

    return response()->json([
        'success' => true,
        'conversation_uuid' => $conversation->uuid,
    ]);
}
```

### Background Job

**File:** `app/Jobs/ProcessConversationStream.php`

The job:
1. Loads the conversation
2. Creates the appropriate provider
3. Calls `provider->stream()` with a callback
4. Callback pushes events to StreamManager
5. Handles completion/errors

### SSE Endpoint

**File:** `app/Http/Controllers/Api/ConversationController.php:streamEvents()`

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

### Stream Manager

**File:** `app/Services/StreamManager.php`

Redis-backed event storage:

```php
class StreamManager
{
    public function startStream(string $uuid, array $metadata): void;
    public function pushEvent(string $uuid, array $event): void;
    public function getEvents(string $uuid, int $fromIndex = 0): array;
    public function getStatus(string $uuid): ?string;
    public function completeStream(string $uuid): void;
    public function failStream(string $uuid, string $error): void;
    public function cleanup(string $uuid): void;
}
```

## Frontend (JavaScript)

### Initiating Stream

**File:** `resources/views/chat.blade.php`

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

### Event Types

#### `text_delta`

Incremental text content:

```json
{
    "type": "text_delta",
    "text": "partial response..."
}
```

#### `thinking_start` / `thinking_delta` / `thinking_stop`

Extended thinking content:

```json
{
    "type": "thinking_delta",
    "thinking": "reasoning text..."
}
```

#### `tool_use_start` / `tool_input_delta`

Tool invocation:

```json
{
    "type": "tool_use_start",
    "tool_name": "Read",
    "tool_id": "tool_123"
}
```

#### `tool_result`

Tool execution result:

```json
{
    "type": "tool_result",
    "tool_id": "tool_123",
    "content": "file contents..."
}
```

#### `usage`

Token usage data:

```json
{
    "type": "usage",
    "input_tokens": 1234,
    "output_tokens": 567,
    "cache_creation_input_tokens": 100,
    "cache_read_input_tokens": 50
}
```

#### `stream_status`

Final stream status:

```json
{
    "type": "stream_status",
    "status": "completed"  // or "failed"
}
```

## Provider Implementation

### Anthropic Provider

**File:** `app/Services/Providers/AnthropicProvider.php`

Uses Anthropic's streaming API with SSE:

```php
public function stream(
    Conversation $conversation,
    string $prompt,
    callable $onEvent
): void {
    $response = Http::withHeaders([
        'x-api-key' => $this->apiKey,
        'anthropic-version' => '2023-06-01',
    ])->withOptions([
        'stream' => true,
    ])->post($this->baseUrl . '/messages', [
        'model' => $conversation->model,
        'messages' => $this->buildMessages($conversation, $prompt),
        'stream' => true,
    ]);

    // Parse SSE and call $onEvent for each chunk
}
```

### OpenAI Provider

**File:** `app/Services/Providers/OpenAIProvider.php`

Uses OpenAI's streaming API:

```php
public function stream(
    Conversation $conversation,
    string $prompt,
    callable $onEvent
): void {
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $this->apiKey,
    ])->withOptions([
        'stream' => true,
    ])->post($this->baseUrl . '/chat/completions', [
        'model' => $conversation->model,
        'messages' => $this->buildMessages($conversation, $prompt),
        'stream' => true,
    ]);

    // Parse SSE and call $onEvent for each chunk
}
```

## Reconnection Handling

The SSE endpoint supports reconnection via `from_index`:

1. Frontend tracks last received event index
2. On disconnect, reconnect with `?from_index=N`
3. Backend sends all events from index N onwards
4. No data loss during temporary disconnections

## Nginx Configuration

SSE requires specific nginx settings:

```nginx
location / {
    proxy_pass http://laravel;
    proxy_http_version 1.1;

    # Disable buffering for SSE
    proxy_buffering off;

    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}
```

## Error Handling

### Backend Errors

Errors are pushed to the event stream:

```php
try {
    $provider->stream($conversation, $prompt, $onEvent);
    $this->streamManager->completeStream($uuid);
} catch (\Exception $e) {
    $this->streamManager->failStream($uuid, $e->getMessage());
}
```

### Frontend Error Display

```javascript
handleStreamEvent(data) {
    if (data.type === 'stream_status' && data.status === 'failed') {
        this.showError(data.error || 'Stream failed');
    }
}
```

## Debugging

View stream events in Redis:

```bash
redis-cli LRANGE stream:{uuid}:events 0 -1
redis-cli GET stream:{uuid}:status
```

Backend logging:

```php
Log::debug('Stream event', ['uuid' => $uuid, 'event' => $event]);
```

Frontend logging:

```javascript
console.log('[SSE]', data.type, data);
```
