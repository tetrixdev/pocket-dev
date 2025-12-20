# Memory System Architecture

## Overview

The Memory System is a vector-based knowledge store that allows PocketDev's AI to persistently store, retrieve, and semantically search structured information. It combines the power of PostgreSQL's relational database with pgvector's vector similarity search to create a flexible, schema-driven memory architecture.

## The Problem We're Solving

AI assistants typically operate statelessly - each conversation starts fresh without knowledge of previous context. The Memory System addresses this by providing:

1. **Persistent Knowledge Storage** - Information survives across conversations
2. **Semantic Search** - Find relevant information by meaning, not just keywords
3. **User-Defined Schemas** - Flexible structure that adapts to different use cases
4. **Relationship Modeling** - Connect related pieces of information

## Use Cases

### Software Development
- **Projects**: Track project metadata, descriptions, and goals
- **Features/Deliverables**: Break projects into manageable chunks
- **Tasks**: Individual work items with status and assignments
- **Technical Decisions**: Document architectural choices and rationale

### World Building (D&D, Fiction)
- **Worlds**: Top-level containers for campaigns/stories
- **Locations**: Places with coordinates, descriptions, and connections
- **Characters**: People/creatures with stats, backstories, relationships
- **Items**: Equipment, artifacts, treasures with properties
- **Events**: Historical occurrences that shaped the world

### Personal Knowledge Management
- **Notes**: Free-form information with semantic searchability
- **Contacts**: People with relationship context
- **Ideas**: Concepts that can be linked and explored

## Architecture

### Database Schema

```
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
│  ├── data (JSONB - flexible fields)                         │
│  ├── searchable_text (concatenated for full-text)           │
│  └── parent_id (self-ref for hierarchy)                     │
├─────────────────────────────────────────────────────────────┤
│  memory_embeddings                                           │
│  ├── id (UUID, PK)                                          │
│  ├── object_id (FK to memory_objects)                       │
│  ├── field_path ('description', 'history', etc.)            │
│  ├── content_hash (detect changes)                          │
│  └── embedding (vector(1536) with HNSW index)               │
├─────────────────────────────────────────────────────────────┤
│  memory_relationships                                        │
│  ├── id (UUID, PK)                                          │
│  ├── source_id, target_id (FKs to memory_objects)           │
│  └── relationship_type ('owns', 'knows', 'located_in')      │
└─────────────────────────────────────────────────────────────┘
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

## How It Works

### 1. Define a Structure (Schema)

Structures are templates that define what fields an object type has:

```json
{
  "name": "Location",
  "slug": "location",
  "description": "A place in the world with geographic coordinates",
  "schema": {
    "type": "object",
    "properties": {
      "name": { "type": "string" },
      "description": {
        "type": "string",
        "format": "rich-text",
        "x-embed": true
      },
      "history": {
        "type": "string",
        "format": "rich-text",
        "x-embed": true
      },
      "coordinates": {
        "type": "object",
        "format": "coordinates",
        "properties": {
          "x": { "type": "number" },
          "y": { "type": "number" }
        }
      },
      "terrain": {
        "type": "string",
        "enum": ["forest", "mountain", "plains", "water", "urban"]
      }
    },
    "required": ["name"]
  }
}
```

The `x-embed: true` marker tells the system to generate vector embeddings for that field.

### 2. Create Objects

Objects are instances of a structure:

```json
{
  "structure": "location",
  "name": "The Sunken Library",
  "data": {
    "description": "An ancient library half-submerged in the murky swamp waters. Bookshelves rise from the muck, their contents miraculously preserved by arcane wards.",
    "history": "Built over a thousand years ago by the elven sage Aelindra, the library served as the greatest repository of magical knowledge in the realm. It sank beneath the earth during the Cataclysm of the Third Age.",
    "terrain": "water",
    "coordinates": { "x": 150, "y": 320 }
  }
}
```

### 3. Embeddings Are Generated Automatically

When an object is created or updated, the system:
1. Extracts fields marked with `x-embed: true`
2. Generates vector embeddings using OpenAI's API
3. Stores embeddings with content hashes to avoid regeneration

### 4. Search Semantically

Find locations by meaning, not just keywords:

```sql
SELECT mo.id, mo.name, 1 - (me.embedding <=> :query_embedding) as similarity
FROM memory_objects mo
JOIN memory_embeddings me ON mo.id = me.object_id
WHERE mo.structure_slug = 'location'
  AND me.field_path = 'description'
  AND 1 - (me.embedding <=> :query_embedding) > 0.5
ORDER BY similarity DESC
LIMIT 10;
```

Query: "places with forbidden knowledge" would match "The Sunken Library" even though those exact words don't appear.

### 5. Create Relationships

Link objects together:

```
Character --[located_in]--> Location
Character --[owns]--> Item
Character --[knows]--> Character
Location --[contains]--> Location
```

## AI Tools

The AI has access to 6 tools for memory management:

| Tool | Purpose |
|------|---------|
| `MemoryQuery` | Read/search with raw SQL (SELECT only) |
| `MemoryCreate` | Create new objects |
| `MemoryUpdate` | Modify existing objects |
| `MemoryDelete` | Remove objects |
| `MemoryLink` | Create relationships |
| `MemoryUnlink` | Remove relationships |

### MemoryQuery Examples

**List all structures:**
```sql
SELECT id, name, slug, description FROM memory_structures
```

**Find characters by class:**
```sql
SELECT * FROM memory_objects
WHERE structure_slug = 'character'
  AND data @> '{"class": "fighter"}'
```

**Semantic search with similarity threshold:**
```sql
SELECT mo.id, mo.name, 1 - (me.embedding <=> :search_embedding) as similarity
FROM memory_objects mo
JOIN memory_embeddings me ON mo.id = me.object_id
WHERE mo.structure_slug = 'location'
  AND 1 - (me.embedding <=> :search_embedding) > 0.5
ORDER BY similarity DESC
```

## Configuration

### Environment Variables

```bash
# Required for embeddings
OPENAI_API_KEY=sk-...

# Optional customization
EMBEDDING_MODEL=text-embedding-3-small  # or text-embedding-3-large
EMBEDDING_DIMENSIONS=1536                # 1536 for small, 3072 for large
```

### Config File (config/ai.php)

```php
'embeddings' => [
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    'model' => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1536),
],
```

## File Structure

```
app/
├── Models/
│   ├── MemoryStructure.php    # Schema definitions
│   ├── MemoryObject.php       # All entities
│   ├── MemoryEmbedding.php    # Vector embeddings
│   └── MemoryRelationship.php # Links between objects
├── Services/
│   └── EmbeddingService.php   # OpenAI embeddings API
├── Tools/
│   ├── MemoryQueryTool.php    # SQL queries
│   ├── MemoryCreateTool.php   # Create objects
│   ├── MemoryUpdateTool.php   # Update objects
│   ├── MemoryDeleteTool.php   # Delete objects
│   ├── MemoryLinkTool.php     # Create relationships
│   └── MemoryUnlinkTool.php   # Remove relationships
└── Providers/
    └── AIServiceProvider.php  # Tool registration

database/migrations/
├── 2025_12_19_000001_create_memory_structures_table.php
├── 2025_12_19_000002_create_memory_objects_table.php
├── 2025_12_19_000003_create_memory_embeddings_table.php
└── 2025_12_19_000004_create_memory_relationships_table.php

config/
└── ai.php                     # Embeddings configuration
```

## Future Enhancements

### TODO: Structured Query Language (Option C)

Currently, the AI writes raw SQL for queries. A safer alternative would be a structured query object:

```json
{
  "from": "character",
  "where": {
    "data.class": "fighter",
    "data.level": { ">": 5 }
  },
  "search": {
    "field": "backstory",
    "query": "tragic past",
    "min_similarity": 0.5
  },
  "limit": 10
}
```

This would be translated to SQL server-side with automatic safety constraints.

### Planned Features

- **Batch Operations** - Create/update multiple objects at once
- **Schema Versioning** - Track changes to structure definitions
- **Import/Export** - JSON export for backup and sharing
- **Relationship Constraints** - Define which structures can link to which
- **Computed Fields** - Auto-populate fields based on relationships
- **Audit Trail** - Track who changed what and when

## Getting Started

1. Ensure PostgreSQL is running with pgvector extension
2. Run migrations: `php artisan migrate`
3. Add your OpenAI API key to `.env`
4. Create a structure using the AI or directly via tinker
5. Start creating objects and searching semantically

## Security Considerations

- **SQL Injection Prevention**: MemoryQuery only allows SELECT statements and blocks dangerous patterns
- **No Dynamic DDL**: Structures use JSONB, not dynamic table creation
- **Input Validation**: Objects are validated against their structure's JSON Schema
- **Embedding Safety**: Content is hashed before embedding to detect tampering
