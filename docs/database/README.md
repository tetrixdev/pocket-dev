# Database Schema

PocketDev uses **PostgreSQL 17** for metadata storage. Messages are stored in Claude's native `.jsonl` files, NOT in the database.

## Connection

```
Host: pocket-dev-postgres
Port: 5432
Database: pocket-dev
Username: pocket-dev
Password: (from DB_PASSWORD in .env)
```

---

## Custom Tables

### claude_sessions

Session metadata for PocketDev conversations.

**Important**: Messages are NOT stored here - only metadata.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT | No | Primary key |
| `claude_session_id` | UUID | Yes | Links to .jsonl file |
| `title` | VARCHAR(255) | Yes | Human-readable title |
| `project_path` | VARCHAR(255) | No | Working directory |
| `model` | VARCHAR(255) | No | Claude model used |
| `turn_count` | INT | No | Conversation turns |
| `status` | VARCHAR(255) | No | active/completed/failed |
| `process_pid` | INT | Yes | Current process ID |
| `process_status` | ENUM | No | idle/starting/streaming/completed/failed/cancelled |
| `last_message_index` | INT | No | Last processed message |
| `last_activity_at` | TIMESTAMP | Yes | Last activity time |
| `created_at` | TIMESTAMP | No | Created |
| `updated_at` | TIMESTAMP | No | Updated |

**Indexes**:
- `status` - Filter by session status
- `last_activity_at` - Recent sessions query
- `project_path` - Sessions per project

**UUID Auto-Generation**: `claude_session_id` is auto-generated on create if not provided.

---

### model_pricing

Cost calculation data per model.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT | No | Primary key |
| `model_name` | VARCHAR(255) | No | Model identifier (unique) |
| `input_price_per_million` | DECIMAL(10,6) | Yes | Input token cost |
| `output_price_per_million` | DECIMAL(10,6) | Yes | Output token cost |
| `cache_write_multiplier` | DECIMAL(5,3) | No | Default 1.25 |
| `cache_read_multiplier` | DECIMAL(5,3) | No | Default 0.1 |
| `created_at` | TIMESTAMP | No | Created |
| `updated_at` | TIMESTAMP | No | Updated |

**Example Data**:
```
model_name: claude-sonnet-4-5-20250929
input_price_per_million: 3.00
output_price_per_million: 15.00
cache_write_multiplier: 1.25
cache_read_multiplier: 0.10
```

---

### app_settings

Encrypted key-value store for sensitive settings.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT | No | Primary key |
| `key` | VARCHAR(255) | No | Setting key (unique) |
| `value` | TEXT | Yes | Encrypted value |
| `created_at` | TIMESTAMP | No | Created |
| `updated_at` | TIMESTAMP | No | Updated |

**Used Keys**:
- `openai_api_key` - OpenAI Whisper API key
- `user_claude_model` - User's preferred model
- `user_permission_mode` - User's permission mode
- `user_max_turns` - User's max turns setting

**Encryption**: Uses Laravel's `encrypted` cast - automatic encrypt/decrypt.

---

## Laravel Framework Tables

Standard Laravel tables (auto-created):

| Table | Purpose |
|-------|---------|
| `users` | User accounts (unused) |
| `password_reset_tokens` | Password resets (unused) |
| `sessions` | Session storage |
| `cache` | Database cache |
| `cache_locks` | Cache locking |
| `jobs` | Queue jobs |
| `job_batches` | Job batches |
| `failed_jobs` | Failed job records |
| `migrations` | Migration tracking |

---

## Models

### ClaudeSession

**File**: `app/Models/ClaudeSession.php`

**Fillable**:
```php
['title', 'project_path', 'claude_session_id', 'model',
 'turn_count', 'status', 'last_activity_at',
 'process_pid', 'process_status', 'last_message_index']
```

**Casts**:
```php
['last_activity_at' => 'datetime']
```

**Key Methods**:

| Method | Purpose |
|--------|---------|
| `incrementTurn()` | Bump turn_count, update last_activity_at |
| `markCompleted()` | Set status = 'completed' |
| `markFailed()` | Set status = 'failed' |
| `startStreaming($pid)` | Begin streaming with PID |
| `completeStreaming()` | End streaming, clear PID |
| `cancelStreaming()` | Abort streaming |
| `isStreaming()` | Check if in progress |

**Scopes**:

| Scope | Query |
|-------|-------|
| `active()` | WHERE status = 'active' |
| `recent($days)` | WHERE last_activity_at >= now - $days |

---

### ModelPricing

**File**: `app/Models/ModelPricing.php`

**Fillable**:
```php
['model_name', 'input_price_per_million',
 'cache_write_multiplier', 'cache_read_multiplier',
 'output_price_per_million']
```

**Casts**:
```php
[
    'input_price_per_million' => 'decimal:6',
    'cache_write_multiplier' => 'decimal:3',
    'cache_read_multiplier' => 'decimal:3',
    'output_price_per_million' => 'decimal:6'
]
```

---

### AppSetting

**File**: `app/Models/AppSetting.php`

**Fillable**:
```php
['key', 'value']
```

**Casts**:
```php
['value' => 'encrypted']
```

**Usage**:
```php
// Create
AppSetting::create(['key' => 'openai_api_key', 'value' => 'sk-...']);

// Read (auto-decrypted)
$setting = AppSetting::where('key', 'openai_api_key')->first();
echo $setting->value; // Decrypted

// Via Service
app(AppSettingsService::class)->get('openai_api_key');
```

---

## Migration Order

1. `0001_01_01_000000` - users, password_reset_tokens, sessions
2. `0001_01_01_000001` - cache, cache_locks
3. `0001_01_01_000002` - jobs, job_batches, failed_jobs
4. `2025_10_16_201350` - claude_sessions (initial)
5. `2025_10_20_124210` - Add claude_session_id
6. `2025_10_20_200821` - Remove messages/context columns
7. `2025_10_25_142434` - Add streaming state columns
8. `2025_10_26_140938` - model_pricing
9. `2025_10_26_214333` - app_settings

---

## Key Design Decision

**Messages NOT in Database**

Messages are stored in Claude's native `.jsonl` files:
```
~/.claude/projects/{encoded-dir}/{session-id}.jsonl
```

**Why?**
- Single source of truth with Claude CLI
- No schema migrations for message format changes
- Compatible with CLI usage
- Simpler data management

**Future**: May migrate to database storage in native JSON format, then transform for display.

---

## Common Queries

### Recent Active Sessions
```sql
SELECT * FROM claude_sessions
WHERE status = 'active'
AND last_activity_at >= NOW() - INTERVAL '7 days'
ORDER BY last_activity_at DESC;
```

### Sessions by Project
```sql
SELECT * FROM claude_sessions
WHERE project_path = '/var/www'
ORDER BY created_at DESC;
```

### Model Pricing Lookup
```sql
SELECT * FROM model_pricing
WHERE model_name = 'claude-sonnet-4-5-20250929';
```

### Get Encrypted Setting
```php
// Via service (recommended)
$key = app(AppSettingsService::class)->getOpenAiApiKey();

// Direct query
$setting = AppSetting::where('key', 'openai_api_key')->first();
$value = $setting?->value; // Auto-decrypted
```
