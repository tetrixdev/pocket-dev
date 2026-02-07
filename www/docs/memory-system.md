# Memory System V2 Architecture

## TLDR

The Memory System provides persistent, semantically-searchable storage using **dynamic PostgreSQL tables**. AI can create proper typed tables with indexes, not just JSONB storage.

**Key concepts:**
- **Tables** = User-defined PostgreSQL tables in `memory` schema
- **Embeddings** = Central table for cross-table semantic search
- **Schema Registry** = Metadata about tables and embeddable fields
- **Snapshots** = Tiered disaster recovery (hourly → daily → archived)

**Read level:** 📖 Full read recommended for implementation

---

## Requirements

- **PostgreSQL 17** with extensions:
  - **pgvector 0.5+** (HNSW index support)
  - **PostGIS** (geospatial queries)
  - **pg_trgm** (fuzzy text search)
- **OpenAI API key** for embeddings
  - Model: `text-embedding-3-small` (1536 dimensions)
  - Configure via Settings UI

---

## Overview

Memory System V2 replaces the JSONB-based storage with dynamic PostgreSQL tables. AI can now create proper typed tables with indexes, enabling efficient queries and better data integrity.

### V1 → V2 Migration

| Aspect | V1 (JSONB) | V2 (Dynamic Tables) |
|--------|------------|---------------------|
| Storage | All data in `memory_objects.data` | Dedicated tables per entity type |
| Typing | JSON validation only | PostgreSQL column types |
| Indexes | GIN on JSONB | Native indexes (B-tree, HNSW, GiST) |
| Queries | JSONB operators | Standard SQL |
| Schema changes | Update JSON Schema | Recreate table (with data migration) |

---

## Architecture

### Database Schema

```text
┌─────────────────────────────────────────────────────────────┐
│  memory.schema_registry (Protected)                         │
│  ├── table_name (PK, varchar)                              │
│  ├── description (text)                                     │
│  ├── embed_fields (text[]) - columns to auto-embed         │
│  └── created_at, updated_at                                │
├─────────────────────────────────────────────────────────────┤
│  memory.embeddings (Protected)                              │
│  ├── id (UUID, PK)                                          │
│  ├── source_table (varchar)                                 │
│  ├── source_id (varchar)                                    │
│  ├── field_name (varchar)                                   │
│  ├── content_hash (varchar) - detect changes                │
│  └── embedding (vector(1536) with HNSW index)               │
├─────────────────────────────────────────────────────────────┤
│  memory.<user_tables>                                        │
│  ├── id (UUID, PK, auto-generated)                          │
│  ├── ...user-defined columns...                             │
│  └── created_at, updated_at (auto-added)                    │
└─────────────────────────────────────────────────────────────┘
```

### Database Users

| User | Role | Purpose |
|------|------|---------|
| `memory_readonly` | SELECT on memory schema | Query tool (safe) |
| `memory_ai` | Full access to memory schema | DDL/DML operations |
| `pocketdev` | Superuser | Migrations only |

---

## How It Works

### 1. Create a Table

```bash
pd memory:schema:create-table \
  --name=characters \
  --description="Player and NPC characters" \
  --columns='[
    {"name":"name","type":"VARCHAR(255)","nullable":false,"description":"Character name"},
    {"name":"class","type":"VARCHAR(100)","description":"Character class"},
    {"name":"level","type":"INTEGER","default":"1","description":"Character level"},
    {"name":"backstory","type":"TEXT","description":"Character background","embed":true},
    {"name":"location_id","type":"UUID","description":"Current location reference"}
  ]'
```

This creates:
1. Table `memory.characters` with typed columns
2. Entry in `memory.schema_registry` with embed_fields=['backstory']
3. Column comments from descriptions

### 2. Insert Data (Auto-Embedding)

```bash
pd memory:insert \
  --table=characters \
  --data='{"name":"Thorin","class":"fighter","level":5,"backstory":"A dwarf warrior..."}'
```

The system:
1. Inserts row with auto-generated UUID
2. Checks schema_registry for embed_fields
3. Generates embeddings for 'backstory'
4. Stores in memory.embeddings with source_table/source_id

### 3. Query with Semantic Search

```bash
pd memory:query \
  --sql="SELECT c.id, c.name, 1 - (e.embedding <=> :search_embedding) as sim
         FROM memory.characters c
         JOIN memory.embeddings e ON e.source_table = 'characters' AND e.source_id::uuid = c.id
         WHERE 1 - (e.embedding <=> :search_embedding) > 0.5
         ORDER BY sim DESC" \
  --search_text="warriors who lost their families"
```

### 4. Update Data (Auto-Re-Embedding)

```bash
pd memory:update \
  --table=characters \
  --data='{"backstory":"Updated backstory..."}' \
  --where="name = 'Thorin'"
```

Changed embeddable fields are automatically re-embedded.

### 5. Schema Changes (Recreate Pattern)

To modify table structure, use the recreate pattern:

```bash
# 1. Create new table with updated schema
pd memory:schema:create-table --name=characters_v2 ...

# 2. Migrate data
pd memory:schema:execute \
  --sql="INSERT INTO memory.characters_v2 (id, name, ...) SELECT id, name, ... FROM memory.characters"

# 3. Drop old table
pd memory:schema:execute --sql="DROP TABLE memory.characters"

# 4. Rename new table
pd memory:schema:execute --sql="ALTER TABLE memory.characters_v2 RENAME TO characters"

# 5. Update registry (use memory:schema:execute for consistency)
pd memory:schema:execute \
  --sql="UPDATE memory.schema_registry SET table_name = 'characters' WHERE table_name = 'characters_v2'"
```

---

## AI Tools

### Schema Tools (memory_schema category)

| Tool | Command | Purpose |
|------|---------|---------|
| MemorySchemaCreateTable | `memory:schema:create-table` | Create typed tables |
| MemorySchemaExecute | `memory:schema:execute` | DDL operations (ALTER, DROP, CREATE INDEX) |
| MemorySchemaListTables | `memory:schema:list-tables` | List tables with columns |

### Data Tools (memory_data category)

| Tool | Command | Purpose |
|------|---------|---------|
| MemoryInsert | `memory:insert` | Insert with auto-embedding |
| MemoryQuery | `memory:query` | SELECT with semantic search |
| MemoryUpdate | `memory:update` | Update with auto-re-embedding |
| MemoryDelete | `memory:delete` | Delete with embedding cleanup |

---

## Supported Column Types

| Category | Types |
|----------|-------|
| **Text** | VARCHAR(n), TEXT, CHAR(n) |
| **Numeric** | INTEGER, BIGINT, SMALLINT, DECIMAL(p,s), NUMERIC(p,s), REAL, DOUBLE PRECISION |
| **Boolean** | BOOLEAN |
| **Date/Time** | DATE, TIME, TIMESTAMP, TIMESTAMPTZ, INTERVAL |
| **JSON** | JSON, JSONB |
| **Binary** | BYTEA |
| **UUID** | UUID |
| **Arrays** | type[] (e.g., TEXT[], INTEGER[]) |
| **Vector** | VECTOR(dimensions) |
| **Geometry** | GEOMETRY, GEOGRAPHY |

---

## Snapshots & Disaster Recovery

### Automatic Snapshots

The scheduler creates snapshots automatically:
- **Hourly**: Keep for 24 hours
- **Daily (4/day)**: Keep for 7 days
- **Daily (1/day)**: Keep for 30 days (configurable)

### Manual Commands

```bash
# Create snapshot
pd memory:snapshot create

# Create schema-only snapshot
pd memory:snapshot create --schema-only

# List snapshots
pd memory:snapshot list

# Restore (creates backup first)
pd memory:snapshot restore memory_20250115_120000.sql

# Prune old snapshots
pd memory:snapshot prune
```

### Export/Import

Via Settings → Memory page:
- **Full Backup** - Data + Schema
- **Schema Only** - Structure without data
- **Import** - Upload and restore

---

## File Structure

```text
app/
├── Services/
│   ├── MemorySchemaService.php    # DDL operations (memory_ai connection)
│   ├── MemoryDataService.php      # DML with auto-embedding
│   ├── MemoryEmbeddingService.php # Embedding generation/storage
│   └── MemorySnapshotService.php  # pg_dump/pg_restore
├── Tools/
│   ├── MemorySchemaCreateTableTool.php
│   ├── MemorySchemaExecuteTool.php
│   ├── MemorySchemaListTablesTool.php
│   ├── MemoryInsertTool.php
│   ├── MemoryQueryTool.php
│   ├── MemoryUpdateTool.php
│   └── MemoryDeleteTool.php
├── Console/Commands/
│   ├── MemorySchemaCreateTableCommand.php
│   ├── MemorySchemaExecuteCommand.php
│   ├── MemorySchemaListTablesCommand.php
│   ├── MemoryInsertCommand.php
│   ├── MemoryQueryCommand.php
│   ├── MemoryUpdateCommand.php
│   ├── MemoryDeleteCommand.php
│   └── MemorySnapshotCommand.php
└── Http/Controllers/
    └── MemoryController.php       # Settings UI

config/
├── memory.php                     # Memory configuration
└── database.php                   # memory_ai, memory_readonly connections

database/migrations/
├── create_memory_schema.php       # Schema + extensions
├── create_memory_embeddings.php   # Central embeddings table
├── create_memory_schema_registry.php
└── create_memory_users.php        # Database users

routes/
└── console.php                    # Snapshot scheduler
```

---

## Configuration

### config/memory.php

```php
return [
    'snapshot_retention_days' => env('MEMORY_SNAPSHOT_RETENTION_DAYS', 30),
    'snapshot_path' => storage_path('memory_snapshots'),
    'pg_dump_path' => env('MEMORY_PG_DUMP_PATH', '/usr/bin/pg_dump'),
    'pg_restore_path' => env('MEMORY_PG_RESTORE_PATH', '/usr/bin/pg_restore'),
];
```

### config/database.php connections

```php
'pgsql_memory_ai' => [
    // Full access for schema/data operations
    'username' => env('DB_MEMORY_AI_USERNAME', 'memory_ai'),
    'password' => env('DB_MEMORY_AI_PASSWORD', ''),
],
'pgsql_readonly' => [
    // SELECT only for queries
    'username' => env('DB_READONLY_USERNAME', 'memory_readonly'),
    'password' => env('DB_READONLY_PASSWORD', ''),
],
```

---

## Security

### Protected Tables

The following tables cannot be dropped or truncated:
- `memory.embeddings`
- `memory.schema_registry`

### SQL Safety

- Schema tools validate table/column names
- Query tool only allows SELECT statements
- UPDATE/DELETE require WHERE clause (no bulk operations)

### Embedding Safety

- Content hashed before embedding
- Unchanged content not re-embedded
- Embeddings cleaned up on row deletion

---

## Best Practices

### 1. Use Descriptive Column Comments

```json
{"name":"hp","type":"INTEGER","description":"Current hit points (0 = unconscious)"}
```

### 2. Mark Embeddable Fields

Only text fields that should be semantically searchable:
```json
{"name":"backstory","type":"TEXT","embed":true,"description":"Character background"}
```

### 3. Use UUIDs for References

```json
{"name":"owner_id","type":"UUID","description":"Character who owns this item"}
```

### 4. Create Indexes for Common Queries

```bash
pd memory:schema:execute \
  --sql="CREATE INDEX idx_characters_class ON memory.characters(class)"
```

### 5. Regular Snapshots

Enable automatic snapshots and configure retention via Settings → Memory.

---

## Getting Started

1. Ensure PostgreSQL is running with extensions (pgvector, PostGIS, pg_trgm)
2. Run migrations: `php artisan migrate`
3. Add OpenAI API key via **Settings → Credentials**
4. Create a table using AI or artisan command
5. Insert data and search semantically
6. Configure snapshots via **Settings → Memory**
