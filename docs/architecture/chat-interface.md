# Chat Interface Architecture

The chat interface (`resources/views/chat.blade.php`) implements a sophisticated real-time messaging system with responsive design, voice recording, and streaming support.

## Dual-Container Pattern

### Overview

The interface maintains **two complete DOM trees simultaneously** - one for desktop, one for mobile:

```
┌─────────────────────────────────────────────────────────────────────┐
│                         chat.blade.php                               │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                    Desktop Layout                            │   │
│  │  ┌──────────────┐  ┌────────────────────────────────────┐  │   │
│  │  │   Sidebar    │  │         #messages                   │  │   │
│  │  │   Sessions   │  │     Message Container               │  │   │
│  │  │   Cost       │  │     (flex, overflow-scroll)         │  │   │
│  │  └──────────────┘  └────────────────────────────────────┘  │   │
│  │  CSS: display: none @ <768px                                 │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                    Mobile Layout                             │   │
│  │  ┌────────────────────────────────────────────────────┐    │   │
│  │  │              Sticky Header                          │    │   │
│  │  ├────────────────────────────────────────────────────┤    │   │
│  │  │           #messages-mobile                          │    │   │
│  │  │         (full-page scroll)                          │    │   │
│  │  ├────────────────────────────────────────────────────┤    │   │
│  │  │           Fixed Bottom Panel                        │    │   │
│  │  └────────────────────────────────────────────────────┘    │   │
│  │  CSS: display: none @ >=768px                                │   │
│  └─────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
```

### Why Both Exist in DOM?

- **No JavaScript layout switching** - CSS handles visibility
- **Consistent state** - Alpine.js state shared across both
- **Instant transitions** - No re-rendering on resize

### Critical Implementation Rule

**Every DOM update must target BOTH containers:**

```javascript
// CORRECT: Update both containers
const containers = ['messages', 'messages-mobile'];
containers.forEach(containerId => {
    const container = document.getElementById(containerId);
    if (container) {
        container.innerHTML += html;
    }
});

// WRONG: Only updates desktop
document.getElementById('messages').innerHTML += html;
```

### Scroll Behavior

| Platform | Container | Scroll Method |
|----------|-----------|---------------|
| Desktop | `#messages` | `container.scrollTop = container.scrollHeight` |
| Mobile | `#messages-mobile` | `window.scrollTo(0, document.body.scrollHeight)` |

---

## Alpine.js State Management

### State Object (`appState()`)

```javascript
{
  // Voice Recording
  isRecording: false,           // Currently recording
  isProcessing: false,          // Transcription in progress
  mediaRecorder: null,          // MediaRecorder instance
  audioChunks: [],              // Accumulated audio data
  openAiKeyConfigured: false,   // OpenAI API key status
  autoSendAfterTranscription: false,

  // Modals
  showOpenAiModal: false,       // OpenAI key setup
  showShortcutsModal: false,    // Keyboard shortcuts
  showMobileDrawer: false,      // Mobile session drawer

  // Quick Settings
  showQuickSettings: false,
  quickSettings: {
    model: 'claude-sonnet-4-5-20250929',
    permissionMode: 'acceptEdits',
    maxTurns: 50
  }
}
```

### Initialization

```html
<div x-data="appState()" x-init="initVoice()">
```

- `x-data` creates reactive state
- `x-init` checks OpenAI key configuration on load

---

## Streaming Architecture

### Flow

```
1. User sends prompt
   │
   ▼
2. POST /api/claude/sessions/{id}/stream
   │
   ▼
3. Backend spawns Claude CLI process
   │ Writes to: /tmp/claude-{uuid}-stdout.jsonl
   ▼
4. PHP tails file, sends SSE events
   │ Format: data: {"type":"...", ...}\n\n
   ▼
5. Frontend processes events real-time
   │
   ▼
6. DOM updated incrementally
```

### SSE Event Types

| Event | Data | Action |
|-------|------|--------|
| `content_block_start` | Block type (thinking/text/tool_use) | Create new block |
| `content_block_delta` | Delta content | Append to block |
| `thinking_delta` | Thinking text | Update thinking block |
| `text_delta` | Response text | Update assistant message |
| `input_json_delta` | Tool input JSON | Update tool block |
| `message_delta` | Usage data | Calculate cost |
| `message_stop` | - | End of response |

### Event Processing Loop

```javascript
const reader = response.body.getReader();
const decoder = new TextDecoder();
let buffer = '';

while (true) {
    const {done, value} = await reader.read();
    if (done) break;

    buffer += decoder.decode(value, {stream: true});
    const lines = buffer.split('\n');
    buffer = lines.pop();  // Keep incomplete line

    for (const line of lines) {
        if (line.startsWith('data: ')) {
            const data = JSON.parse(line.substring(6));
            // Process by type...
        }
    }
}
```

### Reconnection Support

If connection drops during streaming:
1. Client reconnects to same endpoint
2. Backend replays existing lines from stdout file
3. New lines continue streaming
4. No message loss

---

## Message Types & Rendering

### User Messages
- **Background**: Blue (`bg-blue-600`)
- **Content**: Plain text (HTML escaped)
- **Alignment**: Right

### Assistant Messages
- **Background**: Gray (`bg-gray-800`)
- **Content**: Markdown rendered (marked.js + highlight.js)
- **Metadata**: Timestamp + cost badge

### Thinking Blocks (Collapsible)
- **Style**: Purple border, light bulb icon
- **Content**: Monospace plain text
- **Default State**: Collapsed (except during streaming)
- **Auto-collapse**: When next content block starts

### Tool Blocks (Collapsible)
- **Style**: Blue border, settings icon
- **Title**: Smart formatting based on tool type:
  - Bash: Command description
  - Read/Write/Edit: File path
  - Glob/Grep: Search pattern
- **Result**: Green section with output

### System Messages
- **Style**: Centered info badge
- **Used For**: Session compaction markers

### Command Output/Error
- **Output**: Green collapsible block, markdown
- **Error**: Red collapsible block, plain text

---

## Voice Recording

### Recording Flow

```
1. User clicks record button
   │
   ▼
2. Request microphone (getUserMedia)
   │
   ▼
3. Create MediaRecorder
   │ Platform-specific constraints
   ▼
4. Accumulate audio chunks
   │ ondataavailable event
   ▼
5. User clicks stop (or send)
   │
   ▼
6. Create blob, POST to /api/claude/transcribe
   │
   ▼
7. OpenAI Whisper transcription
   │
   ▼
8. Insert text (or auto-send if flag set)
```

### Platform-Specific Constraints

**Mobile**:
```javascript
{
    audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
    }
}
```

**Desktop**:
```javascript
{
    audio: {
        autoGainControl: false,
        echoCancellation: false,
        noiseSuppression: false,
        sampleRate: 16000,
        channelCount: 1
    }
}
```

### MIME Type Selection

Tries formats in order:
1. `audio/webm;codecs=opus`
2. `audio/webm`
3. `audio/mp4`
4. `audio/ogg;codecs=opus`
5. `audio/wav`
6. Browser default

**Note**: Microphone API requires secure context (HTTPS or `localhost`). IP addresses won't work.

---

## Cost Tracking

### Calculation Formula

```javascript
function calculateCost(usage) {
    const inputTokens = usage.input_tokens || 0;
    const cacheCreationTokens = usage.cache_creation_input_tokens || 0;
    const cacheReadTokens = usage.cache_read_input_tokens || 0;
    const outputTokens = usage.output_tokens || 0;

    const inputCost = inputTokens * PRICING.input;
    const cacheWriteCost = cacheCreationTokens * PRICING.input * PRICING.cacheWriteMultiplier;
    const cacheReadCost = cacheReadTokens * PRICING.input * PRICING.cacheReadMultiplier;
    const outputCost = outputTokens * PRICING.output;

    return inputCost + cacheWriteCost + cacheReadCost + outputCost;
}
```

### Default Pricing
- Input: $3 per million tokens
- Output: $15 per million tokens
- Cache Write: 1.25× input price
- Cache Read: 0.1× input price

### Display Locations
- Per-message: Settings icon with cost breakdown modal
- Session total: Sidebar (desktop), drawer (mobile)

---

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Enter` | Send message |
| `Ctrl+T` | Cycle thinking mode |
| `Ctrl+Space` | Toggle voice recording |
| `Ctrl+?` | Show shortcuts modal |

### Thinking Modes

| Level | Name | Tokens | Color |
|-------|------|--------|-------|
| 0 | Off | 0 | Gray |
| 1 | Think | 4,000 | Blue |
| 2 | Think Hard | 10,000 | Purple |
| 3 | Think Harder | 20,000 | Pink |
| 4 | Ultrathink | 32,000 | Yellow |

---

## Session Management

### URL Persistence

Sessions use URL-based routing:
- `/` - New session
- `/session/{uuid}` - Specific session

### Session Loading

1. Extract session ID from URL
2. Fetch messages from `/api/claude/claude-sessions/{id}`
3. Find or create database session
4. Clear current messages
5. Render all historical messages
6. Update URL with `pushState()`

### Session List

Fetched from `/api/claude/claude-sessions`:
- Shows preview (first 50 chars)
- Last modified timestamp
- Click to load session

---

## Slash Command Autocomplete

### Trigger

Autocomplete shows when:
- Input starts with `/`
- Commands fetched from `/api/claude/commands/list`

### Navigation

| Key | Action |
|-----|--------|
| Arrow Up/Down | Navigate list |
| Enter | Select command |
| Escape | Hide autocomplete |

### Selection

Fills input with `/commandName argumentHint` and positions cursor.

---

## Performance Considerations

### Streaming Efficiency
- Text deltas processed in real-time
- Blocks created only after 3+ chars (prevents jitter)
- Both containers updated in single batch

### DOM Updates
- `innerHTML +=` for message additions
- Single forEach loop for dual-container updates
- Minimal re-renders

### Cost Calculation
- Server-side in .jsonl files
- Frontend displays cached values
- No per-message recalculation on load

---

## Source References

- **Main View**: `resources/views/chat.blade.php` (~2500 lines)
- **Alpine State**: Lines 2059-2381 (`appState()` function)
- **Streaming**: Lines 1317-1561 (SSE processing)
- **Message Rendering**: Lines 1636-1825 (`addMsg()` function)
- **Voice Recording**: Lines 2108-2283 (`startVoiceRecording()`, `processRecording()`)
