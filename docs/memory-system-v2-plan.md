# Memory System V2: JSONB to Dynamic Tables

## Overview

Migrate PocketDev's memory system from the current JSONB-based approach (single `memory_objects` table with `data` JSONB column) to a dynamic table architecture where the AI can create proper PostgreSQL tables with typed columns, indexes, and foreign keys.

## Goals

1. **Leverage AI's SQL knowledge** - Let AI use CREATE TABLE, CREATE INDEX, proper types
2. **Enable spatial queries** - PostGIS support for coordinate-based queries
3. **Fuzzy text search** - pg_trgm for indexed ILIKE and similarity matching
4. **Proper indexing** - B-tree indexes on columns, not just GIN on JSONB
5. **Referential integrity** - Real foreign key constraints
6. **Cross-table semantic search** - Central embeddings table
7. **Automatic embeddings** - Smart insert/update commands that auto-generate embeddings
8. **Future chunking support** - Schema ready for chunked embeddings
9. **Safe operations** - Periodic schema snapshots with tiered retention
10. **Schema isolation** - AI-managed tables isolated from application tables

## Architecture

```
PostgreSQL
├── Schema: public (protected)
│   ├── users, conversations, tools, app_settings, etc.
│   └── NO direct AI write access
│
├── Schema: memory (AI-managed)
│   ├── embeddings (pre-created, PROTECTED)
│   ├── schema_registry (pre-created, PROTECTED)
│   ├── [AI-created tables...]
│   └── AI has full DDL/DML rights (except protected tables)
│
└── Snapshots stored as files
    └── /var/www/storage/memory-snapshots/*.sql

Database Users:
├── memory_readonly (existing) - SELECT only, used by memory:query
└── memory_ai (new) - DDL/DML on memory schema

Extensions: pgvector, PostGIS, pg_trgm

PITR (WAL Archiving) - Continuous incremental backup for disaster recovery
```

## Design Decisions

### 1. Tool Interface: Smart Commands with Auto-Embedding

**Schema Tools** (change structure) - `memory:schema:*`:
| Tool | Purpose |
|------|---------|
| `memory:schema:create-table` | Create table + register with column descriptions |
| `memory:schema:execute` | Other DDL (CREATE INDEX, DROP TABLE) |
| `memory:schema:list-tables` | Show available tables with schemas |

**Data Tools** (use structure) - `memory:*`:
| Tool | Purpose |
|------|---------|
| `memory:query` | SELECT queries (read-only connection) |
| `memory:insert` | Insert row + auto-generate embeddings |
| `memory:update` | Update row + auto-regenerate embeddings if needed |
| `memory:delete` | Delete row(s) |

**How auto-embedding works:**
1. AI creates table with registration in one command:
   ```bash
   php artisan memory:schema:create-table \
       --name=characters \
       --description="Player and NPC characters" \
       --embed-fields="backstory,description" \
       --column-descriptions='{
           "name": "Full name including titles",
           "backstory": "Character history, motivations, secrets. Be detailed.",
           "relationships": "Describe bidirectionally: how A feels about B AND B about A"
       }' \
       --sql="CREATE TABLE memory.characters (...)"
   ```
2. Tool executes CREATE TABLE + adds COMMENT ON COLUMN for each description
3. Tool inserts into `schema_registry` with embeddable_fields
4. AI inserts: `memory:insert --table=characters --data='{"name":"Thorin","backstory":"..."}'`
5. Insert command looks up `schema_registry.embeddable_fields`
6. Automatically generates embeddings for specified fields
7. Inserts row + embeddings in transaction

**Note:** `--embed-fields` is **required** (but can be empty string) to force AI to explicitly consider which fields should be embedded.

### 2. No ALTER TABLE Support - Recreate Pattern

Instead of supporting ALTER TABLE, AI must use the recreate pattern:

1. Create new table with new schema
2. Migrate data: `INSERT INTO new_table SELECT ... FROM old_table`
3. Update embeddings: `UPDATE memory.embeddings SET source_table = 'new_table' WHERE source_table = 'old_table'`
4. Drop old table
5. **Always confirm with user before starting**

**Benefits:**
- Simpler implementation
- Forces AI to think about migration
- Clear audit trail
- No complex ALTER detection logic

**Un-embedding a field:**
- Update schema_registry to remove from `embeddable_fields`
- Delete old embeddings:
  ```sql
  DELETE FROM memory.embeddings
  WHERE source_table = 'table_name' AND field_name = 'removed_field'
  ```

### 3. Central Embeddings Table (Pre-created, Protected)

```sql
CREATE TABLE memory.embeddings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    source_table TEXT NOT NULL,
    source_id UUID NOT NULL,
    field_name TEXT NOT NULL,
    chunk_index INTEGER DEFAULT 0,   -- 0 = whole field, 1+ = chunks (future)
    content TEXT,                    -- Original text (for debugging/reembedding)
    content_hash VARCHAR(64),        -- Detect changes
    embedding VECTOR(1536),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(source_table, source_id, field_name, chunk_index)
);

CREATE INDEX idx_embeddings_source ON memory.embeddings(source_table, source_id);
CREATE INDEX idx_embeddings_hnsw ON memory.embeddings
    USING hnsw(embedding vector_cosine_ops);
```

**Chunking support:** Initially `chunk_index = 0` always (whole field). Future chunking implementation can split into `chunk_index = 1, 2, 3...` without schema changes.

### 4. Schema Registry Table (Pre-created, Protected)

```sql
CREATE TABLE memory.schema_registry (
    table_name TEXT PRIMARY KEY,
    description TEXT,                -- For AI context in system prompt
    embeddable_fields TEXT[],        -- Fields to auto-embed on insert/update
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
```

Column descriptions are stored using PostgreSQL's native `COMMENT ON COLUMN` and retrieved via `pg_catalog.pg_description`.

### 5. Periodic Schema Snapshots (Tiered Retention)

Instead of per-operation snapshots, use periodic `pg_dump` of the memory schema with tiered retention:

**Snapshot Schedule:**
- **0-24 hours:** Hourly snapshots (24 snapshots max)
- **1-7 days:** Keep 4 per day (00:00, 06:00, 12:00, 18:00) - 24 snapshots max
- **7-30 days:** Keep 1 per day at midnight - 23 snapshots max

**Total:** ~71 snapshots max

**Storage:**
```
/var/www/storage/memory-snapshots/
├── memory_20251226_140000.sql     # Hourly
├── memory_20251225_000000.sql     # Daily
└── ...
```

**Restore Flow (Settings Page Only - AI Cannot Restore):**
1. User views list of snapshots at `/config/memory`
2. Clicks "Restore to this point"
3. System auto-snapshots current state first (can undo the restore)
4. Restores memory schema from selected snapshot

**Retention is configurable** via settings page (stored in `app_settings` table).

### 6. Database Users & Permissions

**Existing user (keep):** `memory_readonly`
- Used by `memory:query` for SELECT operations
- Password: `DB_READONLY_PASSWORD` in `.env`

**New user:** `memory_ai`
- Used by schema tools and data tools (DDL/DML)
- Password: `DB_MEMORY_AI_PASSWORD` in `.env` (generated by setup.sh)

```sql
-- memory_ai user permissions
CREATE USER memory_ai WITH PASSWORD '...';

-- memory schema: full access EXCEPT protected tables
GRANT ALL ON SCHEMA memory TO memory_ai;
ALTER DEFAULT PRIVILEGES IN SCHEMA memory GRANT ALL ON TABLES TO memory_ai;
ALTER DEFAULT PRIVILEGES IN SCHEMA memory GRANT ALL ON SEQUENCES TO memory_ai;

-- Protect specific tables (embeddings, schema_registry)
REVOKE DELETE, TRUNCATE ON memory.embeddings FROM memory_ai;
REVOKE DELETE, TRUNCATE ON memory.schema_registry FROM memory_ai;
-- Note: We still allow INSERT/UPDATE on these, just not destructive ops

-- public schema: NO access (isolation)
REVOKE ALL ON SCHEMA public FROM memory_ai;
```

### 7. PostgreSQL Extensions

```sql
-- Vector similarity search (existing)
CREATE EXTENSION IF NOT EXISTS vector;

-- Spatial queries for coordinates
CREATE EXTENSION IF NOT EXISTS postgis;

-- Fuzzy text search (indexed ILIKE, similarity matching)
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

**pg_trgm usage example:**
```sql
-- Create trigram index on name column
CREATE INDEX idx_npcs_name_trgm ON memory.npcs USING GIN (name gin_trgm_ops);

-- Fuzzy search
SELECT * FROM memory.npcs WHERE name % 'Gandolf';  -- Finds "Gandalf"
SELECT * FROM memory.npcs WHERE name ILIKE '%alf%';  -- Now uses index!
```

### 8. Migration Path

**Start fresh** - no migration of existing data. The old `memory_structures`, `memory_objects`, `memory_embeddings` tables will be dropped.

### 9. Settings Storage

| Setting | Storage | Reason |
|---------|---------|--------|
| `memory_snapshot_retention_days` | `app_settings` (DB) | User-configurable via UI |
| `snapshot_directory` | `config/memory.php` | Infrastructure path |
| `embedding_model` | `config/memory.php` + env | Requires code awareness |
| `embedding_dimensions` | `config/memory.php` | Must match model |

### 10. Settings Page

`/config/memory` - consistent with existing `/config/*` pattern.

Features:
- View registered tables with descriptions and column info
- View/manage snapshots (restore, delete)
- Configure snapshot retention (days)
- Export memory schema:
  - **Full backup** (schema + data)
  - **Schema only** (structure without data - for sharing/templates)
- Import memory schema (pg_restore)

---

## Implementation Phases

### Phase 1: Database Infrastructure

**New migrations:**

1. `2025_12_26_000001_create_memory_schema_and_extensions.php`
   - Create `memory` schema
   - Enable extensions: postgis, pg_trgm (vector already exists)

2. `2025_12_26_000002_create_memory_embeddings_table.php`
   - Create `memory.embeddings` table with `chunk_index` column
   - HNSW index for vector search
   - Source index for lookups

3. `2025_12_26_000003_create_memory_schema_registry_table.php`
   - Create `memory.schema_registry` table

4. `2025_12_26_000004_create_memory_ai_user.php`
   - Create `memory_ai` PostgreSQL user
   - Grant appropriate permissions
   - Protect embeddings and schema_registry from DROP/TRUNCATE

5. `2025_12_26_000005_drop_old_memory_tables.php`
   - Drop `memory_structures` table
   - Drop `memory_objects` table
   - Drop `memory_embeddings` table (old one in public schema)
   - Keep `memory_readonly` user (still used for queries)

**Config changes:**

- `config/database.php` - Add `pgsql_memory_ai` connection
- `config/memory.php` - New config file:
  ```php
  return [
      'snapshot_retention_days' => 30,  // Default, overrideable via app_settings
      'snapshot_directory' => storage_path('memory-snapshots'),
      'embedding_model' => env('MEMORY_EMBEDDING_MODEL', 'text-embedding-3-small'),
      'embedding_dimensions' => env('MEMORY_EMBEDDING_DIMENSIONS', 1536),
  ];
  ```

**Setup script changes:**

- `setup.sh` - Generate `DB_MEMORY_AI_PASSWORD` and add to `.env`
- `.env.example` - Add `DB_MEMORY_AI_PASSWORD=`

### Phase 2: Core Services

**New files:**

1. `app/Services/MemorySchemaService.php`
   - Execute DDL on memory schema
   - Validate table names (prevent schema escape)
   - Protect embeddings and schema_registry tables
   - Add column comments via COMMENT ON COLUMN

2. `app/Services/MemoryDataService.php`
   - Smart insert with auto-embedding
   - Smart update with embedding regeneration
   - Delete operations
   - Transaction handling

3. `app/Services/MemoryEmbeddingService.php`
   - Refactor existing EmbeddingService for new structure
   - Generate embeddings for specified fields
   - Store in central memory.embeddings table
   - Content hashing to avoid re-embedding unchanged content

4. `app/Services/MemorySnapshotService.php`
   - Create snapshots (pg_dump of memory schema)
   - List snapshots with metadata
   - Restore from snapshot (pg_restore)
   - Prune old snapshots (tiered retention)
   - Auto-snapshot before restore
   - Support schema-only export

**Modified files:**

- `app/Services/AppSettingsService.php` - Add memory settings methods:
  - `getMemorySnapshotRetentionDays(): int`
  - `setMemorySnapshotRetentionDays(int $days): AppSetting`

### Phase 3: Tools

**New tools:**

1. `app/Tools/MemorySchemaCreateTableTool.php`
   - Combined CREATE TABLE + schema_registry registration
   - Required `--embed-fields` parameter (can be empty)
   - Optional `--column-descriptions` JSON for COMMENT ON COLUMN
   - Validates SQL is actually CREATE TABLE
   - Category: `memory_schema`

2. `app/Tools/MemorySchemaExecuteTool.php`
   - DDL execution (CREATE INDEX, DROP TABLE, other SQL)
   - Validates against protected tables
   - Blocks ALTER TABLE with instruction to use recreate pattern
   - Category: `memory_schema`

3. `app/Tools/MemorySchemaListTablesTool.php`
   - List all tables in memory schema
   - Include column info with descriptions from pg_catalog
   - Include metadata from schema_registry
   - Category: `memory_schema`

4. `app/Tools/MemoryInsertTool.php`
   - Insert row into any memory.* table
   - Auto-generates embeddings based on schema_registry
   - Returns inserted row ID
   - Category: `memory_data`

5. `app/Tools/MemoryUpdateTool.php`
   - Update row(s) in memory.* table
   - Regenerates embeddings for changed embeddable fields
   - Supports WHERE clause
   - Category: `memory_data`

6. `app/Tools/MemoryDeleteTool.php`
   - Delete row(s) from memory.* table
   - Supports WHERE clause (required)
   - Category: `memory_data`

**Modified tools:**

- `app/Tools/MemoryQueryTool.php`
   - Update to query `memory.*` tables
   - Update available tables documentation
   - Keep read-only connection (`pgsql_readonly`)
   - Category: `memory_data`

**Deprecated tools (to remove):**

- `app/Tools/MemoryCreateTool.php`
- `app/Tools/MemoryUpdateTool.php` (old version)
- `app/Tools/MemoryDeleteTool.php` (old version)
- `app/Tools/MemoryStructureCreateTool.php`
- `app/Tools/MemoryStructureGetTool.php`
- `app/Tools/MemoryStructureUpdateTool.php`
- `app/Tools/MemoryStructureDeleteTool.php`

### Phase 4: Console Commands

**New commands:**

1. `app/Console/Commands/MemorySchemaCreateTableCommand.php`
   - CLI wrapper for MemorySchemaCreateTableTool

2. `app/Console/Commands/MemorySchemaExecuteCommand.php`
   - CLI wrapper for MemorySchemaExecuteTool

3. `app/Console/Commands/MemorySchemaListTablesCommand.php`
   - CLI wrapper for MemorySchemaListTablesTool

4. `app/Console/Commands/MemoryInsertCommand.php`
   - CLI wrapper for MemoryInsertTool

5. `app/Console/Commands/MemoryUpdateCommand.php`
   - CLI wrapper for MemoryUpdateTool

6. `app/Console/Commands/MemoryDeleteCommand.php`
   - CLI wrapper for MemoryDeleteTool

7. `app/Console/Commands/MemorySnapshotCommand.php`
   - `memory:snapshot create` - Create manual snapshot
   - `memory:snapshot list` - List snapshots
   - `memory:snapshot prune` - Apply tiered retention
   - **Not exposed as AI tool** - artisan/scheduler only

**Modified commands:**

- `app/Console/Commands/MemoryQueryCommand.php` - Update for new schema

**Deprecated commands (to remove):**

- `app/Console/Commands/MemoryCreateCommand.php`
- `app/Console/Commands/MemoryUpdateCommand.php` (old)
- `app/Console/Commands/MemoryDeleteCommand.php` (old)
- `app/Console/Commands/MemoryStructureCreateCommand.php`
- `app/Console/Commands/MemoryStructureGetCommand.php`
- `app/Console/Commands/MemoryStructureUpdateCommand.php`
- `app/Console/Commands/MemoryStructureDeleteCommand.php`

**Schedule (Kernel.php):**
```php
$schedule->command('memory:snapshot create')->hourly();
$schedule->command('memory:snapshot prune')->daily();
```

### Phase 5: System Prompt Updates

**Modified files:**

- `app/Services/ToolSelector.php`
   - Update `buildSystemPrompt()` for new tools
   - Replace `buildStructuresSection()` with `buildTablesSection()` reading from schema_registry
   - Add `getCategoryInstructions()` for category-level shared instructions
   - Include extension capabilities (PostGIS, pg_trgm examples)
   - Document recreate pattern in `memory_schema` category instructions

- `app/Models/PocketTool.php`
   - Add new categories: `memory_schema`, `memory_data`

**Category-level instructions:**

```php
private function getCategoryInstructions(string $category): ?string
{
    return match ($category) {
        'memory_schema' => $this->getMemorySchemaInstructions(),
        default => null,
    };
}

private function getMemorySchemaInstructions(): string
{
    return <<<'MD'
### Schema Change Guidelines

**ALTER TABLE is not supported.** To modify a table schema, use the recreate pattern:

1. Create new table: `memory:schema:create-table --name=tablename_v2 ...`
2. Migrate data: `memory:schema:execute --sql="INSERT INTO memory.new SELECT ... FROM memory.old"`
3. Update embeddings: `memory:schema:execute --sql="UPDATE memory.embeddings SET source_table = 'new' WHERE source_table = 'old'"`
4. Drop old table: `memory:schema:execute --sql="DROP TABLE memory.old"`

**Always confirm with the user before starting a schema migration.**
MD;
}
```

**New system prompt structure:**

```markdown
# Memory System

You have access to a PostgreSQL schema called `memory` where you can create
and manage tables. Use the memory:* commands to interact with it.

## Schema Operations

### Schema Change Guidelines
[Category instructions inserted here]

### memory:schema:create-table
[Tool instructions]

### memory:schema:execute
[Tool instructions]

### memory:schema:list-tables
[Tool instructions]

## Data Operations

### memory:query
[Tool instructions]

### memory:insert
[Tool instructions]

### memory:update
[Tool instructions]

### memory:delete
[Tool instructions]

## Pre-existing Tables (DO NOT DROP)

### memory.embeddings
Central storage for semantic search vectors. Automatically populated by insert/update.
Columns: id, source_table, source_id, field_name, chunk_index, content, content_hash, embedding

### memory.schema_registry
Tracks table metadata. Automatically populated by memory:schema:create-table.

## Current User Tables

[Dynamically generated from schema_registry with column descriptions]

## Extensions Available

### PostGIS (Spatial)
```sql
coordinates GEOGRAPHY(Point, 4326)
ST_DWithin(coordinates, ST_MakePoint(-122.4, 37.8)::geography, 50000)
```

### pg_trgm (Fuzzy Text)
```sql
CREATE INDEX idx_name_trgm ON memory.table USING GIN (name gin_trgm_ops);
WHERE name % 'Gandolf'  -- Finds "Gandalf"
```
```

### Phase 6: Settings Page

**New files:**

1. `app/Http/Controllers/MemoryController.php`
   - `index()` - Show memory settings/dashboard
   - `updateSettings()` - Save retention settings
   - `listSnapshots()` - AJAX endpoint for snapshots
   - `restoreSnapshot()` - Restore a snapshot (auto-snapshots current first)
   - `deleteSnapshot()` - Delete a snapshot
   - `export()` - Download pg_dump (full or schema-only)
   - `import()` - Handle pg_restore upload

2. `resources/views/memory/index.blade.php`
   - Tables list with descriptions, columns, and row counts
   - Snapshots list with tiered display (hourly/daily)
   - Restore/delete actions for snapshots
   - Settings form (retention days)
   - Export buttons (Full Backup / Schema Only)
   - Import file upload

**Routes (web.php):**
```php
// Memory management
Route::get('/config/memory', [MemoryController::class, 'index'])->name('config.memory');
Route::post('/config/memory/settings', [MemoryController::class, 'updateSettings'])->name('config.memory.settings');
Route::get('/config/memory/snapshots', [MemoryController::class, 'listSnapshots'])->name('config.memory.snapshots');
Route::post('/config/memory/snapshots/{filename}/restore', [MemoryController::class, 'restoreSnapshot'])->name('config.memory.snapshots.restore');
Route::delete('/config/memory/snapshots/{filename}', [MemoryController::class, 'deleteSnapshot'])->name('config.memory.snapshots.delete');
Route::get('/config/memory/export', [MemoryController::class, 'export'])->name('config.memory.export');
Route::post('/config/memory/import', [MemoryController::class, 'import'])->name('config.memory.import');
```

**Navigation update:**
- Add "Memory" link to config sidebar

### Phase 7: Documentation & Cleanup

**Files to update:**

- `docs/memory-system.md` - Complete rewrite for new architecture
- `CLAUDE.md` - Update memory tools section

**Files to create:**

- `docs/memory-browser-followup.md` - Future UI work for browsing memory data

**Deprecated files to remove:**

**Tools (7):**
- `app/Tools/MemoryCreateTool.php`
- `app/Tools/MemoryUpdateTool.php` (old)
- `app/Tools/MemoryDeleteTool.php` (old)
- `app/Tools/MemoryStructureCreateTool.php`
- `app/Tools/MemoryStructureGetTool.php`
- `app/Tools/MemoryStructureUpdateTool.php`
- `app/Tools/MemoryStructureDeleteTool.php`

**Commands (7):**
- `app/Console/Commands/MemoryCreateCommand.php`
- `app/Console/Commands/MemoryUpdateCommand.php` (old)
- `app/Console/Commands/MemoryDeleteCommand.php` (old)
- `app/Console/Commands/MemoryStructureCreateCommand.php`
- `app/Console/Commands/MemoryStructureGetCommand.php`
- `app/Console/Commands/MemoryStructureUpdateCommand.php`
- `app/Console/Commands/MemoryStructureDeleteCommand.php`

**Models (1):**
- `app/Models/MemoryStructure.php`

---

## File Summary

### New Files (22)

**Migrations (5):**
- `database/migrations/2025_12_26_000001_create_memory_schema_and_extensions.php`
- `database/migrations/2025_12_26_000002_create_memory_embeddings_table.php`
- `database/migrations/2025_12_26_000003_create_memory_schema_registry_table.php`
- `database/migrations/2025_12_26_000004_create_memory_ai_user.php`
- `database/migrations/2025_12_26_000005_drop_old_memory_tables.php`

**Config (1):**
- `config/memory.php`

**Services (4):**
- `app/Services/MemorySchemaService.php`
- `app/Services/MemoryDataService.php`
- `app/Services/MemoryEmbeddingService.php`
- `app/Services/MemorySnapshotService.php`

**Tools (6):**
- `app/Tools/MemorySchemaCreateTableTool.php`
- `app/Tools/MemorySchemaExecuteTool.php`
- `app/Tools/MemorySchemaListTablesTool.php`
- `app/Tools/MemoryInsertTool.php`
- `app/Tools/MemoryUpdateTool.php` (new version)
- `app/Tools/MemoryDeleteTool.php` (new version)

**Commands (4):**
- `app/Console/Commands/MemorySchemaCreateTableCommand.php`
- `app/Console/Commands/MemorySchemaExecuteCommand.php`
- `app/Console/Commands/MemorySchemaListTablesCommand.php`
- `app/Console/Commands/MemorySnapshotCommand.php`

**Controller & Views (2):**
- `app/Http/Controllers/MemoryController.php`
- `resources/views/memory/index.blade.php`

### Modified Files (9)

- `config/database.php` - Add pgsql_memory_ai connection
- `setup.sh` - Generate DB_MEMORY_AI_PASSWORD
- `.env.example` - Add DB_MEMORY_AI_PASSWORD
- `app/Tools/MemoryQueryTool.php` - Update for new schema
- `app/Console/Commands/MemoryQueryCommand.php` - Update for new schema
- `app/Services/ToolSelector.php` - New system prompt with category instructions
- `app/Services/AppSettingsService.php` - Add memory settings methods
- `app/Models/PocketTool.php` - Add memory_schema, memory_data categories
- `routes/web.php` - Memory routes

### Deprecated Files to Remove (15)

**Tools (7):**
- `app/Tools/MemoryCreateTool.php`
- `app/Tools/MemoryUpdateTool.php` (old)
- `app/Tools/MemoryDeleteTool.php` (old)
- `app/Tools/MemoryStructureCreateTool.php`
- `app/Tools/MemoryStructureGetTool.php`
- `app/Tools/MemoryStructureUpdateTool.php`
- `app/Tools/MemoryStructureDeleteTool.php`

**Commands (7):**
- `app/Console/Commands/MemoryCreateCommand.php`
- `app/Console/Commands/MemoryUpdateCommand.php` (old)
- `app/Console/Commands/MemoryDeleteCommand.php` (old)
- `app/Console/Commands/MemoryStructureCreateCommand.php`
- `app/Console/Commands/MemoryStructureGetCommand.php`
- `app/Console/Commands/MemoryStructureUpdateCommand.php`
- `app/Console/Commands/MemoryStructureDeleteCommand.php`

**Models (1):**
- `app/Models/MemoryStructure.php`

---

## Testing Strategy

### Unit Tests
- MemorySchemaService: Table name validation, protected table enforcement
- MemoryDataService: Insert with embedding, update detection
- MemoryEmbeddingService: Embedding generation, hashing
- MemorySnapshotService: Snapshot creation, tiered retention pruning

### Integration Tests
- Full workflow: create table → insert → query → semantic search
- Recreate pattern: create v2 → migrate → update embeddings → drop old
- Snapshot restore workflow

### Manual Testing
- Settings page functionality
- Export/import (full and schema-only)
- pg_trgm fuzzy search
- PostGIS spatial queries

---

## Rollback Plan

If issues arise:
1. Migrations are reversible (down methods)
2. Old tables can be restored from PITR
3. Old tool files kept in git history

---

## Security Considerations

1. **Schema isolation**: memory_ai user cannot access public schema
2. **Protected tables**: embeddings and schema_registry protected from DROP/TRUNCATE
3. **SQL validation**: Table names validated to prevent schema escape
4. **Snapshot protection**: Auto-snapshot before restore prevents accidental data loss
5. **AI cannot restore**: Snapshots only restorable via settings page by humans
6. **Read-only queries**: memory:query uses memory_readonly user
7. **User confirmation**: Schema changes (recreate pattern) require user confirmation

---

## Future Considerations

### Chunked Embeddings
The `chunk_index` column in `memory.embeddings` enables future chunking support without schema changes. Implementation would:
1. Split long text fields into chunks
2. Store each chunk with `chunk_index = 1, 2, 3...`
3. Aggregate results in semantic search queries

### Memory Browser UI
See `docs/memory-browser-followup.md` for planned features:
- Browse tables and data visually
- Search within tables
- View related rows via UUID references
- Inspect embeddings
