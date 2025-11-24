# Claude Integration Module

Complete documentation for the Claude Code AI integration in PocketDev.

## Components

| Component | File | Purpose |
|-----------|------|---------|
| ClaudeCodeService | `app/Services/ClaudeCodeService.php` | CLI wrapper, process management |
| ClaudeController | `app/Http/Controllers/Api/ClaudeController.php` | API endpoints |
| ClaudeAuthController | `app/Http/Controllers/ClaudeAuthController.php` | Credential management |
| OpenAIService | `app/Services/OpenAIService.php` | Voice transcription |
| AppSettingsService | `app/Services/AppSettingsService.php` | Encrypted settings |
| ClaudeSession | `app/Models/ClaudeSession.php` | Session metadata model |
| ModelPricing | `app/Models/ModelPricing.php` | Cost calculation model |
| chat.blade.php | `resources/views/chat.blade.php` | Frontend interface |

---

## ClaudeCodeService

**File**: `app/Services/ClaudeCodeService.php` (~854 lines)

### Core Responsibilities
- Wraps Claude Code CLI execution
- Handles sync and streaming modes
- Manages process lifecycle
- Writes interruption markers

### Key Methods

#### Process Execution

**`query(string $prompt, array $options): array`**
- Synchronous execution
- Uses `--print --output-format json`
- Returns parsed JSON response

**`streamQuery(string $prompt, callable $callback, array $options): void`**
- Streaming execution
- Uses `--output-format stream-json --verbose --include-partial-messages`
- Calls `$callback` for each line

**`startBackgroundProcess(...): int`**
- Detaches process
- Writes to `/tmp/claude-{sessionId}-stdout.jsonl`
- Returns PID for tracking

#### Command Building

```php
protected function buildCommandFlags(array $options): string
```

Constructs CLI flags:
- `--model` (model name)
- `--permission-mode` (default, acceptEdits, plan, bypassPermissions)
- `--allowed-tools` (Read, Write, Edit, Bash, Grep, Glob)
- `--max-turns` (conversation limit)

#### Session Management

| Message Type | Flag |
|--------------|------|
| First message | `--session-id {uuid}` |
| Resume | `--resume {uuid}` |

Session files stored at: `~/.claude/projects/{encoded-dir}/{session-id}.jsonl`

#### Environment Setup

```php
protected function buildEnvironment(int $thinkingLevel, ?string $model = null): ?array
```

Sets:
- `MAX_THINKING_TOKENS` - Based on thinking level (0-32000)
- `CLAUDE_CODE_SUBAGENT_MODEL` - For nested calls
- Copies: PATH, HOME, USER, SHELL, TERM, LANG, GIT_*

#### Process Control

| Method | Purpose |
|--------|---------|
| `writePidFile(sessionId, pid)` | Store PID in `/tmp/claude-session-{id}.pid` |
| `readPidFile(sessionId)` | Retrieve PID |
| `killProcess(pid)` | SIGTERM → SIGKILL fallback |
| `isProcessAlive(pid)` | Check `/proc/{pid}/status` |

#### Interruption Handling

```php
public function writeInterruptionMarker(?string $sessionId, string $cwd): void
```

Appends `[Request interrupted by user]` to session file in Claude's native format.

---

## ClaudeController

**File**: `app/Http/Controllers/Api/ClaudeController.php` (~1001 lines)

### Endpoints

#### Session Management

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/claude/sessions` | Create session |
| GET | `/api/claude/sessions` | List sessions |
| GET | `/api/claude/sessions/{id}/status` | Session status |

#### Streaming

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/claude/sessions/{id}/stream` | Stream query (SSE) |
| GET | `/api/claude/sessions/{id}/history` | Get streaming history |
| POST | `/api/claude/sessions/{id}/cancel` | Cancel request |

#### Native Sessions (.jsonl)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/claude/claude-sessions` | List .jsonl files |
| GET | `/api/claude/claude-sessions/{id}` | Load session history |

#### Voice & Settings

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/claude/transcribe` | Transcribe audio |
| GET | `/api/claude/openai-key/check` | Check key status |
| POST | `/api/claude/openai-key` | Save key |
| DELETE | `/api/claude/openai-key` | Remove key |
| GET | `/api/claude/quick-settings` | Get user settings |
| POST | `/api/claude/quick-settings` | Save user settings |

### Streaming Implementation

**`streamQuery(Request, ClaudeSession): StreamedResponse`**

1. Validate input (prompt, thinking_level 0-4)
2. Determine if first message or resume
3. Increment turn count
4. Load user preferences from database
5. Handle new turn vs reconnection:
   - **NEW TURN**: Start background process, store PID
   - **RECONNECTION**: Replay existing lines
6. Tail `/tmp/claude-{sessionId}-stdout.jsonl`
7. Send SSE events: `data: {json}\n\n`
8. Complete and clear PID

### Session Loading

**`loadClaudeSession(Request, string $sessionId)`**

Filters out from response:
- Sidechain messages (`isSidechain`)
- Meta messages (`isMeta`, `queue-operation`)
- Caveat messages
- Slash command metadata XML
- Synthetic "No response requested."
- Empty user messages

Extracts:
- Command stdout from `<local-command-stdout>`
- Command stderr from `<local-command-stderr>`

Calculates:
- Cost using ModelPricing table
- Cache token multipliers

### Cost Calculation

```php
private function calculateMessageCost(?array $usage, ?string $modelName): ?float
```

Formula:
```
inputCost = (input_tokens / 1M) × input_price
cacheWriteCost = (cache_creation_tokens / 1M) × input_price × 1.25
cacheReadCost = (cache_read_tokens / 1M) × input_price × 0.1
outputCost = (output_tokens / 1M) × output_price
total = inputCost + cacheWriteCost + cacheReadCost + outputCost
```

---

## ClaudeAuthController

**File**: `app/Http/Controllers/ClaudeAuthController.php` (~276 lines)

### Credential Storage

**Path**: `{HOME}/.claude/.credentials.json`

**Structure**:
```json
{
  "claudeAiOauth": {
    "accessToken": "...",
    "refreshToken": "...",
    "expiresAt": 1234567890000,
    "subscriptionType": "pro",
    "scopes": ["read", "write"]
  }
}
```

### Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/claude/auth` | Auth status page |
| GET | `/claude/auth/status` | JSON status |
| POST | `/claude/auth/upload` | Upload file |
| POST | `/claude/auth/upload-json` | Upload JSON string |
| DELETE | `/claude/auth/logout` | Remove credentials |

### Validation

Required fields:
- `claudeAiOauth.accessToken`
- `claudeAiOauth.refreshToken`
- `claudeAiOauth.expiresAt`

---

## OpenAIService

**File**: `app/Services/OpenAIService.php` (~84 lines)

### Purpose
Transcribes audio files using OpenAI Whisper API.

### Methods

**`transcribeAudio(UploadedFile $audioFile): string`**

- Posts to `{baseUrl}/audio/transcriptions`
- Uses `gpt-4o-transcribe` model
- 30-second timeout
- Returns plain text

### Configuration

API key sources (in order):
1. `config('services.openai.api_key')`
2. `AppSettingsService->getOpenAiApiKey()`

---

## AppSettingsService

**File**: `app/Services/AppSettingsService.php` (~105 lines)

### Purpose
Manages encrypted key-value settings in database.

### Methods

| Method | Purpose |
|--------|---------|
| `get(key, default)` | Retrieve setting |
| `set(key, value)` | Create/update setting |
| `has(key)` | Check existence |
| `delete(key)` | Remove setting |

### OpenAI Key Helpers

```php
getOpenAiApiKey(): ?string
setOpenAiApiKey(string $apiKey): void
hasOpenAiApiKey(): bool
deleteOpenAiApiKey(): bool
```

---

## Models

### ClaudeSession

**Table**: `claude_sessions`

**Key Fields**:
- `claude_session_id` - UUID linking to .jsonl file
- `title`, `project_path` - Session metadata
- `turn_count` - Conversation progress
- `status` - active/completed/failed
- `process_pid`, `process_status` - Streaming state

**Key Methods**:
- `incrementTurn()` - Bump turn count
- `startStreaming(pid)` - Begin streaming
- `completeStreaming()` - End streaming
- `cancelStreaming()` - Abort streaming
- `isStreaming()` - Check if in progress

**Note**: Messages are NOT stored in database. Only metadata.

### ModelPricing

**Table**: `model_pricing`

**Fields**:
- `model_name` - Claude model identifier
- `input_price_per_million` - Input token cost
- `output_price_per_million` - Output token cost
- `cache_write_multiplier` - Default 1.25
- `cache_read_multiplier` - Default 0.1

### AppSetting

**Table**: `app_settings`

**Fields**:
- `key` - Setting key
- `value` - Encrypted value

Uses Laravel's `encrypted` cast for automatic encrypt/decrypt.

---

## Configuration

**File**: `config/claude.php`

| Setting | Default | Purpose |
|---------|---------|---------|
| `cli_path` | `'claude'` | Path to CLI |
| `default_model` | `'claude-sonnet-4-5-20250929'` | Default model |
| `allowed_tools` | Read, Write, Edit, Bash, Grep, Glob | Enabled tools |
| `permission_mode` | `'acceptEdits'` | Permission handling |
| `max_turns` | 50 | Max conversation turns |
| `timeout` | 300 | Seconds |
| `working_directory` | `'/workspace'` | Working dir |

### Thinking Modes

| Level | Name | Tokens |
|-------|------|--------|
| 0 | Off | 0 |
| 1 | Think | 4,000 |
| 2 | Think Hard | 10,000 |
| 3 | Think Harder | 20,000 |
| 4 | Ultrathink | 32,000 |

---

## Session File Encoding

Path: `~/.claude/projects/{encoded-dir}/{session-id}.jsonl`

**Encoding rules**:
- `/` becomes `-`
- `/workspace/project` → `-workspace-project`
- Root `/` → `-`

---

## Exception Hierarchy

| Exception | Trigger |
|-----------|---------|
| `CLINotFoundException` | CLI not in PATH |
| `ClaudeCodeException` | Base exception |
| `JSONDecodeException` | JSON parse error |
| `ProcessFailedException` | Non-zero exit |
| `TimeoutException` | Operation timeout |
