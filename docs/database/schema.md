# Database Schema

Current schema as of December 2025.

**Note:** This documents CURRENT STATE, not migration history.

## Custom Tables

### claude_sessions

Session metadata. Messages are stored in Claude's `.jsonl` files, not here.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | bigint | No | auto | Primary key |
| claude_session_id | uuid | Yes | null | Claude CLI session UUID |
| title | varchar | Yes | null | Display name |
| project_path | varchar | No | - | Working directory |
| model | varchar | No | 'claude-sonnet-4-5-20250929' | Model name |
| turn_count | int | No | 0 | Conversation turns |
| status | varchar | No | 'active' | active/completed/failed |
| streaming_state | json | Yes | null | Incomplete stream state (for recovery) |
| last_activity_at | timestamp | Yes | null | Last interaction time |
| created_at | timestamp | Yes | null | Created timestamp |
| updated_at | timestamp | Yes | null | Updated timestamp |

**Indexes:**
- `claude_sessions_status_index` on `status`
- `claude_sessions_last_activity_at_index` on `last_activity_at`
- `claude_sessions_project_path_index` on `project_path`
- `claude_sessions_claude_session_id_unique` on `claude_session_id` (unique)

**Model:** `app/Models/ClaudeSession.php`

**Key Notes:**
- `claude_session_id` is the UUID used by Claude CLI
- `id` is the Laravel auto-increment ID used in routes
- `messages` and `context` columns were removed; messages live in `.jsonl` files

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
- Currently stores: `openai_api_key`

### model_pricing

Token pricing for cost calculation.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | bigint | No | auto | Primary key |
| model_name | varchar | No | - | Model identifier (unique) |
| input_price_per_million | decimal(10,6) | Yes | null | Input token price |
| cache_write_multiplier | decimal(5,3) | No | 1.250 | Cache write price multiplier |
| cache_read_multiplier | decimal(5,3) | No | 0.100 | Cache read price multiplier |
| output_price_per_million | decimal(10,6) | Yes | null | Output token price |
| created_at | timestamp | Yes | null | Created timestamp |
| updated_at | timestamp | Yes | null | Updated timestamp |

**Indexes:**
- `model_pricing_model_name_unique` on `model_name` (unique)

**Model:** `app/Models/ModelPricing.php`

**Key Notes:**
- Prices are per million tokens
- Cache multipliers apply to input price (e.g., cache_write = input_price * 1.25)
- Used by frontend for real-time cost calculation during streaming

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

### Get Active Sessions

```sql
SELECT * FROM claude_sessions
WHERE status = 'active'
ORDER BY last_activity_at DESC;
```

### Get Sessions for Project

```sql
SELECT * FROM claude_sessions
WHERE project_path = '/workspace/myproject'
ORDER BY created_at DESC;
```

### Get Model Pricing

```sql
SELECT * FROM model_pricing
WHERE model_name = 'claude-sonnet-4-5-20250929';
```

### Get Setting

```sql
SELECT value FROM app_settings
WHERE key = 'openai_api_key';
-- Note: value is encrypted, use AppSettingsService to decrypt
```

## Migrations

Located in `www/database/migrations/`:

- `0001_01_01_000000_create_users_table.php`
- `0001_01_01_000001_create_cache_table.php`
- `0001_01_01_000002_create_jobs_table.php`
- `2025_10_16_201350_create_claude_sessions_table.php`
- `2025_10_20_124210_add_claude_session_id_to_claude_sessions_table.php`
- `2025_10_20_200821_remove_messages_and_context_from_claude_sessions_table.php`
- `2025_10_25_142434_add_streaming_state_to_claude_sessions_table.php`
- `2025_10_26_140938_create_model_pricing_table.php`
- `2025_10_26_214333_create_app_settings_table.php`

**Run migrations:**
```bash
docker compose exec pocket-dev-php php artisan migrate
```
