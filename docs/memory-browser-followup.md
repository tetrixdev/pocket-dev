# Memory Browser UI - Follow-up Feature

## Overview

After the Memory System V2 migration is complete, add a visual browser for exploring memory tables and data directly in the PocketDev UI.

## Proposed Location

`/config/memory/browse` or expand `/config/memory` with tabs:
- **Settings** (current plan) - snapshots, export/import, retention
- **Browse** (this feature) - visual data explorer

## Features

### 1. Table List View
- List all tables in `memory` schema
- Show row count, storage size
- Show description from `schema_registry`
- Click to view table contents

### 2. Table Detail View
- Paginated data grid (50 rows per page)
- Column headers with types and descriptions
- Sortable columns
- Embeddable fields highlighted

### 3. Search Within Table
- Text search across all columns
- Filter by specific column
- Fuzzy search using pg_trgm (if indexed)

### 4. Row Detail View
- Click row to see full details
- Show all fields with formatted values
- Show embeddings for this row:
  - Field name
  - Content preview
  - Embedding vector (truncated)
  - Created/updated timestamps

### 5. Related Rows
- Detect UUID fields that reference other tables
- Show links to related rows
- Example: Character row shows link to Location row via `location_id`

### 6. Semantic Search
- Search box with "Semantic Search" toggle
- When enabled, searches via embeddings
- Shows similarity scores
- Can filter by field (backstory, description, etc.)

## UI Mockup

```
┌─────────────────────────────────────────────────────────────────┐
│ Memory Browser                                                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│ Tables                          │ characters (47 rows)          │
│ ┌─────────────────────────────┐ │ ┌─────────────────────────────│
│ │ ▶ characters (47)           │ │ │ Search: [__________] [🔍]   │
│ │   locations (23)            │ │ │ □ Semantic search           │
│ │   items (156)               │ │ │                             │
│ │   relationships (89)        │ │ │ ┌────┬──────────┬─────────┐ │
│ └─────────────────────────────┘ │ │ │ ID │ Name     │ Class   │ │
│                                 │ │ ├────┼──────────┼─────────┤ │
│ Description:                    │ │ │ a1 │ Thorin   │ fighter │ │
│ Player and NPC characters       │ │ │ b2 │ Gandalf  │ wizard  │ │
│                                 │ │ │ c3 │ Bilbo    │ rogue   │ │
│ Embeddable: backstory           │ │ └────┴──────────┴─────────┘ │
│                                 │ │                             │
│                                 │ │ Page 1 of 2  [<] [>]        │
└─────────────────────────────────┴─┴─────────────────────────────┘
```

## Technical Implementation

### Routes
```php
Route::get('/config/memory/browse', [MemoryBrowserController::class, 'index']);
Route::get('/config/memory/browse/{table}', [MemoryBrowserController::class, 'showTable']);
Route::get('/config/memory/browse/{table}/{id}', [MemoryBrowserController::class, 'showRow']);
Route::get('/config/memory/browse/{table}/search', [MemoryBrowserController::class, 'search']);
```

### Controller
```php
class MemoryBrowserController extends Controller
{
    public function index()
    {
        // List tables from information_schema + schema_registry
    }

    public function showTable(string $table, Request $request)
    {
        // Paginated query with optional sorting
    }

    public function showRow(string $table, string $id)
    {
        // Single row + embeddings + related rows
    }

    public function search(string $table, Request $request)
    {
        // Text search or semantic search
    }
}
```

### Security
- Read-only (no edit/delete from browser)
- Uses `memory_readonly` connection
- Table name validation to prevent injection

## Priority

**Low** - Nice to have after core V2 functionality is working.

## Dependencies

- Memory System V2 complete
- `schema_registry` populated with table metadata
- `memory.embeddings` populated for semantic search
