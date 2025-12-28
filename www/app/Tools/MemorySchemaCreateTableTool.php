<?php

namespace App\Tools;

use App\Services\MemorySchemaService;

/**
 * Create a table in the memory schema with auto-embedding configuration.
 */
class MemorySchemaCreateTableTool extends Tool
{
    public string $name = 'MemorySchemaCreateTable';

    public string $description = 'Create a table in the memory schema with embedded field configuration.';

    public string $category = 'memory_schema';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Table name (without schema prefix). Must start with letter, contain only lowercase letters, numbers, underscores.',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Human-readable description of what this table stores.',
            ],
            'sql' => [
                'type' => 'string',
                'description' => 'CREATE TABLE SQL statement. Must include memory. schema prefix (e.g., CREATE TABLE memory.characters ...).',
            ],
            'embed_fields' => [
                'type' => 'string',
                'description' => 'Comma-separated list of text fields to auto-embed on insert/update. Required but can be empty string if no embedding needed.',
            ],
            'column_descriptions' => [
                'type' => 'string',
                'description' => 'JSON object mapping column names to their descriptions. These become COMMENT ON COLUMN for documentation.',
            ],
        ],
        'required' => ['name', 'description', 'sql', 'embed_fields'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:schema:create-table';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemorySchemaCreateTable to create new tables in the memory schema.

## CLI Example

```bash
php artisan memory:schema:create-table \
    --name=characters \
    --description="Player and NPC characters" \
    --embed-fields="backstory,description" \
    --column-descriptions='{"name":"Full character name","backstory":"Character history and motivations"}' \
    --sql="CREATE TABLE memory.characters (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        name TEXT NOT NULL,
        class TEXT,
        backstory TEXT,
        description TEXT,
        created_at TIMESTAMP DEFAULT NOW()
    )"
```

## Important Rules

1. **Table name**: Must start with letter, lowercase only, underscores allowed
2. **SQL must use memory. prefix**: `CREATE TABLE memory.tablename (...)`
3. **Always include id column**: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
4. **embed_fields is required**: List text fields to auto-embed, or empty string for none
5. **Column descriptions are optional but recommended**: Helps AI understand the schema

## Table Description Requirements (6 Mandatory Elements)

The `--description` parameter must include these 6 elements for complete documentation:

1. **What data is stored** - Primary purpose of the table
2. **Which fields are auto-embedded** - List the embed_fields and what they're used for
3. **What queries are typical** - Common access patterns
4. **Relationships to other tables** - Foreign keys and join patterns
5. **Append vs. Replace guidelines** - Which fields accumulate content vs. get replaced
6. **Example insert** - A sample insert command

**Example description:**
```
Tracks relationships between entities (NPCs, PCs, creatures). Store both directions in a single row.
**Auto-embed:** notes, relationship_from_entity, relationship_from_related
**Typical queries:** Find all relationships for an entity, find relationship between two specific entities
**Relationships:** entity_id and related_entity_id reference memory.entities(id)
**Append fields:** relationship_from_entity, relationship_from_related, notes - ALWAYS read before update
**Replace fields:** relationship_type, status
**Example:** php artisan memory:insert --table=entity_relationships --data='{"entity_id":"...", "related_entity_id":"...", "relationship_type":"ally"}'
```

## Supported Column Types

- TEXT, VARCHAR(n) - text fields (can be embedded)
- INTEGER, BIGINT, SMALLINT - whole numbers
- NUMERIC, DECIMAL, REAL, DOUBLE PRECISION - decimals
- BOOLEAN - true/false
- UUID - unique identifiers
- TIMESTAMP, DATE, TIME - temporal
- JSONB - structured data
- TEXT[] - text arrays
- GEOGRAPHY(Point, 4326) - PostGIS coordinates

## PostGIS Example (Coordinates)

```sql
CREATE TABLE memory.locations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT NOT NULL,
    description TEXT,
    coordinates GEOGRAPHY(Point, 4326),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_locations_geo ON memory.locations USING GIST (coordinates);
```

## Fuzzy Search Index (pg_trgm)

After creating the table, add a trigram index for fuzzy text search:
```sql
CREATE INDEX idx_tablename_name_trgm ON memory.tablename USING GIN (name gin_trgm_ops);
```
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $sql = trim($input['sql'] ?? '');
        $embedFieldsStr = $input['embed_fields'] ?? '';
        $columnDescriptionsJson = $input['column_descriptions'] ?? '{}';

        if (empty($name)) {
            return ToolResult::error('name is required');
        }

        if (empty($description)) {
            return ToolResult::error('description is required');
        }

        if (empty($sql)) {
            return ToolResult::error('sql is required');
        }

        // Parse embed_fields
        $embedFields = [];
        if (!empty($embedFieldsStr)) {
            $embedFields = array_map('trim', explode(',', $embedFieldsStr));
            $embedFields = array_filter($embedFields);
        }

        // Parse column_descriptions
        $columnDescriptions = [];
        if (!empty($columnDescriptionsJson)) {
            $decoded = json_decode($columnDescriptionsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ToolResult::error('column_descriptions must be valid JSON: ' . json_last_error_msg());
            }
            $columnDescriptions = $decoded;
        }

        $service = app(MemorySchemaService::class);
        $result = $service->createTable($name, $sql, $description, $embedFields, $columnDescriptions);

        if ($result['success']) {
            $output = $result['message'];
            if (!empty($embedFields)) {
                $output .= "\nAuto-embed fields: " . implode(', ', $embedFields);
            }
            return ToolResult::success($output);
        }

        return ToolResult::error($result['message']);
    }
}
