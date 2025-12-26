<?php

namespace App\Tools;

use App\Services\MemoryDataService;

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
            'table' => [
                'type' => 'string',
                'description' => 'Table name (without schema prefix).',
            ],
            'where' => [
                'type' => 'string',
                'description' => 'WHERE clause (without WHERE keyword). Required. Example: "id = \'uuid-here\'"',
            ],
        ],
        'required' => ['table', 'where'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:delete';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryDelete to remove rows from memory tables. Associated embeddings are automatically deleted.

## CLI Example

```bash
php artisan memory:delete --table=characters --where="id = '123e4567-e89b-12d3-a456-426614174000'"
```

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
- Cannot delete from protected tables (embeddings, schema_registry)
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $table = trim($input['table'] ?? '');
        $where = trim($input['where'] ?? '');

        if (empty($table)) {
            return ToolResult::error('table is required');
        }

        if (empty($where)) {
            return ToolResult::error('where clause is required');
        }

        // Prevent deletion from protected tables
        if (in_array($table, ['embeddings', 'schema_registry'])) {
            return ToolResult::error("Cannot delete from protected table: {$table}");
        }

        $service = app(MemoryDataService::class);
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
