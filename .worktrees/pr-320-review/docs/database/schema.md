# Database Schema

Current schema as of December 2025.

**Note:** This documents CURRENT STATE, not migration history.

## Custom Tables

### conversations

Multi-provider conversation storage with full message history.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | bigint | No | auto | Primary key |
| uuid | uuid | No | - | URL-safe identifier (unique) |
| title | varchar | Yes | null | Display name |
| working_directory | varchar | No | '/var/www' | Context directory |
| provider_type | varchar | No | 'anthropic' | Provider: anthropic, openai, claude_code |
| model | varchar | No | - | Model identifier |
| status | varchar | No | 'idle' | idle/processing/archived/failed (see below) |
| anthropic_thinking_budget | int | Yes | null | Thinking tokens (Anthropic) |
| openai_reasoning_effort | varchar | Yes | null | Reasoning level (OpenAI) |
| claude_code_thinking_tokens | int | Yes | null | Thinking tokens (Claude Code) |
| claude_session_id | varchar | Yes | null | Session ID for Claude Code CLI continuity |
| response_level | int | No | 1 | Response verbosity (0-3) |
| total_input_tokens | bigint | No | 0 | Cumulative input tokens |
| total_output_tokens | bigint | No | 0 | Cumulative output tokens |
| last_activity_at | timestamp | Yes | null | Last interaction time |
| created_at | timestamp | Yes | null | Created timestamp |
| updated_at | timestamp | Yes | null | Updated timestamp |

**Indexes:**
- `conversations_uuid_unique` on `uuid` (unique)
- `conversations_status_index` on `status`
- `conversations_provider_type_index` on `provider_type`
- `conversations_last_activity_at_index` on `last_activity_at`

**Model:** `app/Models/Conversation.php`

**Conversation Statuses:**

| Status | When Active | Triggered By | Purpose |
|--------|-------------|--------------|---------|
| `idle` | Default state, ready for input | `completeProcessing()`, `unarchive()` | Conversation is available for new messages |
| `processing` | AI is generating response | `startProcessing()` in job | Prevents concurrent requests, shows loading UI |
| `archived` | User archived conversation | `archive()` | Hides from active list, preserves history |
| `failed` | Error during processing | `markFailed()` when stream fails | Indicates error; user can still continue |

**Lifecycle:** `idle` → `processing` → `idle` (or `failed` on error). Archived conversations return to `idle` when unarchived. The `CleanupStaleConversations` command marks conversations stuck in `processing` for >30 minutes as `failed`.

**Scopes:** `scopeActive()` includes `idle`, `processing`, and `failed` (so users can retry after errors). `scopeArchived()` filters to only `archived`.

### messages

Messages within conversations.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | bigint | No | auto | Primary key |
| conversation_id | bigint | No | - | Foreign key to conversations |
| role | varchar(20) | No | - | user/assistant/system/tool |
| content | json | No | - | Message content (native provider format) |
| input_tokens | int | Yes | null | Input tokens used |
| output_tokens | int | Yes | null | Output tokens used |
| cache_creation_tokens | int | Yes | null | Cache creation tokens (Anthropic) |
| cache_read_tokens | int | Yes | null | Cache read tokens (Anthropic) |
| stop_reason | varchar(50) | Yes | null | Stop reason |
| model | varchar(100) | Yes | null | Model used for this message |
| cost | decimal(10,6) | Yes | null | Cost in USD (calculated server-side) |
| sequence | int | No | - | Ordering within conversation |
| created_at | timestamp | Yes | null | Created timestamp |

**Indexes:**
- `messages_conversation_id_sequence_unique` on `(conversation_id, sequence)` (unique, implicitly provides indexing)

**Foreign Keys:**
- `messages_conversation_id_foreign` on `conversation_id` → `conversations.id` (cascade delete)

**Model:** `app/Models/Message.php`

### ai_models

AI model configuration and pricing.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | bigint | No | auto | Primary key |
| model_id | varchar | No | - | Model identifier (unique) |
| provider | varchar | No | - | Provider: anthropic, openai |
| display_name | varchar | Yes | null | Human-readable name |
| input_price_per_million | decimal(10,6) | Yes | null | Input token price |
| output_price_per_million | decimal(10,6) | Yes | null | Output token price |
| cache_write_price_per_million | decimal(10,6) | Yes | null | Cache write price |
| cache_read_price_per_million | decimal(10,6) | Yes | null | Cache read price |
| context_window | int | Yes | null | Context window size |
| supports_thinking | tinyint | No | 0 | Extended thinking support |
| supports_tools | tinyint | No | 1 | Tool use support |
| is_available | tinyint | No | 1 | Currently available |
| created_at | timestamp | Yes | null | Created timestamp |
| updated_at | timestamp | Yes | null | Updated timestamp |

**Indexes:**
- `ai_models_model_id_unique` on `model_id` (unique)
- `ai_models_provider_index` on `provider`

**Model:** `app/Models/AiModel.php`

### app_settings

Encrypted key-value store for sensitive application settings.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | bigint | No | auto | Primary key |
| key | varchar | No | - | Setting name (unique) |
| value | text | Yes | null | Encrypted value |
| created_at | timestamp | Yes | null | Created timestamp |
| updated_at | timestamp | Yes | null | Updated timestamp |

**Indexes:**
- `app_settings_key_unique` on `key` (unique)

**Model:** `app/Models/AppSetting.php`

**Key Notes:**
- Values are automatically encrypted/decrypted using Laravel's `encrypted` cast
- Currently stores: `openai_api_key`, `anthropic_api_key`, chat defaults

## Laravel Standard Tables

### users

Standard Laravel users table. Currently unused (no user authentication beyond Basic Auth).

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | varchar | User name |
| email | varchar | Email (unique) |
| email_verified_at | timestamp | Verification timestamp |
| password | varchar | Hashed password |
| remember_token | varchar | Remember me token |
| created_at | timestamp | Created timestamp |
| updated_at | timestamp | Updated timestamp |

### cache

Laravel cache storage.

| Column | Type | Description |
|--------|------|-------------|
| key | varchar | Cache key (primary) |
| value | mediumtext | Cached value |
| expiration | int | Expiration timestamp |

### cache_locks

Laravel cache locks for atomic operations.

| Column | Type | Description |
|--------|------|-------------|
| key | varchar | Lock key (primary) |
| owner | varchar | Lock owner |
| expiration | int | Expiration timestamp |

### jobs

Laravel queue jobs.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| queue | varchar | Queue name |
| payload | longtext | Job data |
| attempts | tinyint | Attempt count |
| reserved_at | int | Reserved timestamp |
| available_at | int | Available timestamp |
| created_at | int | Created timestamp |

### job_batches

Laravel job batches for batch processing.

| Column | Type | Description |
|--------|------|-------------|
| id | varchar | Batch ID (primary) |
| name | varchar | Batch name |
| total_jobs | int | Total job count |
| pending_jobs | int | Pending count |
| failed_jobs | int | Failed count |
| failed_job_ids | longtext | Failed job IDs |
| options | mediumtext | Batch options |
| cancelled_at | int | Cancelled timestamp |
| created_at | int | Created timestamp |
| finished_at | int | Finished timestamp |

### failed_jobs

Failed job storage for debugging.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| uuid | varchar | Job UUID (unique) |
| connection | text | Queue connection |
| queue | text | Queue name |
| payload | longtext | Job data |
| exception | longtext | Exception details |
| failed_at | timestamp | Failure timestamp |

## Common Queries

### Get Active Conversations

```sql
SELECT * FROM conversations
WHERE status IN ('idle', 'processing', 'failed')
ORDER BY last_activity_at DESC;
```

### Get Conversation with Messages

```sql
SELECT c.*, m.*
FROM conversations c
LEFT JOIN messages m ON c.id = m.conversation_id
WHERE c.uuid = ?
ORDER BY m.sequence ASC;
```

### Get Model Pricing

```sql
SELECT * FROM ai_models
WHERE model_id = 'claude-sonnet-4-5-20250929';
```

### Get Setting

```sql
SELECT value FROM app_settings
WHERE key = 'openai_api_key';
-- Note: value is encrypted, use AppSettingsService to decrypt
```

## Migrations

Located in `www/database/migrations/`. Run with:

```bash
docker compose exec pocket-dev-php php artisan migrate
```
