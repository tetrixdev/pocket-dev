<?php

namespace App\Tools;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Query memory objects using raw SQL (SELECT only).
 *
 * This tool allows semantic search and complex queries against the memory store.
 * Only SELECT queries are allowed for safety.
 */
class MemoryQueryTool extends Tool
{
    public string $name = 'MemoryQuery';

    public string $description = 'Query memory objects using SQL. Supports semantic search with vector similarity.';

    public string $category = 'memory';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'sql' => [
                'type' => 'string',
                'description' => 'SQL SELECT query. Available tables: memory_structures, memory_objects, memory_embeddings, memory_relationships. For semantic search, use: embedding <=> $query_embedding where $query_embedding is a vector literal like \'[0.1,0.2,...]\'. Use 1 - (embedding <=> query) for similarity score.',
            ],
            'search_text' => [
                'type' => 'string',
                'description' => 'Optional: Text to convert to embedding for semantic search. If provided, the embedding will be available as :search_embedding parameter in your SQL.',
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
Use MemoryQuery to search and retrieve memory objects. This is your primary read tool for the memory system.

## CLI Example

```bash
php artisan memory:query --sql="SELECT id, name, slug FROM memory_structures"
php artisan memory:query --sql="SELECT * FROM memory_objects WHERE structure_slug='character'" --limit=10
```

## Available Tables

- **memory_structures**: Schema definitions (id, name, slug, description, schema, icon, color)
- **memory_objects**: All entities (id, structure_id, structure_slug, name, data, searchable_text, parent_id)
- **memory_embeddings**: Vector embeddings (id, object_id, field_path, content_hash, embedding)
- **memory_relationships**: Links between objects (id, source_id, target_id, relationship_type)

## Common Query Patterns

### List all structures
```sql
SELECT id, name, slug, description FROM memory_structures
```

### List objects of a type
```sql
SELECT id, name, data FROM memory_objects WHERE structure_slug = 'character'
```

### Get object with relationships
```sql
SELECT mo.*, mr.relationship_type, target.name as related_name
FROM memory_objects mo
LEFT JOIN memory_relationships mr ON mo.id = mr.source_id
LEFT JOIN memory_objects target ON mr.target_id = target.id
WHERE mo.id = 'uuid-here'
```

### Semantic search (requires search_text parameter)
```sql
SELECT mo.id, mo.name, 1 - (me.embedding <=> :search_embedding) as similarity
FROM memory_objects mo
JOIN memory_embeddings me ON mo.id = me.object_id
WHERE mo.structure_slug = 'location'
  AND 1 - (me.embedding <=> :search_embedding) > 0.5
ORDER BY similarity DESC
```

### JSONB queries
```sql
SELECT * FROM memory_objects
WHERE structure_slug = 'item'
  AND data @> '{"type": "weapon"}'
```

## Notes
- Only SELECT queries allowed
- Results limited to 100 rows
- Use :search_embedding placeholder for vector searches
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
            return ToolResult::error('Only SELECT queries are allowed. Use MemoryCreate, MemoryUpdate, MemoryDelete, MemoryLink, or MemoryUnlink for modifications.');
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
