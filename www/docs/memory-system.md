# Memory System Architecture

## TLDR

The Memory System provides persistent, semantically-searchable structured storage for PocketDev's AI. It uses PostgreSQL with pgvector for vector similarity search.

**Key concepts:**
- **Structures** = Schemas/templates (like classes)
- **Objects** = Instances (like objects)
- **Embeddings** = Vectors for semantic search
- **Relationships** = UUID references stored in object's JSONB `data` field

**Read level:** 📖 Full read recommended for implementation

---

## Requirements

- **PostgreSQL 17** with **pgvector 0.5+** (for HNSW index support)
  - PocketDev uses `pgvector/pgvector:pg17` Docker image which includes pgvector 0.8+
- **OpenAI API key** (or compatible embedding API) for generating embeddings
  - Model: `text-embedding-3-small` (1536 dimensions by default)
  - Configure via Settings UI

---

## Overview

The Memory System is a vector-based knowledge store that allows PocketDev's AI to persistently store, retrieve, and semantically search structured information. It combines PostgreSQL's relational database with pgvector's vector similarity search.

## The Problem We're Solving

AI assistants typically operate statelessly - each conversation starts fresh without knowledge of previous context. The Memory System addresses this by providing:

1. **Persistent Knowledge Storage** - Information survives across conversations
2. **Semantic Search** - Find relevant information by meaning, not just keywords
3. **User-Defined Schemas** - Flexible structure that adapts to different use cases
4. **Relationship Modeling** - Connect related pieces of information via ID references

## Use Cases

### Software Development
- **Projects**: Track project metadata, descriptions, and goals
- **Features/Deliverables**: Break projects into manageable chunks
- **Tasks**: Individual work items with status, owner references
- **Technical Decisions**: Document architectural choices and rationale

### World Building (D&D, Fiction)
- **Worlds**: Top-level containers for campaigns/stories
- **Locations**: Places with coordinates, descriptions, and location references
- **Characters**: People/creatures with stats, backstories, relationship references
- **Items**: Equipment, artifacts with owner/location references
- **Events**: Historical occurrences that shaped the world

### Personal Knowledge Management
- **Notes**: Free-form information with semantic searchability
- **Contacts**: People with relationship context
- **Ideas**: Concepts that can be linked and explored

---

## Architecture

### Database Schema

```text
┌─────────────────────────────────────────────────────────────┐
│  memory_structures                                           │
│  ├── id (UUID, PK)                                          │
│  ├── name, slug (unique identifier)                         │
│  ├── description (for AI system prompt)                     │
│  ├── schema (JSON Schema with x-embed markers)              │
│  └── icon, color (UI customization)                         │
├─────────────────────────────────────────────────────────────┤
│  memory_objects                                              │
│  ├── id (UUID, PK)                                          │
│  ├── structure_id, structure_slug (denormalized)            │
│  ├── name                                                   │
│  ├── data (JSONB - all fields including relationship IDs)  │
│  └── searchable_text (concatenated for full-text)           │
├─────────────────────────────────────────────────────────────┤
│  memory_embeddings                                           │
│  ├── id (UUID, PK)                                          │
│  ├── object_id (FK to memory_objects)                       │
│  ├── field_path ('description', 'history', etc.)            │
│  ├── content_hash (detect changes)                          │
│  └── embedding (vector(1536) with HNSW index)               │
└─────────────────────────────────────────────────────────────┘
```

### Modeling Relationships

**Relationships are stored as IDs in the data object**, not in a separate table. This simplifies the schema and makes queries more intuitive.

Example schema with relationships:
```json
{
  "type": "object",
  "properties": {
    "name": { "type": "string" },
    "owner_id": {
      "type": "string",
      "format": "uuid",
      "description": "UUID of the character who owns this item"
    },
    "location_id": {
      "type": "string",
      "format": "uuid",
      "description": "UUID of the location where this item is found"
    },
    "description": {
      "type": "string",
      "x-embed": true
    }
  }
}
```

Example object:
```json
{
  "name": "Flaming Sword",
  "owner_id": "550e8400-e29b-41d4-a716-446655440001",
  "location_id": null,
  "description": "A legendary blade wreathed in eternal flames"
}
```

Query relationships with JSONB operators:
```sql
-- Find all items owned by a character
SELECT * FROM memory_objects
WHERE structure_slug = 'item'
  AND data->>'owner_id' = '550e8400-e29b-41d4-a716-446655440001';

-- Find items at a location
SELECT * FROM memory_objects
WHERE structure_slug = 'item'
  AND data->>'location_id' = 'some-location-uuid';
```

### Key Design Decisions

#### Why pgvector Instead of a Separate Vector Database?

1. **Single Source of Truth** - No synchronization issues between Postgres and a vector store
2. **Transactional Integrity** - Updates to data and embeddings are atomic
3. **Hybrid Queries** - Combine SQL filters with vector similarity in one query
4. **Simpler Operations** - One database to backup, monitor, and maintain
5. **Sufficient Scale** - pgvector handles 10M+ vectors with proper indexing

#### Why JSONB Instead of Dynamic Tables?

1. **Security** - No DDL operations from AI input
2. **Flexibility** - Users can modify schemas without migrations
3. **Indexing** - GIN indexes on JSONB enable efficient queries
4. **Simplicity** - One table for all object types

#### Why Multiple Embeddings Per Object?

A location might have both a `description` and a `history`. Searching for "ancient magical library" should match the description, while "destroyed by the Cataclysm" should match the history. Separate embeddings allow targeted semantic search.

---

## How It Works

### 1. Define a Structure (Schema)

Structures are templates that define what fields an object type has. Use JSON Schema `description` fields to document each property:

```json
{
  "name": "Character",
  "slug": "character",
  "description": "A player character or NPC in the world",
  "schema": {
    "type": "object",
    "properties": {
      "class": {
        "type": "string",
        "description": "The character's class (fighter, wizard, rogue, etc.)"
      },
      "level": {
        "type": "integer",
        "description": "Character level (1-20)"
      },
      "backstory": {
        "type": "string",
        "description": "The character's background and history",
        "x-embed": true
      },
      "location_id": {
        "type": "string",
        "format": "uuid",
        "description": "UUID of the character's current location"
      },
      "ally_ids": {
        "type": "array",
        "items": { "type": "string", "format": "uuid" },
        "description": "UUIDs of allied characters"
      }
    },
    "required": ["class"]
  }
}
```

Key schema features:
- `x-embed: true` - Marks fields for vector embedding
- `description` - Documents what each field is for (shown to AI)
- `format: "uuid"` - Indicates this is a reference to another object

### 2. Create Objects

Objects are instances of a structure:

```json
{
  "structure": "character",
  "name": "Thorin Ironforge",
  "data": {
    "class": "fighter",
    "level": 5,
    "backstory": "A dwarven warrior who lost his clan to a dragon attack...",
    "location_id": "550e8400-e29b-41d4-a716-446655440000",
    "ally_ids": ["550e8400-e29b-41d4-a716-446655440001"]
  }
}
```

### 3. Embeddings Are Generated Automatically

When an object is created or updated, the system:
1. Extracts fields marked with `x-embed: true`
2. Generates vector embeddings using OpenAI's API
3. Stores embeddings with content hashes to avoid regeneration

### 4. Search Semantically

Find objects by meaning, not just keywords:

```sql
SELECT mo.id, mo.name, 1 - (me.embedding <=> :query_embedding) as similarity
FROM memory_objects mo
JOIN memory_embeddings me ON mo.id = me.object_id
WHERE mo.structure_slug = 'character'
  AND me.field_path = 'backstory'
  AND 1 - (me.embedding <=> :query_embedding) > 0.5
ORDER BY similarity DESC
LIMIT 10;
```

Query: "warriors who lost their families" would match Thorin even though those exact words don't appear.

---

## AI Tools

The AI has access to these tools for memory management:

### Structure Management

| Tool | Command | Purpose |
|------|---------|---------|
| Memory Structure Create | `memory:structure:create` | Create new schemas |
| Memory Structure Get | `memory:structure:get` | Retrieve a schema |
| Memory Structure Delete | `memory:structure:delete` | Remove a schema (no objects) |

### Object Management

| Tool | Command | Purpose |
|------|---------|---------|
| Memory Create | `memory:create` | Create new objects |
| Memory Query | `memory:query` | Read/search with SQL |
| Memory Update | `memory:update` | Modify existing objects |
| Memory Delete | `memory:delete` | Remove objects |

### Command Examples

**Create a structure:**
```bash
php artisan memory:structure:create \
  --name="Character" \
  --description="A player character or NPC" \
  --schema='{"type":"object","properties":{"class":{"type":"string","description":"Character class"},"backstory":{"type":"string","x-embed":true}}}'
```

**Create an object:**
```bash
php artisan memory:create \
  --structure=character \
  --name="Thorin Ironforge" \
  --data='{"class":"fighter","level":5,"location_id":"uuid-here"}'
```

**Query with semantic search:**
```bash
php artisan memory:query \
  --sql="SELECT mo.id, mo.name, 1 - (me.embedding <=> :search_embedding) as sim FROM memory_objects mo JOIN memory_embeddings me ON mo.id = me.object_id ORDER BY sim DESC LIMIT 5" \
  --search_text="ancient magical library"
```

**Update with text operations:**
```bash
# Append to a field
php artisan memory:update --id=<uuid> --field=backstory --append="\n\nNew chapter..."

# Replace text
php artisan memory:update --id=<uuid> --field=description --replace-text="old" --with="new"

# Insert after marker
php artisan memory:update --id=<uuid> --field=notes --insert-after="## Section A" --insert-text="\nContent"
```

### Query Examples

**List all structures:**
```sql
SELECT slug, name, description FROM memory_structures
```

**Find characters by class:**
```sql
SELECT id, name, data FROM memory_objects
WHERE structure_slug = 'character'
  AND data->>'class' = 'fighter'
```

**Find all items owned by a character:**
```sql
SELECT id, name, data FROM memory_objects
WHERE structure_slug = 'item'
  AND data->>'owner_id' = '<character-uuid>'
```

**Semantic search with similarity threshold:**
```sql
SELECT mo.id, mo.name, 1 - (me.embedding <=> :search_embedding) as similarity
FROM memory_objects mo
JOIN memory_embeddings me ON mo.id = me.object_id
WHERE mo.structure_slug = 'location'
  AND 1 - (me.embedding <=> :search_embedding) > 0.5
ORDER BY similarity DESC
LIMIT 10
```

---

## Configuration

### API Keys

API keys are managed via the PocketDev UI:

1. Go to **Config → Credentials**
2. Enter your **OpenAI API Key** (used for embeddings)
3. Save

The OpenAI key is stored securely in the database and used by the EmbeddingService.

### Config File (config/ai.php)

```php
'embeddings' => [
    // API key managed via UI (uses OpenAI key from database)
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    'model' => 'text-embedding-3-small',
    'dimensions' => 1536,
],
```

---

## File Structure

```text
app/
├── Models/
│   ├── MemoryStructure.php    # Schema definitions
│   ├── MemoryObject.php       # All entities
│   └── MemoryEmbedding.php    # Vector embeddings
├── Services/
│   └── EmbeddingService.php   # OpenAI embeddings API
├── Tools/
│   ├── MemoryQueryTool.php    # SQL queries
│   ├── MemoryCreateTool.php   # Create objects
│   ├── MemoryUpdateTool.php   # Update objects (with text ops)
│   └── MemoryDeleteTool.php   # Delete objects
├── Console/Commands/
│   ├── MemoryStructureCreateCommand.php
│   ├── MemoryStructureGetCommand.php
│   ├── MemoryStructureDeleteCommand.php
│   ├── MemoryCreateCommand.php
│   ├── MemoryQueryCommand.php
│   ├── MemoryUpdateCommand.php
│   └── MemoryDeleteCommand.php
└── Providers/
    └── AIServiceProvider.php  # Tool registration

database/migrations/
└── See database/migrations/ for complete list of memory-related migrations

config/
└── ai.php                     # Embeddings configuration
```

---

## Schema Design Best Practices

### 1. Use Description Fields

Every property should have a `description` that explains its purpose:

```json
{
  "properties": {
    "hp": {
      "type": "integer",
      "description": "Current hit points (health remaining)"
    }
  }
}
```

### 2. Mark Embeddable Fields

Add `x-embed: true` to text fields that should be semantically searchable:

```json
{
  "properties": {
    "backstory": {
      "type": "string",
      "x-embed": true,
      "description": "Character's background story"
    }
  }
}
```

### 3. Use Format for Relationships

Use `format: "uuid"` for ID references:

```json
{
  "properties": {
    "owner_id": {
      "type": "string",
      "format": "uuid",
      "description": "UUID of the owning character"
    },
    "member_ids": {
      "type": "array",
      "items": { "type": "string", "format": "uuid" },
      "description": "UUIDs of party members"
    }
  }
}
```

### 4. Document Relationship Semantics

Name relationship fields clearly with `_id` suffix:

- `owner_id` - Who owns this
- `location_id` - Where this is located
- `creator_id` - Who created this
- `target_id` - What this affects
- `member_ids` - Array of members

---

## Security Considerations

- **SQL Injection Prevention**: MemoryQuery only allows SELECT statements and blocks dangerous patterns
- **No Dynamic DDL**: Structures use JSONB, not dynamic table creation
- **Input Validation**: Objects are validated against their structure's JSON Schema
- **Embedding Safety**: Content is hashed before embedding to detect tampering

---

## Future Enhancements

### Planned Features

- **Batch Operations** - Create/update multiple objects at once
- **Schema Versioning** - Track changes to structure definitions
- **Import/Export** - JSON export for backup and sharing
- **Computed Fields** - Auto-populate fields based on queries
- **Audit Trail** - Track who changed what and when
- **Auto-Generated System Prompt** - Inject available structures into AI context

---

## Getting Started

1. Ensure PostgreSQL is running with pgvector extension
2. Run migrations: `php artisan migrate`
3. Run seeders: `php artisan db:seed`
4. Add your OpenAI API key via **Config → Credentials** in the UI
5. Create a structure using the AI or artisan command
6. Start creating objects and searching semantically
