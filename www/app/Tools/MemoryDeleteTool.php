<?php

namespace App\Tools;

use App\Services\MemoryDataService;
use App\Services\MemorySchemaService;

/**
 * Delete rows from a memory table and their associated embeddings.
 */
class MemoryDeleteTool extends Tool
{
    public string $name = 'MemoryDelete';

    public string $description = 'Delete rows from a memory table and their associated embeddings.';

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
            'where' => [
                'type' => 'string',
                'description' => 'WHERE clause (without WHERE keyword). Required. Example: "id = \'uuid-here\'"',
            ],
        ],
        'required' => ['schema', 'table', 'where'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:delete';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryDelete to remove rows from memory tables. Associated embeddings are automatically deleted.

## WHERE Clause

The WHERE clause is **required** to prevent accidental deletion of all rows:

```bash
# Delete by ID (most common)
--where="id = 'uuid-here'"

# Delete by name
--where="name = 'Old Character'"

# Delete multiple matching rows
--where="class = 'deprecated'"
```

## Cascading Deletion

When you delete rows:
1. The delete tool finds all matching row IDs
2. Deletes associated embeddings from memory.embeddings
3. Deletes the rows themselves
4. Returns counts of deleted rows and embeddings

## Example: Delete a character

```json
{
  "table": "characters",
  "where": "name = 'Thorin Ironforge'"
}
```

## Notes

- WHERE clause is required (use DROP TABLE for clearing all data)
- Embeddings are deleted first, then rows
- Returns count of deleted rows and embeddings
- Cannot delete from protected tables (embeddings)

## schema_registry Deletion

Deleting from schema_registry has special validation to prevent metadata corruption:
- Only `table_name = 'tablename'` format is allowed
- The table must NOT exist (only orphaned entries can be deleted)
- Use this to clean up registry entries after dropping a table
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
pd memory:delete --schema=default --table=characters --where="id = '123e4567-e89b-12d3-a456-426614174000'"
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

```json
{
  "table": "characters",
  "where": "name = 'Thorin Ironforge'"
}
```
API;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $schemaName = trim($input['schema'] ?? '');
        $table = trim($input['table'] ?? '');
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

        if (empty($where)) {
            return ToolResult::error('where clause is required');
        }

        // Prevent deletion from protected tables
        if ($table === 'embeddings') {
            return ToolResult::error("Cannot delete from protected table: {$table}");
        }

        // Special validation for schema_registry to prevent metadata corruption
        // Only allow deletion of entries for tables that no longer exist
        if ($table === 'schema_registry') {
            // Only allow simple equality on table_name column
            if (!preg_match('/^table_name\s*=\s*[\'"]([a-z_][a-z0-9_]*)[\'"]$/i', $where, $matches)) {
                return ToolResult::error(
                    "schema_registry deletions must use simple format: table_name = 'tablename'. " .
                    "This prevents accidental deletion of active table metadata."
                );
            }

            $targetTable = $matches[1];
            $schemaService = app(MemorySchemaService::class);
            $schemaService->setMemoryDatabase($memoryDb);

            // Verify the table doesn't actually exist
            if ($schemaService->tableExists($targetTable)) {
                return ToolResult::error(
                    "Cannot delete schema_registry entry for existing table: {$targetTable}. " .
                    "Only orphaned entries (where the table no longer exists) can be deleted."
                );
            }
        }

        $service = app(MemoryDataService::class);
        $service->setMemoryDatabase($memoryDb);
        $result = $service->delete($table, $where);

        if ($result['success']) {
            $output = [$result['message']];

            if ($result['deleted_embeddings'] > 0) {
                $output[] = "Deleted {$result['deleted_embeddings']} embedding(s)";
            }

            return ToolResult::success(implode("\n", $output));
        }

        return ToolResult::error($result['message']);
    }
}
