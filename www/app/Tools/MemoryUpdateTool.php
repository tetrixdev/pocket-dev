<?php

namespace App\Tools;

use App\Services\MemoryDataService;

/**
 * Update rows in a memory table with auto-embedding regeneration.
 */
class MemoryUpdateTool extends Tool
{
    public string $name = 'MemoryUpdate';

    public string $description = 'Update rows in a memory table. Automatically regenerates embeddings for changed fields.';

    public string $category = 'memory_data';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'schema' => [
                'type' => 'string',
                'description' => 'Memory schema short name (e.g., "default", "my_campaign"). Required - check your available schemas in the system prompt.',
            ],
            'table' => [
                'type' => 'string',
                'description' => 'Table name (without schema prefix).',
            ],
            'data' => [
                'type' => 'string',
                'description' => 'JSON object with column => new value pairs to update.',
            ],
            'where' => [
                'type' => 'string',
                'description' => 'WHERE clause (without WHERE keyword). Required. Example: "id = \'uuid-here\'"',
            ],
        ],
        'required' => ['schema', 'table', 'data', 'where'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:update';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryUpdate to modify existing rows. Embeddings are automatically regenerated for any changed fields that are configured for embedding.

## CRITICAL: Read Before Write

**You MUST read the existing row before updating ANY text field to avoid data loss.**

Updates REPLACE field values, they do not append. If you update a field without reading it first, you will lose the existing content.

**Rule of thumb:** Always query the row first. Only numeric/boolean fields (hp_current, danger_level, is_alive) can be set directly without reading.

**Correct pattern:**
```bash
# 1. READ the current value
php artisan memory:query --sql="SELECT backstory, notes FROM memory.characters WHERE id = 'uuid'" --limit=1

# 2. THEN update, preserving and appending to existing content
php artisan memory:update --table=characters --data='{"backstory":"[preserved original content] + [new content]"}' --where="id = 'uuid'"
```

**Wrong pattern (causes data loss):**
```bash
# DON'T do this - you'll overwrite the existing backstory
php artisan memory:update --table=characters --data='{"backstory":"New content only"}' --where="id = 'uuid'"
```

**Fields that commonly need read-before-write:**
- story_arcs: hooks, current_status, secrets_revealed
- entities: notes, backstory, current_goals
- Any text field where content accumulates over time

**Note:** For relationship tracking, prefer using the append-only `relationship_events` table instead of updating `entity_relationships`. See dm_system_prompt.md for the pattern.

## WHERE Clause

The WHERE clause is **required** to prevent accidental updates of all rows:

```bash
# Update by ID
--where="id = 'uuid-here'"

# Update by name
--where="name = 'Thorin'"

# Update multiple matching rows
--where="class = 'wizard'"
```

## Auto-Re-Embedding

When you update embeddable fields:
1. The update tool detects which fields are changing
2. Checks if those fields are in the table's `embed_fields`
3. Regenerates embeddings only for changed embeddable fields
4. Updates the hash to track the new content

## Notes

- WHERE clause is required (no bulk updates without filter)
- Only specified columns are updated (others remain unchanged)
- Returns count of affected rows
- Embeddings only regenerated for fields that actually changed
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
php artisan memory:update --schema=default --table=characters --data='{"backstory":"Updated backstory with new details..."}' --where="id = '123e4567-e89b-12d3-a456-426614174000'"
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "table": "characters",
  "data": {
    "backstory": "After the Battle of Five Armies, Thorin sacrificed himself to save his companions. His legacy lives on through his nephew Fili who now rules Erebor."
  },
  "where": "name = 'Thorin Ironforge'"
}
```

The backstory embedding will be regenerated automatically if `backstory` is an embed field.
API;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $schemaName = trim($input['schema'] ?? '');
        $table = trim($input['table'] ?? '');
        $dataJson = $input['data'] ?? '';
        $where = trim($input['where'] ?? '');

        // Validate schema parameter
        if (empty($schemaName)) {
            return ToolResult::error('schema is required. Specify the short schema name (e.g., "default", "my_campaign"). Check your available schemas in the system prompt.');
        }

        // Validate schema exists
        $memoryDb = \App\Models\MemoryDatabase::where('schema_name', $schemaName)->first();
        if (!$memoryDb) {
            return ToolResult::error("Schema '{$schemaName}' not found. Check your available schemas in the system prompt.");
        }

        // Validate agent has access to this schema (if agent context available)
        if ($context->agent && !$context->agent->hasMemoryDatabaseAccess($memoryDb)) {
            return ToolResult::error("Agent does not have access to schema '{$schemaName}'. Enable it in agent settings.");
        }

        if (empty($table)) {
            return ToolResult::error('table is required');
        }

        if (empty($dataJson)) {
            return ToolResult::error('data is required');
        }

        if (empty($where)) {
            return ToolResult::error('where clause is required');
        }

        $data = json_decode($dataJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ToolResult::error('data must be valid JSON: ' . json_last_error_msg());
        }

        if (empty($data) || !is_array($data)) {
            return ToolResult::error('data must be a non-empty JSON object');
        }

        $service = app(MemoryDataService::class);
        $service->setMemoryDatabase($memoryDb);
        $result = $service->update($table, $data, $where);

        if ($result['success']) {
            $output = [$result['message']];

            if (isset($result['embedded_rows']) && $result['embedded_rows'] > 0) {
                $output[] = "Re-embedded {$result['embedded_rows']} row(s)";
            }

            return ToolResult::success(implode("\n", $output));
        }

        return ToolResult::error($result['message']);
    }
}
