<?php

namespace App\Tools;

use App\Services\MemoryDataService;

/**
 * Insert a row into a memory table with auto-embedding.
 */
class MemoryInsertTool extends Tool
{
    public string $name = 'MemoryInsert';

    public string $description = 'Insert a row into a memory table. Automatically generates embeddings for configured fields.';

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
                'description' => 'JSON object with column => value pairs to insert.',
            ],
        ],
        'required' => ['table', 'data'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:insert';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryInsert to add rows to memory tables. Embeddings are automatically generated based on the table's configured embed_fields.

## CLI Example

```bash
php artisan memory:insert --table=characters --data='{"name":"Thorin Ironforge","class":"fighter","backstory":"A dwarf warrior seeking revenge for his fallen clan."}'
```

## Auto-Embedding

When you insert data, fields configured in the table's `embed_fields` are automatically embedded:
1. The insert tool looks up schema_registry for the table
2. Finds which fields should be embedded
3. Generates embeddings via OpenAI API
4. Stores embeddings in memory.embeddings table

You don't need to manually generate embeddings!

## Example: Character with backstory

```json
{
  "table": "characters",
  "data": {
    "name": "Gandalf the Grey",
    "class": "wizard",
    "backstory": "A Maia spirit sent to Middle-earth to guide the free peoples against Sauron. He wanders as an old man, appearing humble but wielding great power.",
    "relationships": "Close friend of Bilbo, mentor to Frodo, ally of Aragorn"
  }
}
```

If the table has `backstory` and `relationships` as embed_fields, both will be embedded automatically.

## Notes

- The table must exist (use MemorySchemaCreateTable first)
- id is auto-generated if not provided
- created_at/updated_at are auto-generated if columns exist
- Returns the new row's UUID
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $table = trim($input['table'] ?? '');
        $dataJson = $input['data'] ?? '';

        if (empty($table)) {
            return ToolResult::error('table is required');
        }

        if (empty($dataJson)) {
            return ToolResult::error('data is required');
        }

        $data = json_decode($dataJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ToolResult::error('data must be valid JSON: ' . json_last_error_msg());
        }

        if (empty($data) || !is_array($data)) {
            return ToolResult::error('data must be a non-empty JSON object');
        }

        $service = app(MemoryDataService::class);
        $result = $service->insert($table, $data);

        if ($result['success']) {
            $output = [
                $result['message'],
                "ID: {$result['id']}",
            ];

            if (!empty($result['embedded_fields'])) {
                $output[] = "Embedded: " . implode(', ', $result['embedded_fields']);
            }

            return ToolResult::success(implode("\n", $output));
        }

        return ToolResult::error($result['message']);
    }
}
