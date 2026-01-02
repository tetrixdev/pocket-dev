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

## Why This Matters: AI-Consumable Documentation

Memory tables are NOT traditional database tables with separate documentation. The table description and column descriptions ARE the documentation - they get injected directly into the AI's system prompt.

When a future AI uses this table, it will ONLY see:
1. The table description you write here
2. Column names, types, and descriptions
3. Which fields are auto-embedded (added automatically)

A well-documented table enables AI to use it correctly; a poorly-documented table leads to misuse and data inconsistency.

## Important Rules

1. **Table name**: Must start with letter, lowercase only, underscores allowed
2. **SQL must use memory. prefix**: `CREATE TABLE memory.tablename (...)`
3. **Always include id column**: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
4. **embed_fields is required**: List text fields to auto-embed, or empty string for none
5. **Column descriptions are strongly recommended**: See guidance below

## Table Description Format

The description should enable any AI to use this table correctly:

```
[1-2 sentence purpose: What data is stored and why]
**Typical queries:** [Common access patterns]
**Relationships:** [Foreign keys and table connections]
**Example:** php artisan memory:insert --table=X --data='{...}'
```

**Do NOT include `**Auto-embed:**`** - this is automatically added from embed_fields.

**Note:** Read-before-write guidance is handled by the MemoryUpdate tool. You don't need to specify which fields require reading first - the update tool instructs the AI to always read text fields before updating.

## Column Description Guidelines

**Length guidance:**
- **Simple fields** (name, status): 2-10 words
- **Complex text fields** (backstory, notes): 1-3 sentences explaining structure
- **Structured fields**: Detailed format guidance

**Always describe:**
- Text fields that will be embedded (these need the most guidance)
- Fields with specific formats (dates, enums, JSON structure)

## Supported Column Types

- TEXT, VARCHAR(n) - text fields (can be embedded)
- INTEGER, BIGINT, SMALLINT - whole numbers
- NUMERIC, DECIMAL, REAL, DOUBLE PRECISION - decimals
- BOOLEAN - true/false
- UUID - unique identifiers
- TIMESTAMP, DATE, TIME - temporal
- JSONB - structured data
- TEXT[], UUID[] - arrays
- GEOGRAPHY(Point, 4326) - PostGIS coordinates

## Fuzzy Search Index (pg_trgm)

After creating the table, add a trigram index for fuzzy text search:
```sql
CREATE INDEX idx_tablename_name_trgm ON memory.tablename USING GIN (name gin_trgm_ops);
```
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
php artisan memory:schema:create-table \
    --name=tasks \
    --description="Individual tasks belonging to projects.
**Typical queries:** Get tasks by project, find overdue tasks
**Relationships:** project_id references projects(id)
**Example:** php artisan memory:insert --table=tasks --data='{\"title\":\"...\", \"status\":\"todo\"}'" \
    --embed-fields="title,description" \
    --column-descriptions='{"title":"Brief task name (5-10 words)","description":"Detailed requirements and context","status":"todo, in_progress, review, done, or blocked","priority":"low, medium, high, or critical"}' \
    --sql="CREATE TABLE memory.tasks (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        title TEXT NOT NULL,
        description TEXT,
        status TEXT DEFAULT 'todo',
        priority TEXT DEFAULT 'medium',
        project_id UUID,
        created_at TIMESTAMP DEFAULT NOW()
    )"
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "name": "tasks",
  "description": "Individual tasks belonging to projects.\n**Typical queries:** Get tasks by project\n**Relationships:** project_id references projects(id)\n**Example:** ...",
  "embed_fields": "title,description",
  "column_descriptions": "{\"title\":\"Brief task name\",\"status\":\"todo, in_progress, done\"}",
  "sql": "CREATE TABLE memory.tasks (id UUID PRIMARY KEY DEFAULT gen_random_uuid(), title TEXT NOT NULL, status TEXT DEFAULT 'todo', created_at TIMESTAMP DEFAULT NOW())"
}
```
API;

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
