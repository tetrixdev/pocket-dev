# Chat Module

The chat module provides a web interface for multi-provider AI conversations.

## Contents

- `streaming.md` - SSE streaming implementation details

## Overview

**Primary file:** `resources/views/chat.blade.php` + `partials/chat/`

**Controller:** `app/Http/Controllers/Api/ConversationController.php`

**Routes:**
- `/` - Chat interface (default)
- `/chat/{uuid}` - Conversation-specific view
- `/api/conversations` - Conversation CRUD
- `/api/conversations/{uuid}/stream` - Start streaming
- `/api/conversations/{uuid}/stream-events` - SSE endpoint

## Architecture

The chat system uses a multi-provider architecture:

```
Frontend (Alpine.js)          Backend                      Providers
────────────────────          ───────                      ─────────
POST /api/conversations/      ConversationController
{uuid}/stream                 │
                              ▼
                              ProcessConversationStream
                              (Background Job)
                              │
                              ▼
                              ProviderFactory::make()
                              │
                              ├─► AnthropicProvider ──────► Anthropic API
                              └─► OpenAIProvider ─────────► OpenAI API
                              │
                              ▼
                              StreamManager (Redis)
                              │
GET /stream-events ◄──────────┘ (SSE)
```

## Key Concepts

### Responsive Layout

The chat interface uses a **single `#messages` container** with Tailwind CSS responsive classes:

- Mobile (< md): Document scrolling, full-page layout
- Desktop (>= md): Container scrolling, sidebar layout

The same DOM element adapts to different screen sizes via responsive breakpoint prefixes (`md:`, `lg:`, etc.).

### Conversation Model

Conversations are stored in the database with full message history:

| Field | Description |
|-------|-------------|
| `uuid` | URL-safe identifier |
| `provider_type` | 'anthropic' or 'openai' |
| `model` | Model identifier |
| `working_directory` | Context directory |
| `status` | active/archived |
| `anthropic_thinking_budget` | Thinking tokens (Anthropic) |
| `openai_reasoning_effort` | Reasoning level (OpenAI) |
| `response_level` | Response verbosity (0-3) |

**Model:** `app/Models/Conversation.php`

### Provider Abstraction

The `ProviderFactory` creates provider instances based on type:

```php
$provider = $this->providerFactory->make('anthropic');
$provider->isAvailable();  // Check API key
$provider->getModels();    // List available models
$provider->stream(...);    // Stream a response
```

### Message Types

| Type | Appearance | Content | Collapsible |
|------|------------|---------|-------------|
| `user` | Blue bubble, right | Plain text | No |
| `assistant` | Gray bubble, left | Markdown | No |
| `thinking` | Purple block | Plain text (mono) | Yes |
| `tool` | Blue block | Formatted tool call | Yes |
| `error` | Red bubble | Error text | No |

### Cost Tracking

Cost is calculated **server-side only** using the `ai_models` table pricing:

- During streaming: `ProcessConversationStream` calculates cost when receiving usage events
- Cost is included in the `usage` stream event and stored in the `messages.cost` column
- Frontend displays the server-provided cost without any client-side calculation
- Formula: `(input_tokens * input_price + output_tokens * output_price + cache_creation * cache_write_price + cache_read * cache_read_price) / 1,000,000`

### Voice Input

Voice transcription uses OpenAI Whisper:

1. User clicks mic or presses Ctrl+Space
2. `MediaRecorder` captures audio as `audio/webm`
3. Audio sent to `/api/claude/transcribe`
4. Transcribed text inserted into prompt
5. Optional auto-send after transcription

**Requirements:**
- OpenAI API key configured
- Secure context (HTTPS or localhost)

## State Management (Alpine.js)

The chat uses Alpine.js for reactive state management. Key state includes:

- `messages[]` - Conversation messages
- `currentConversationUuid` - Active conversation
- `isStreaming` - Stream status
- `providers` - Available AI providers
- `chatDefaults` - Default settings

## UI Components

### Quick Settings Modal

Configures per-conversation settings:
- Model selection (provider-specific)
- Thinking/reasoning level
- Response verbosity level

### Conversation Sidebar

- Lists recent conversations
- Shows title or first prompt preview
- Archive/delete actions
- Click to switch conversations

### Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl+T` | Cycle thinking modes |
| `Ctrl+Space` | Toggle voice recording |
| `Ctrl+?` | Show shortcuts modal |

## File Structure

```
resources/views/
├── chat.blade.php                 # Main chat view
└── partials/chat/
    ├── sidebar.blade.php          # Conversation list
    ├── mobile-layout.blade.php    # Mobile view
    ├── input-desktop.blade.php    # Desktop input
    ├── input-mobile.blade.php     # Mobile input
    ├── modals.blade.php           # Modal container
    ├── modals/
    │   ├── quick-settings.blade.php
    │   ├── pricing-settings.blade.php
    │   ├── openai-key.blade.php
    │   ├── shortcuts.blade.php
    │   ├── cost-breakdown.blade.php
    │   └── error.blade.php
    └── messages/
        ├── user-message.blade.php
        ├── assistant-message.blade.php
        ├── thinking-block.blade.php
        ├── tool-block.blade.php
        └── empty-response.blade.php
```

## API Endpoints

### Conversation Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/conversations` | List conversations |
| POST | `/api/conversations` | Create conversation |
| GET | `/api/conversations/{uuid}` | Get conversation |
| DELETE | `/api/conversations/{uuid}` | Delete conversation |
| POST | `/api/conversations/{uuid}/archive` | Archive |
| POST | `/api/conversations/{uuid}/unarchive` | Unarchive |

### Streaming

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/conversations/{uuid}/stream` | Start streaming |
| GET | `/api/conversations/{uuid}/stream-status` | Check status |
| GET | `/api/conversations/{uuid}/stream-events` | SSE endpoint |

### Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/providers` | List providers |
| GET | `/api/settings/chat-defaults` | Get defaults |
| POST | `/api/settings/chat-defaults` | Update defaults |
