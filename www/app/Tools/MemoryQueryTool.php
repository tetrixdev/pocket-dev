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
            'schema' => [
                'type' => 'string',
                'description' => 'Memory schema short name (e.g., "default", "my_campaign"). Required - check your available schemas in the system prompt.',
            ],
            'sql' => [
                'type' => 'string',
                'description' => 'SQL SELECT query. Tables use the schema prefix (e.g., memory_default.tablename). For semantic search, use: embedding <=> :search_embedding. Use 1 - (embedding <=> :search_embedding) for similarity score (0-1).',
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
        'required' => ['schema', 'sql'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:query';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemoryQuery to search and retrieve data from memory tables. This is your primary read tool for the memory system.

**IMPORTANT**: The `--schema` parameter is REQUIRED. Use the short schema name from your available schemas (e.g., "default", "my_campaign").

## System Tables (in each schema)

- **{schema}.schema_registry**: Table metadata (table_name, description, embeddable_fields)
- **{schema}.embeddings**: Vector embeddings (source_table, source_id, field_name, embedding, content)

## Common Query Patterns

Replace `{schema}` with your actual schema name (e.g., `memory_default`, `memory_my_campaign`).

### List all tables
```sql
SELECT table_name, description, embeddable_fields FROM {schema}.schema_registry
```

### Query a user table
```sql
SELECT id, name, class FROM {schema}.characters WHERE class = 'wizard'
```

### Semantic search (requires search_text parameter)
```sql
SELECT c.id, c.name, c.backstory, 1 - (e.embedding <=> :search_embedding) as similarity
FROM {schema}.characters c
JOIN {schema}.embeddings e ON e.source_id = c.id AND e.source_table = 'characters'
WHERE e.field_name = 'backstory'
  AND 1 - (e.embedding <=> :search_embedding) > 0.5
ORDER BY similarity DESC
```

### Fuzzy text search (pg_trgm)
```sql
SELECT * FROM {schema}.characters WHERE name % 'Gandolf'
```

## Important: Embedding Columns

- **Never SELECT the embedding column directly** - it's a 1536-dimension vector array
- Use `1 - (e.embedding <=> :search_embedding) as similarity` to get a 0-1 similarity score
- The `content` column in embeddings contains the original text that was embedded

## Notes
- Only SELECT queries allowed
- If no LIMIT specified, results are limited to 50 rows (max 100)
- Output is JSON format with full text (no truncation)
- Use :search_embedding placeholder with search_text parameter for vector searches
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
pd memory:query --schema=default --sql="SELECT * FROM memory_default.characters LIMIT 10"
pd memory:query --schema=default --sql="SELECT * FROM memory_default.schema_registry"
```
CLI;

    public ?string $apiExamples = <<<'API'
## API Example (JSON input)

Basic query:
```json
{
  "schema": "default",
  "sql": "SELECT * FROM memory_default.characters LIMIT 10"
}
```

Semantic search:
```json
{
  "schema": "default",
  "sql": "SELECT e.source_table, e.content, 1 - (e.embedding <=> :search_embedding) as similarity FROM memory_default.embeddings e WHERE 1 - (e.embedding <=> :search_embedding) > 0.6 ORDER BY similarity DESC LIMIT 5",
  "search_text": "dwarf warrior revenge"
}
```
API;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $schemaName = trim($input['schema'] ?? '');
        $sql = trim($input['sql'] ?? '');
        $searchText = $input['search_text'] ?? null;
        $limit = min(100, max(1, $input['limit'] ?? 50));

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

            // Check if limit was applied
            $hadLimit = preg_match('/\bLIMIT\s+\d+/i', $input['sql'] ?? '');
            $limitApplied = !$hadLimit;

            if (empty($results)) {
                return ToolResult::success(json_encode([
                    'results' => [],
                    'count' => 0,
                ], JSON_PRETTY_PRINT));
            }

            // Format results as JSON
            $output = $this->formatResults($results, $limitApplied, $limit);

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

        // Must start with SELECT or WITH
        if (!str_starts_with($normalized, 'SELECT') && !str_starts_with($normalized, 'WITH')) {
            return false;
        }

        // For CTEs (WITH), ensure it ends with SELECT, not a mutating statement
        // WITH ... DELETE/UPDATE/INSERT are not allowed
        if (str_starts_with($normalized, 'WITH')) {
            // Check for mutating statements after the CTE definition
            // The CTE pattern is: WITH name AS (...) <final statement>
            // We need to ensure the final statement is SELECT
            if (preg_match('/\)\s*(DELETE|UPDATE|INSERT)\b/i', $normalized)) {
                return false;
            }
        }

        return true;
    }

    private function containsDangerousPatterns(string $sql): bool
    {
        $normalized = strtoupper($sql);

        // Block mutating statements (backup check - isSelectQuery should catch these too)
        $mutating = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'CREATE', 'GRANT', 'REVOKE'];
        foreach ($mutating as $pattern) {
            if (preg_match('/\b' . $pattern . '\b/', $normalized)) {
                return true;
            }
        }

        // Block dangerous functions and system access
        $dangerous = [
            'EXECUTE', 'EXEC',
            'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE',
            'INFORMATION_SCHEMA', 'PG_CATALOG',
        ];

        foreach ($dangerous as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        // Block access to PostgreSQL system tables (pg_*) except allowed extensions
        // Allow: pg_trgm functions (similarity, show_trgm, etc. - used via % operator, not pg_trgm prefix)
        // Block: pg_class, pg_tables, pg_user, pg_roles, pg_settings, etc.
        if (preg_match('/\bPG_[A-Z]/', $normalized)) {
            return true;
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

    private function formatResults(array $results, bool $limitApplied, int $limit): string
    {
        $count = count($results);

        // Convert to arrays for easier handling
        $rows = array_map(fn($r) => (array) $r, $results);

        // Build output structure
        $output = [
            'results' => $rows,
            'count' => $count,
        ];

        // Add meta info if limit was auto-applied
        if ($limitApplied) {
            $output['_meta'] = [
                'limit_applied' => $limit,
                'note' => $count >= $limit
                    ? "Results limited to {$limit} rows. Add a WHERE clause or explicit LIMIT to narrow results."
                    : null,
            ];
            // Remove note if null
            if ($output['_meta']['note'] === null) {
                unset($output['_meta']['note']);
            }
        }

        return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
