<?php

namespace App\Tools;

use App\Services\MemorySchemaService;

/**
 * List all tables in the memory schema with their metadata.
 */
class MemorySchemaListTablesTool extends Tool
{
    public string $name = 'MemorySchemaListTables';

    public string $description = 'List all tables in the memory schema with columns, types, and metadata.';

    public string $category = 'memory_schema';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'table' => [
                'type' => 'string',
                'description' => 'Optional: Show details for a specific table only.',
            ],
            'show_columns' => [
                'type' => 'boolean',
                'description' => 'Include column details. Default: true.',
            ],
        ],
        'required' => [],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:schema:list-tables';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemorySchemaListTables to see what tables exist in the memory schema.

## CLI Example

```bash
php artisan memory:schema:list-tables
php artisan memory:schema:list-tables --table=characters
php artisan memory:schema:list-tables --show-columns=false
```

## Output Includes

For each table:
- Table name and description
- Row count
- Embeddable fields (fields that auto-generate embeddings)
- Column names, types, and descriptions

## System Tables (DO NOT DROP)

- **memory.embeddings**: Central storage for semantic search vectors
- **memory.schema_registry**: Tracks table metadata and embeddable fields
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $specificTable = $input['table'] ?? null;
        $showColumns = $input['show_columns'] ?? true;

        $service = app(MemorySchemaService::class);
        $tables = $service->listTables();

        if (empty($tables)) {
            return ToolResult::success("No tables found in memory schema.\n\nSystem tables (embeddings, schema_registry) are protected.");
        }

        // Filter to specific table if requested
        if ($specificTable) {
            $tables = array_filter($tables, fn($t) => $t['table_name'] === $specificTable);
            if (empty($tables)) {
                return ToolResult::error("Table '{$specificTable}' not found in memory schema.");
            }
        }

        $output = [];
        $output[] = "Memory Schema Tables";
        $output[] = str_repeat('=', 50);
        $output[] = '';

        foreach ($tables as $table) {
            $isSystem = in_array($table['table_name'], ['embeddings', 'schema_registry']);
            $systemBadge = $isSystem ? ' [SYSTEM - DO NOT DROP]' : '';

            $output[] = "## {$table['table_name']}{$systemBadge}";

            if ($table['description']) {
                $output[] = $table['description'];
            }

            $output[] = '';
            $output[] = "Rows: {$table['row_count']}";

            if (!empty($table['embeddable_fields'])) {
                $output[] = "Auto-embed: " . implode(', ', $table['embeddable_fields']);
            }

            if ($showColumns && !empty($table['columns'])) {
                $output[] = '';
                $output[] = "Columns:";

                foreach ($table['columns'] as $col) {
                    $nullable = $col['nullable'] ? '' : ' NOT NULL';
                    $default = $col['default'] ? " = {$col['default']}" : '';
                    $desc = $col['description'] ? " -- {$col['description']}" : '';

                    $output[] = "  - {$col['name']}: {$col['type']}{$nullable}{$default}{$desc}";
                }
            }

            $output[] = '';
            $output[] = str_repeat('-', 50);
            $output[] = '';
        }

        return ToolResult::success(implode("\n", $output));
    }
}
