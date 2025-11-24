# API Reference

Complete reference for all PocketDev routes and API endpoints.

## Authentication

All requests go through the Nginx proxy which enforces **HTTP Basic Auth**:

```bash
curl -u username:password http://localhost/api/...
```

Laravel routes do NOT have additional auth middleware - proxy handles authentication.

---

## Web Routes

**File**: `routes/web.php`

### Authentication

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/claude/auth` | ClaudeAuthController@index | Auth status page |
| GET | `/claude/auth/status` | ClaudeAuthController@status | JSON status |
| POST | `/claude/auth/upload` | ClaudeAuthController@upload | Upload credentials file |
| POST | `/claude/auth/upload-json` | ClaudeAuthController@uploadJson | Upload credentials JSON |
| DELETE | `/claude/auth/logout` | ClaudeAuthController@logout | Clear credentials |

### Chat Interface

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/` | Main chat interface |
| GET | `/session/{sessionId}` | Session-specific chat |

### Terminal (Deprecated)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/terminal` | Web terminal interface |

### Configuration

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/config` | Redirect to last section |
| GET | `/config/claude` | CLAUDE.md editor |
| POST | `/config/claude` | Save CLAUDE.md |
| GET | `/config/settings` | settings.json editor |
| POST | `/config/settings` | Save settings.json |
| GET | `/config/nginx` | Nginx config editor |
| POST | `/config/nginx` | Save + reload nginx |

#### Agents CRUD

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/config/agents` | List agents |
| GET | `/config/agents/create` | Create form |
| POST | `/config/agents` | Store agent |
| GET | `/config/agents/{filename}/edit` | Edit form |
| PUT | `/config/agents/{filename}` | Update agent |
| DELETE | `/config/agents/{filename}` | Delete agent |

#### Commands CRUD

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/config/commands` | List commands |
| GET | `/config/commands/create` | Create form |
| POST | `/config/commands` | Store command |
| GET | `/config/commands/{filename}/edit` | Edit form |
| PUT | `/config/commands/{filename}` | Update command |
| DELETE | `/config/commands/{filename}` | Delete command |

#### Skills CRUD

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/config/skills` | List skills |
| GET | `/config/skills/create` | Create form |
| POST | `/config/skills` | Store skill |
| GET | `/config/skills/{skillName}/edit` | Edit form |
| DELETE | `/config/skills/{skillName}` | Delete skill |

---

## API Routes

**File**: `routes/api.php`

All endpoints prefixed with `/api/`.

### Status

```
GET /api/claude/status
```

Check Claude CLI availability.

**Response**:
```json
{
  "available": true,
  "version": "1.0.0"
}
```

---

### Sessions

#### Create Session

```
POST /api/claude/sessions
```

**Request**:
```json
{
  "title": "My Session",      // optional
  "project_path": "/var/www"  // required
}
```

**Response** (201):
```json
{
  "session": {
    "id": 1,
    "title": "My Session",
    "project_path": "/var/www",
    "claude_session_id": "550e8400-e29b-41d4-a716-446655440000",
    "turn_count": 0,
    "status": "active",
    "created_at": "2025-01-24T12:00:00Z",
    "updated_at": "2025-01-24T12:00:00Z"
  }
}
```

#### List Sessions

```
GET /api/claude/sessions
```

**Query Parameters**:
- `status` - Filter by status (active/completed/failed)
- `project_path` - Filter by project

**Response**: Paginated list of sessions (20 per page)

#### Get Session Status

```
GET /api/claude/sessions/{id}/status
```

**Response**:
```json
{
  "session_id": 1,
  "claude_session_id": "550e8400-...",
  "process_pid": null,
  "process_status": "idle",
  "last_message_index": 5,
  "turn_count": 3,
  "status": "active"
}
```

---

### Streaming

#### Stream Query (SSE)

```
POST /api/claude/sessions/{id}/stream
```

**Request**:
```json
{
  "prompt": "What is 5+3?",
  "thinking_level": 0      // 0-4 (optional)
}
```

**Response**: Server-Sent Events (text/event-stream)

**Event Format**:
```
data: {"type":"text_delta","delta":"Hello"}\n\n
data: {"type":"usage","usage":{...}}\n\n
```

**Event Types**:

| Type | Content | Purpose |
|------|---------|---------|
| `message` | Full message object | Message start |
| `text_delta` | Text string | Incremental text |
| `thinking_delta` | Thinking text | Thinking content |
| `tool_use` | Tool name, ID | Tool invocation |
| `tool_result` | Result content | Tool output |
| `usage` | Token counts | Usage data |
| `error` | Error message | Error occurred |

#### Get Streaming History

```
GET /api/claude/sessions/{id}/history
```

Returns all messages from streaming stdout file.

#### Cancel Request

```
POST /api/claude/sessions/{id}/cancel
```

**Response**:
```json
{
  "success": true,
  "message": "Request cancelled",
  "process_killed": true,
  "process_status": "cancelled"
}
```

---

### Native Sessions (.jsonl)

#### List Claude Sessions

```
GET /api/claude/claude-sessions
```

**Query Parameters**:
- `project_path` - Project path (default: `/var/www`)

**Response**:
```json
{
  "sessions": [
    {
      "id": "550e8400-...",
      "timestamp": "2025-01-24T12:00:00Z",
      "prompt": "First message preview...",
      "file_size": 1234,
      "modified": 1706097600
    }
  ]
}
```

#### Load Claude Session

```
GET /api/claude/claude-sessions/{sessionId}
```

**Query Parameters**:
- `project_path` - Project path (default: `/var/www`)

**Response**:
```json
{
  "session_id": "550e8400-...",
  "messages": [
    {
      "role": "user",
      "content": "Hello",
      "timestamp": "2025-01-24T12:00:00Z"
    },
    {
      "role": "assistant",
      "content": "Hi there!",
      "timestamp": "2025-01-24T12:00:01Z",
      "usage": {
        "input_tokens": 10,
        "output_tokens": 5
      },
      "model": "claude-sonnet-4-5-20250929",
      "cost": 0.0001
    }
  ]
}
```

**Filtering Applied**:
- Removes sidechain/meta messages
- Extracts command output from XML tags
- Filters synthetic messages
- Calculates cost server-side

---

### Voice Transcription

#### Transcribe Audio

```
POST /api/claude/transcribe
```

**Request**: Form data with `audio` file

**Supported Formats**: webm, wav, mp3, m4a, ogg

**Max Size**: 10MB

**Response**:
```json
{
  "transcription": "Hello world",
  "success": true
}
```

**Error Codes**:
- 422 - Validation error
- 428 - OpenAI key not configured
- 500 - Processing error

#### Check OpenAI Key

```
GET /api/claude/openai-key/check
```

**Response**:
```json
{
  "configured": true
}
```

#### Save OpenAI Key

```
POST /api/claude/openai-key
```

**Request**:
```json
{
  "api_key": "sk-..."
}
```

#### Delete OpenAI Key

```
DELETE /api/claude/openai-key
```

---

### Quick Settings

#### Get Settings

```
GET /api/claude/quick-settings
```

**Response**:
```json
{
  "model": "claude-sonnet-4-5-20250929",
  "permissionMode": "acceptEdits",
  "maxTurns": 50
}
```

#### Save Settings

```
POST /api/claude/quick-settings
```

**Request**:
```json
{
  "model": "claude-sonnet-4-5-20250929",
  "permissionMode": "acceptEdits",
  "maxTurns": 50
}
```

**Valid Models**:
- `claude-haiku-4-5-20251001`
- `claude-sonnet-4-5-20250929`
- `claude-opus-4-1-20250805`

**Valid Permission Modes**:
- `default`
- `acceptEdits`
- `plan`
- `bypassPermissions`

---

### Slash Commands

#### List Commands

```
GET /api/claude/commands/list
```

**Response**:
```json
{
  "commands": [
    {
      "name": "review",
      "description": "Review code changes",
      "argumentHint": "[file-pattern]"
    }
  ]
}
```

---

### Model Pricing

#### Get Pricing

```
GET /api/pricing/{modelName}
```

**Response**:
```json
{
  "model_name": "claude-sonnet-4-5-20250929",
  "input_price_per_million": 3.0,
  "output_price_per_million": 15.0,
  "cache_write_multiplier": 1.25,
  "cache_read_multiplier": 0.1
}
```

#### Save Pricing

```
POST /api/pricing/{modelName}
```

**Request**:
```json
{
  "input_price_per_million": 3.0,
  "output_price_per_million": 15.0,
  "cache_write_multiplier": 1.25,
  "cache_read_multiplier": 0.1
}
```

---

## Route Order Warning

**CRITICAL**: Route order matters in Laravel. Auth routes MUST be defined BEFORE wildcard routes:

```php
// CORRECT ORDER in web.php
Route::get('/claude/auth', ...);        // Specific first
Route::get('/claude/{sessionId?}', ...); // Wildcard last
```

If reversed, `/claude/auth` will never match because `{sessionId?}` captures everything.

---

## Credential Format

When uploading credentials via `/claude/auth/upload-json`:

```json
{
  "json": "{\"claudeAiOauth\":{\"accessToken\":\"...\",\"refreshToken\":\"...\",\"expiresAt\":1234567890000}}"
}
```

**Required Fields**:
- `claudeAiOauth.accessToken`
- `claudeAiOauth.refreshToken`
- `claudeAiOauth.expiresAt` (milliseconds timestamp)
