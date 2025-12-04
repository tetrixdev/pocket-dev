# Chat Module

The chat module provides a web interface for interacting with Claude Code CLI.

## Contents

- `streaming.md` - SSE streaming implementation details

## Overview

**Primary file:** `resources/views/chat.blade.php` (~1500 lines)

**Controller:** `app/Http/Controllers/Api/ClaudeController.php`

**Routes:**
- `/` - Chat interface (web)
- `/session/{sessionId}` - Session-specific chat (web)
- `/api/claude/sessions/{id}/stream` - Streaming endpoint (API)

## Key Concepts

### Dual-Container Pattern

The chat interface maintains **two separate DOM containers** for responsive design:

- `#messages` - Desktop view (sidebar layout, container scroll)
- `#messages-mobile` - Mobile view (full-page scroll)

**Critical:** Every message operation must update BOTH containers:

```javascript
// Pattern used throughout chat.blade.php
const container = document.getElementById('messages');
const mobileContainer = document.getElementById('messages-mobile');

if (container) {
    container.innerHTML += html;
    container.scrollTop = container.scrollHeight;  // Desktop scroll
}
if (mobileContainer) {
    mobileContainer.innerHTML += html;
    window.scrollTo(0, document.body.scrollHeight);  // Mobile scroll
}
```

**Functions that must update both:**
- `addMsg()` - Add new message
- `updateMsg()` - Update existing message
- `clearMessages()` - Clear all messages
- `updateSessionCost()` - Update cost display
- `loadSessionsList()` - Update session sidebar

**Why this pattern?**
- CSS media queries show/hide containers based on viewport
- Both exist in DOM simultaneously
- Avoids JavaScript viewport detection complexity

**Limitation:** Code duplication, easy to forget one container.

### Session Management

**Two IDs per session:**

| ID | Type | Source | Used For |
|----|------|--------|----------|
| `sessionId` | Integer | Database (auto-increment) | Laravel routes, database queries |
| `claudeSessionId` | UUID | Claude CLI | Continuing conversations |

**Flow:**
1. User creates session → Database generates ID + UUID
2. Claude CLI uses UUID for session persistence
3. Frontend tracks both for API calls
4. Loading from .jsonl uses UUID, may create database record

**Source:** `app/Models/ClaudeSession.php`

### Message Types

| Type | Appearance | Content | Collapsible |
|------|------------|---------|-------------|
| `user` | Blue bubble, right | Plain text | No |
| `assistant` | Gray bubble, left | Markdown | No |
| `thinking` | Purple block | Plain text (mono) | Yes |
| `tool` | Blue block | Formatted tool call | Yes |
| `error` | Red bubble | Error text | No |

**Rendering:**
- User messages: `escapeHtml(content)` (plain text)
- Assistant messages: `marked.parse(content)` (markdown)
- Thinking/Tool: Custom formatting with collapsible UI

### Cost Tracking

**Two calculation contexts:**

| Context | Calculator | Source | When |
|---------|------------|--------|------|
| Streaming | Client JavaScript | `PRICING` constants | Real-time, during response |
| Historical | Server (Claude CLI) | .jsonl metadata | Loading saved sessions |

**Client-side calculation:**
```javascript
const PRICING = {
    input: inputPricePerMillion / 1_000_000,
    output: outputPricePerMillion / 1_000_000,
    cacheWriteMultiplier: 1.25,
    cacheReadMultiplier: 0.1
};

function calculateCost(usage) {
    return (usage.input_tokens * PRICING.input) +
           (usage.cache_creation_input_tokens * PRICING.input * 1.25) +
           (usage.cache_read_input_tokens * PRICING.input * 0.1) +
           (usage.output_tokens * PRICING.output);
}
```

**Server-side:** Claude CLI calculates and stores cost in .jsonl file metadata.

**Risk:** Client and server calculations could drift if pricing changes.

## State Management (Alpine.js)

```javascript
function appState() {
    return {
        // Voice recording
        isRecording: false,
        isProcessing: false,
        mediaRecorder: null,
        audioChunks: [],

        // Modals
        showOpenAiModal: false,
        showShortcutsModal: false,
        showMobileDrawer: false,

        // Configuration
        openAiKeyConfigured: false,
        autoSendAfterTranscription: true
    }
}
```

**Usage:** `x-data="appState()"` on body element.

## Voice Recording

**Flow:**
1. User clicks mic or presses Ctrl+Space
2. `MediaRecorder` starts with `audio/webm` format
3. Chunks collected in `audioChunks` array
4. On stop: Blob created, FormData sent to `/api/claude/transcribe`
5. Transcribed text inserted into prompt
6. If `autoSendAfterTranscription`: Form submitted

**Requirements:**
- OpenAI API key configured (stored encrypted in database)
- Secure context (HTTPS or localhost) - mic won't work on plain IP

**Source:** `appState()` methods in `chat.blade.php`

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Shift+T` (in prompt) | Cycle thinking modes |
| `Ctrl+Space` | Toggle voice recording |
| `Ctrl+?` | Show shortcuts modal |

## UI Components

### Thinking Mode Toggle

Three levels:
- Off (gray)
- Low (yellow) - `thinking_budget: "low"`
- High (green) - `thinking_budget: "high"`

Cycles through on Shift+T.

### Session Sidebar

**Desktop:** Fixed left sidebar with session list
**Mobile:** Accessible via hamburger menu → drawer

Sessions show:
- First 50 chars of first prompt
- Date and time
- Click to load

### Cost Display

Per-message:
- Shows `$X.XXXX` after assistant messages
- Cog icon opens breakdown modal

Session total:
- Desktop: Bottom of sidebar
- Mobile: In drawer

## File Structure

```
resources/views/chat.blade.php
├── HTML Structure
│   ├── Desktop layout (sidebar + main)
│   ├── Mobile layout (full-page + drawer)
│   └── Modals (OpenAI key, shortcuts, cost breakdown)
├── CSS (inline styles)
│   ├── Markdown styling
│   ├── Responsive breakpoints
│   └── Animation keyframes
└── JavaScript
    ├── Global variables (sessionId, claudeSessionId, PRICING, etc.)
    ├── marked.js configuration
    ├── Helper functions (escapeHtml, formatTimestamp, calculateCost)
    ├── Session functions (loadSessionsList, loadSession, newSession)
    ├── Message functions (addMsg, updateMsg, clearMessages)
    ├── Streaming (sendMessage with SSE handling)
    ├── Tool formatting (formatToolCall)
    ├── Cost tracking (updateSessionCost, showCostBreakdown)
    ├── Alpine.js state (appState with voice recording)
    └── Keyboard shortcuts
```

## Known Issues / Complexity

1. **File size:** 1500+ lines in single file
2. **Dual container:** Easy to forget to update both
3. **Session ID confusion:** Two IDs tracked throughout
4. **Cost duplication:** Client and server both calculate
5. **No code splitting:** All JS loaded regardless of need
6. **Mixed concerns:** HTML, CSS, JS all in one file

## Refactoring Opportunities

1. Extract JavaScript to separate files
2. Use a single message container with CSS-only responsive
3. Consolidate session ID handling
4. Move cost calculation to server-only
5. Consider Livewire for simpler reactivity
