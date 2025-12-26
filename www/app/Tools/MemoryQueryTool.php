<?php

namespace App\Tools;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Query memory tables using raw SQL (SELECT only).
 *
 * This tool allows semantic search and complex queries against the memory schema.
 * Only SELECT queries are allowed for safety.
 */
class MemoryQueryTool extends Tool
{
    public string $name = 'MemoryQuery';

    public string $description = 'Query memory tables using SQL. Supports semantic search with vector similarity.';

    public string $category = 'memory_data';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'sql' => [
                'type' => 'string',
                'description' => 'SQL SELECT query. Tables are in memory schema (memory.tablename). For semantic search, use: embedding <=> :search_embedding. Use 1 - (embedding <=> :search_embedding) for similarity score (0-1).',
            ],
            'search_text' => [
                'type' => 'string',
                'description' => 'Optional: Text to convert to embedding for semantic search. If provided, the embedding will be available as :search_embedding in your SQL.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of results to return. Default: 50, Max: 100.',
            ],
        ],
        'required' => ['sql'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:query';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryQuery to search and retrieve data from memory tables. This is your primary read tool for the memory system.

## CLI Example

```bash
php artisan memory:query --sql="SELECT * FROM memory.characters LIMIT 10"
php artisan memory:query --sql="SELECT * FROM memory.schema_registry"
```

## System Tables

- **memory.schema_registry**: Table metadata (table_name, description, embeddable_fields)
- **memory.embeddings**: Vector embeddings (source_table, source_id, field_name, embedding, content)

## Common Query Patterns

### List all tables
```sql
SELECT table_name, description, embeddable_fields FROM memory.schema_registry
```

### Query a user table
```sql
SELECT id, name, class FROM memory.characters WHERE class = 'wizard'
```

### Semantic search (requires search_text parameter)
```sql
SELECT c.id, c.name, c.backstory, 1 - (e.embedding <=> :search_embedding) as similarity
FROM memory.characters c
JOIN memory.embeddings e ON e.source_id = c.id AND e.source_table = 'characters'
WHERE e.field_name = 'backstory'
  AND 1 - (e.embedding <=> :search_embedding) > 0.5
ORDER BY similarity DESC
```

### Cross-table semantic search
```sql
SELECT e.source_table, e.source_id, e.field_name, e.content,
       1 - (e.embedding <=> :search_embedding) as similarity
FROM memory.embeddings e
WHERE 1 - (e.embedding <=> :search_embedding) > 0.6
ORDER BY similarity DESC
LIMIT 10
```

### Fuzzy text search (pg_trgm)
```sql
SELECT * FROM memory.characters WHERE name % 'Gandolf'
```

### Spatial query (PostGIS)
```sql
SELECT name, ST_Distance(coordinates, ST_MakePoint(-122.4, 37.8)::geography) as distance_m
FROM memory.locations
WHERE ST_DWithin(coordinates, ST_MakePoint(-122.4, 37.8)::geography, 50000)
ORDER BY distance_m
```

## Notes
- Only SELECT queries allowed
- Results limited to 100 rows
- Use :search_embedding placeholder with search_text parameter for vector searches
- Tables are in memory schema (memory.tablename)
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $sql = trim($input['sql'] ?? '');
        $searchText = $input['search_text'] ?? null;
        $limit = min(100, max(1, $input['limit'] ?? 50));

        if (empty($sql)) {
            return ToolResult::error('sql is required');
        }

        // Security: Only allow SELECT queries
        if (!$this->isSelectQuery($sql)) {
            return ToolResult::error('Only SELECT queries are allowed. Use MemoryInsert, MemoryUpdate, or MemoryDelete for modifications.');
        }

        // Security: Block dangerous patterns
        if ($this->containsDangerousPatterns($sql)) {
            return ToolResult::error('Query contains disallowed patterns.');
        }

        try {
            $bindings = [];

            // Generate embedding if search_text provided
            if ($searchText !== null && str_contains($sql, ':search_embedding')) {
                $embedding = $this->generateEmbedding($searchText);
                if ($embedding === null) {
                    return ToolResult::error('Failed to generate embedding for search_text. Check that embedding service is configured.');
                }
                // Replace placeholder with vector literal
                $vectorLiteral = "'" . $this->formatVector($embedding) . "'";
                $sql = str_replace(':search_embedding', $vectorLiteral, $sql);
            }

            // Add LIMIT if not present
            if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
                $sql .= " LIMIT {$limit}";
            }

            // Execute query using read-only connection for security
            // The memory_readonly user can only SELECT from memory_* tables
            $results = DB::connection('pgsql_readonly')->select($sql, $bindings);

            if (empty($results)) {
                return ToolResult::success("No results found.\n\nQuery: {$sql}");
            }

            // Format results as table
            $output = $this->formatResults($results, $sql);

            return ToolResult::success($output);
        } catch (\Exception $e) {
            Log::error('MemoryQueryTool error', [
                'sql' => $sql,
                'error' => $e->getMessage(),
            ]);
            return ToolResult::error('Query failed: ' . $e->getMessage());
        }
    }

    private function isSelectQuery(string $sql): bool
    {
        $normalized = strtoupper(trim($sql));
        return str_starts_with($normalized, 'SELECT') ||
               str_starts_with($normalized, 'WITH');
    }

    private function containsDangerousPatterns(string $sql): bool
    {
        $normalized = strtoupper($sql);

        $dangerous = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER',
            'CREATE', 'GRANT', 'REVOKE', 'EXECUTE', 'EXEC',
            'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE',
            'INFORMATION_SCHEMA', 'PG_CATALOG', 'PG_',
        ];

        foreach ($dangerous as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function generateEmbedding(string $text): ?array
    {
        $service = app(\App\Services\EmbeddingService::class);
        return $service->embed($text);
    }

    private function formatVector(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }

    private function formatResults(array $results, string $sql): string
    {
        $count = count($results);
        $output = ["Found {$count} result(s)", ""];

        // Convert to arrays for easier handling
        $rows = array_map(fn($r) => (array) $r, $results);

        if (empty($rows)) {
            return "No results.";
        }

        $columns = array_keys($rows[0]);

        // Calculate column widths
        $widths = [];
        foreach ($columns as $col) {
            $widths[$col] = strlen($col);
            foreach ($rows as $row) {
                $value = $this->formatValue($row[$col] ?? null);
                $widths[$col] = max($widths[$col], min(50, strlen($value)));
            }
        }

        // Build header
        $header = '| ';
        $separator = '|-';
        foreach ($columns as $col) {
            $header .= str_pad($col, $widths[$col]) . ' | ';
            $separator .= str_repeat('-', $widths[$col]) . '-|-';
        }
        $output[] = $header;
        $output[] = $separator;

        // Build rows
        foreach ($rows as $row) {
            $line = '| ';
            foreach ($columns as $col) {
                $value = $this->formatValue($row[$col] ?? null);
                if (strlen($value) > 50) {
                    $value = substr($value, 0, 47) . '...';
                }
                $line .= str_pad($value, $widths[$col]) . ' | ';
            }
            $output[] = $line;
        }

        return implode("\n", $output);
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        // Truncate embedding vectors
        if (is_string($value) && str_starts_with($value, '[') && strlen($value) > 100) {
            return '[vector...]';
        }
        return (string) $value;
    }
}
