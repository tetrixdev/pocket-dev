<?php

namespace App\Tools;

use App\Services\MemorySchemaService;
use App\Services\MemoryEmbeddingService;

/**
 * Execute DDL SQL on the memory schema (CREATE INDEX, DROP TABLE, etc.).
 */
class MemorySchemaExecuteTool extends Tool
{
    public string $name = 'MemorySchemaExecute';

    public string $description = 'Execute DDL SQL on the memory schema (CREATE INDEX, DROP TABLE, etc.). ALTER TABLE is not supported.';

    public string $category = 'memory_schema';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'sql' => [
                'type' => 'string',
                'description' => 'DDL SQL statement to execute. Must operate on memory schema tables only.',
            ],
        ],
        'required' => ['sql'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'memory:schema:execute';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Use MemorySchemaExecute for DDL operations other than CREATE TABLE:
- CREATE INDEX
- DROP TABLE
- DROP INDEX
- UPDATE memory.embeddings (for recreate pattern)
- Other memory schema operations

## CLI Example

```bash
php artisan memory:schema:execute --sql="CREATE INDEX idx_characters_name ON memory.characters(name)"
php artisan memory:schema:execute --sql="DROP TABLE IF EXISTS memory.old_table"
```

## ALTER TABLE is NOT supported

To modify a table schema, use the **recreate pattern**:

1. Create new table with new schema:
   ```bash
   php artisan memory:schema:create-table --name=characters_v2 ...
   ```

2. Migrate data:
   ```bash
   php artisan memory:schema:execute --sql="INSERT INTO memory.characters_v2 SELECT id, name, class, backstory FROM memory.characters"
   ```

3. Update embeddings:
   ```bash
   php artisan memory:schema:execute --sql="UPDATE memory.embeddings SET source_table = 'characters_v2' WHERE source_table = 'characters'"
   ```

4. Drop old table:
   ```bash
   php artisan memory:schema:execute --sql="DROP TABLE memory.characters"
   ```

**Always confirm with the user before starting a schema migration.**

## Protected Tables

Cannot DROP or TRUNCATE:
- memory.embeddings
- memory.schema_registry

## Index Examples

```sql
-- Standard B-tree index
CREATE INDEX idx_tablename_column ON memory.tablename(column);

-- Trigram index for fuzzy text search
CREATE INDEX idx_tablename_name_trgm ON memory.tablename USING GIN (name gin_trgm_ops);

-- Spatial index for PostGIS
CREATE INDEX idx_tablename_geo ON memory.tablename USING GIST (coordinates);

-- JSONB index
CREATE INDEX idx_tablename_data ON memory.tablename USING GIN (data);
```
INSTRUCTIONS;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $sql = trim($input['sql'] ?? '');

        if (empty($sql)) {
            return ToolResult::error('sql is required');
        }

        $schemaService = app(MemorySchemaService::class);

        // Check if this is a DROP TABLE - if so, also delete embeddings
        $isDropTable = preg_match('/DROP\s+TABLE/i', $sql);
        $droppedTableName = null;

        if ($isDropTable) {
            // Extract table name for embedding cleanup
            if (preg_match('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?memory\.([a-z_][a-z0-9_]*)/i', $sql, $matches)) {
                $droppedTableName = $matches[1];
            } elseif (preg_match('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?"memory"\."([^"]+)"/i', $sql, $matches)) {
                $droppedTableName = $matches[1];
            }
        }

        $result = $schemaService->execute($sql);

        if ($result['success']) {
            $output = $result['message'];

            // Clean up embeddings for dropped table
            if ($droppedTableName) {
                $embeddingService = app(MemoryEmbeddingService::class);
                $deletedEmbeddings = $embeddingService->deleteTableEmbeddings($droppedTableName);
                if ($deletedEmbeddings > 0) {
                    $output .= "\nDeleted {$deletedEmbeddings} embedding(s) for table {$droppedTableName}";
                }
            }

            return ToolResult::success($output);
        }

        return ToolResult::error($result['message']);
    }
}
