# Streaming Implementation

Real-time streaming of Claude responses using Server-Sent Events (SSE).

## Architecture

```
Frontend                    Backend                     Claude CLI
────────                    ───────                     ──────────
fetch() ──────────────────► ClaudeController::stream()
                            │
                            ▼
                            ClaudeCodeService::streamQuery()
                            │
                            ▼
                            proc_open('claude --output-format stream-json')
                            │
                            ▼
                            Read stdout line by line ◄── Claude CLI output
                            │
                            ▼
SSE: data: {...} ◄───────── echo "data: " . $line
reader.read() ◄─────────────│
│
▼
Parse JSON, update DOM
```

## Backend (PHP)

### Controller Entry Point

**File:** `app/Http/Controllers/Api/ClaudeController.php:stream()`

```php
public function stream(Request $request, ClaudeSession $session)
{
    $validated = $request->validate([
        'prompt' => 'required|string',
        'thinking_level' => 'nullable|string|in:none,low,high',
    ]);

    return response()->stream(function () use ($validated, $session) {
        $this->claudeService->streamQuery(
            prompt: $validated['prompt'],
            sessionId: $session->claude_session_id,
            onChunk: function ($chunk) {
                echo "data: " . json_encode($chunk) . "\n\n";
                ob_flush();
                flush();
            },
            workingDirectory: $session->project_path,
            thinkingLevel: $validated['thinking_level'] ?? 'none',
        );
    }, 200, [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
        'X-Accel-Buffering' => 'no',
    ]);
}
```

### Service Layer

**File:** `app/Services/ClaudeCodeService.php:streamQuery()`

Key aspects:
1. Builds command with `--output-format stream-json`
2. Uses `proc_open()` with pipes for stdin/stdout
3. Writes prompt to stdin, closes it
4. Reads stdout line by line
5. Calls `$onChunk` callback for each line

```php
$command = [
    $this->binaryPath,
    '--print',
    '--output-format', 'stream-json',
    '--model', $this->model,
];

if ($sessionId) {
    $command[] = '--session';
    $command[] = $sessionId;
    $command[] = '--resume';
}

$process = proc_open($command, $descriptorspec, $pipes);

// Write prompt to stdin
fwrite($pipes[0], $prompt);
fclose($pipes[0]);

// Read stdout line by line
while (!feof($pipes[1])) {
    $line = fgets($pipes[1]);
    if ($line !== false && trim($line) !== '') {
        $onChunk(json_decode($line, true));
    }
}
```

## Frontend (JavaScript)

### Initiating Stream

**File:** `resources/views/chat.blade.php:sendMessage()`

```javascript
const response = await fetch(`${baseUrl}/api/claude/sessions/${sessionId}/stream`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
        prompt: originalPrompt,
        thinking_level: currentThinkingLevel
    })
});

const reader = response.body.getReader();
const decoder = new TextDecoder();
let buffer = '';

while (true) {
    const {done, value} = await reader.read();
    if (done) break;

    buffer += decoder.decode(value, {stream: true});
    const lines = buffer.split('\n');
    buffer = lines.pop(); // Keep incomplete line

    for (const line of lines) {
        if (line.startsWith('data: ')) {
            const data = JSON.parse(line.substring(6));
            // Handle event...
        }
    }
}
```

### Event Types

Claude CLI emits these event types in stream-json mode:

#### `content_block_start`

Signals start of a new content block.

```json
{
    "type": "stream_event",
    "event": {
        "type": "content_block_start",
        "index": 0,
        "content_block": {
            "type": "thinking" | "text" | "tool_use",
            "id": "...",
            "name": "ToolName"  // Only for tool_use
        }
    }
}
```

**Handling:**
- Set `currentBlockType` to track what we're receiving
- For `thinking`: Reset `thinkingContent`, prepare new thinking block
- For `text`: Reset `textContent`, prepare new assistant message
- For `tool_use`: Initialize tool tracking with name and ID

#### `content_block_delta`

Incremental content update.

**Thinking delta:**
```json
{
    "type": "stream_event",
    "event": {
        "type": "content_block_delta",
        "delta": {
            "type": "thinking_delta",
            "thinking": "partial thinking text..."
        }
    }
}
```

**Text delta:**
```json
{
    "type": "stream_event",
    "event": {
        "type": "content_block_delta",
        "delta": {
            "type": "text_delta",
            "text": "partial response text..."
        }
    }
}
```

**Tool input delta:**
```json
{
    "type": "stream_event",
    "event": {
        "type": "content_block_delta",
        "delta": {
            "type": "input_json_delta",
            "partial_json": "{\"command\": \"ls"
        }
    }
}
```

**Handling:**
- Append delta to accumulated content
- Create message block if doesn't exist
- Update message block with new content

#### `message_delta`

Contains usage data.

```json
{
    "type": "stream_event",
    "event": {
        "type": "message_delta",
        "usage": {
            "input_tokens": 1234,
            "output_tokens": 567,
            "cache_creation_input_tokens": 100,
            "cache_read_input_tokens": 50
        }
    }
}
```

**Handling:**
- Store usage data for cost calculation
- Calculate cost after stream completes

#### Tool Results

Tool results come as user messages:

```json
{
    "type": "user",
    "message": {
        "content": [
            {
                "type": "tool_result",
                "tool_use_id": "...",
                "content": "tool output here"
            }
        ]
    }
}
```

**Handling:**
- Match `tool_use_id` to existing tool block
- Update tool block with result

### Block Tracking

The frontend tracks multiple concurrent blocks:

```javascript
let thinkingMsgId = null;      // Current thinking block ID
let assistantMsgId = null;     // Current text block ID
let toolBlocks = {};           // Map: block index → {msgId, name, content, toolId}
let toolBlockMap = {};         // Map: tool_use_id → block msgId
let currentBlockIndex = -1;    // Current block being streamed
let currentBlockType = null;   // 'thinking', 'text', or 'tool_use'
```

### Auto-Collapse Behavior

When a new collapsible block starts (thinking or tool_use), the previous one is collapsed:

```javascript
if ((currentBlockType === 'thinking' || currentBlockType === 'tool_use') && lastExpandedBlockId) {
    collapseBlock(lastExpandedBlockId);
}
```

This keeps the UI focused on the current activity.

## Nginx Configuration

SSE requires specific nginx settings:

**File:** `docker-proxy/shared/nginx.conf.template`

```nginx
location / {
    proxy_pass http://laravel;
    proxy_http_version 1.1;

    # Disable buffering for SSE
    proxy_buffering off;

    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

**Key:** `proxy_buffering off` ensures events stream immediately.

## Error Handling

### Backend Errors

```php
// In streamQuery(), errors are caught and sent as chunks
try {
    // ... streaming logic
} catch (ClaudeCodeException $e) {
    $onChunk([
        'type' => 'result',
        'is_error' => true,
        'result' => $e->getMessage()
    ]);
}
```

### Frontend Error Display

```javascript
if (data.type === 'result' && data.is_error) {
    if (!assistantMsgId) assistantMsgId = addMsg('assistant', '');
    updateMsg(assistantMsgId, 'Error: ' + data.result);
}
```

### Stream Interruption

If stream ends without content:

```javascript
if (!textContent && !thinkingContent) {
    if (!assistantMsgId) assistantMsgId = addMsg('assistant', '');
    updateMsg(assistantMsgId, 'No response received from Claude');
}
```

## Performance Considerations

1. **Buffer management:** Incomplete lines kept in buffer to handle split UTF-8
2. **DOM updates:** `updateMsg()` is called frequently during streaming
3. **Dual container:** Updates happen twice (desktop + mobile)
4. **Memory:** `audioChunks` for voice recording should be cleared after use

## Debugging

Add logging to see stream events:

```javascript
console.log('[FRONTEND-SSE] Received:', {
    type: data.type,
    event_type: data.event?.type,
    data_preview: JSON.stringify(data).substring(0, 100)
});
```

Backend logging in ClaudeCodeService:

```php
Log::debug('Claude stream chunk', ['chunk' => $chunk]);
```
