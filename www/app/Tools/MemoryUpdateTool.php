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
        'required' => ['table', 'data', 'where'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:update';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryUpdate to modify existing rows. Embeddings are automatically regenerated for any changed fields that are configured for embedding.

## CLI Example

```bash
php artisan memory:update --table=characters --data='{"backstory":"Updated backstory with new details..."}' --where="id = '123e4567-e89b-12d3-a456-426614174000'"
```

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

## Example: Update character backstory

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

## Notes

- WHERE clause is required (no bulk updates without filter)
- Only specified columns are updated (others remain unchanged)
- Returns count of affected rows
- Embeddings only regenerated for fields that actually changed
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $table = trim($input['table'] ?? '');
        $dataJson = $input['data'] ?? '';
        $where = trim($input['where'] ?? '');

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
