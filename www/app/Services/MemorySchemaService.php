<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing the memory schema DDL operations.
 * Uses the memory_ai database user with DDL/DML rights.
 */
class MemorySchemaService
{
    private const PROTECTED_TABLES = ['embeddings', 'schema_registry'];

    /**
     * Get the database connection for memory schema operations.
     */
    protected function connection(): \Illuminate\Database\Connection
    {
        return DB::connection('pgsql_memory_ai');
    }

    /**
     * Create a table in the memory schema.
     *
     * @param string $tableName Table name (without schema prefix)
     * @param string $sql CREATE TABLE SQL statement
     * @param string $description Description of the table
     * @param array<string> $embedFields Fields to auto-embed on insert/update
     * @param array<string, string> $columnDescriptions Field descriptions for COMMENT ON COLUMN
     * @return array{success: bool, message: string}
     */
    public function createTable(
        string $tableName,
        string $sql,
        string $description,
        array $embedFields = [],
        array $columnDescriptions = []
    ): array {
        // Validate table name
        $validation = $this->validateTableName($tableName);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => $validation['error']];
        }

        // Validate SQL is CREATE TABLE
        if (!$this->isCreateTableStatement($sql)) {
            return [
                'success' => false,
                'message' => 'SQL must be a CREATE TABLE statement',
            ];
        }

        // Check for protected table names
        if ($this->isProtectedTable($tableName)) {
            return [
                'success' => false,
                'message' => "Table '{$tableName}' is a protected system table and cannot be created",
            ];
        }

        // Ensure SQL references the memory schema
        if (!$this->sqlReferencesMemorySchema($sql, $tableName)) {
            return [
                'success' => false,
                'message' => "SQL must create table in memory schema: memory.{$tableName}",
            ];
        }

        try {
            $this->connection()->transaction(function () use ($tableName, $sql, $description, $embedFields, $columnDescriptions) {
                // Execute CREATE TABLE
                $this->connection()->statement($sql);

                // Add column comments
                foreach ($columnDescriptions as $column => $colDescription) {
                    $this->addColumnComment($tableName, $column, $colDescription);
                }

                // Register in schema_registry
                $this->connection()->table('schema_registry')->insert([
                    'table_name' => $tableName,
                    'description' => $description,
                    'embeddable_fields' => $this->arrayToPgArray($embedFields),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Grant SELECT to memory_readonly for query access
                // This is needed because ALTER DEFAULT PRIVILEGES only applies to tables
                // created by the role that ran the ALTER, not tables created by other roles
                DB::statement("GRANT SELECT ON memory.{$tableName} TO memory_readonly");
            });

            Log::info('Memory table created', [
                'table' => $tableName,
                'embed_fields' => $embedFields,
            ]);

            return [
                'success' => true,
                'message' => "Table memory.{$tableName} created successfully",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create memory table', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create table: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Execute DDL SQL on the memory schema (CREATE INDEX, DROP TABLE, etc.).
     *
     * @param string $sql DDL SQL statement
     * @return array{success: bool, message: string}
     */
    public function execute(string $sql): array
    {
        // Block ALTER TABLE - must use recreate pattern
        if ($this->isAlterTableStatement($sql)) {
            return [
                'success' => false,
                'message' => 'ALTER TABLE is not supported. To modify a table schema, use the recreate pattern: '
                    . '1) Create new table with new schema, '
                    . '2) Migrate data with INSERT...SELECT, '
                    . '3) Update embeddings source_table, '
                    . '4) Drop old table. '
                    . 'Always confirm with the user before starting.',
            ];
        }

        // Check for protected tables in DROP/TRUNCATE statements
        $protectedCheck = $this->checkProtectedTables($sql);
        if ($protectedCheck !== null) {
            return [
                'success' => false,
                'message' => $protectedCheck,
            ];
        }

        // Ensure SQL only operates on memory schema
        if (!$this->sqlOperatesOnMemorySchemaOnly($sql)) {
            return [
                'success' => false,
                'message' => 'SQL must only operate on tables in the memory schema',
            ];
        }

        try {
            $this->connection()->statement($sql);

            // If this was a DROP TABLE, also clean up schema_registry
            // Use main Laravel connection which has DELETE permission on schema_registry
            $droppedTable = $this->extractDroppedTableName($sql);
            if ($droppedTable) {
                DB::table('memory.schema_registry')
                    ->where('table_name', $droppedTable)
                    ->delete();

                Log::info('Memory table dropped', ['table' => $droppedTable]);
            }

            return [
                'success' => true,
                'message' => 'SQL executed successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to execute memory DDL', [
                'sql' => $sql,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to execute SQL: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * List all tables in the memory schema with their metadata.
     *
     * @return array<array{table_name: string, description: ?string, embeddable_fields: array, columns: array, row_count: int}>
     */
    public function listTables(): array
    {
        $tables = [];

        // Get tables from information_schema
        $dbTables = DB::connection('pgsql_readonly')->select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'memory'
            ORDER BY table_name
        ");

        foreach ($dbTables as $table) {
            $tableName = $table->table_name;

            // Get metadata from schema_registry
            $registry = DB::connection('pgsql_readonly')
                ->table('memory.schema_registry')
                ->where('table_name', $tableName)
                ->first();

            // Get columns with their types and descriptions
            $columns = $this->getTableColumns($tableName);

            // Get row count
            $countResult = DB::connection('pgsql_readonly')
                ->select("SELECT COUNT(*) as count FROM memory.{$tableName}");
            $rowCount = $countResult[0]->count ?? 0;

            $tables[] = [
                'table_name' => $tableName,
                'description' => $registry->description ?? null,
                'embeddable_fields' => $registry ? $this->pgArrayToArray($registry->embeddable_fields) : [],
                'columns' => $columns,
                'row_count' => (int) $rowCount,
            ];
        }

        return $tables;
    }

    /**
     * Get detailed column information for a table.
     *
     * @return array<array{name: string, type: string, nullable: bool, description: ?string}>
     */
    public function getTableColumns(string $tableName): array
    {
        $columns = [];

        // Get column info from information_schema
        $dbColumns = DB::connection('pgsql_readonly')->select("
            SELECT
                c.column_name,
                c.data_type,
                c.udt_name,
                c.is_nullable,
                c.column_default,
                pgd.description
            FROM information_schema.columns c
            LEFT JOIN pg_catalog.pg_statio_all_tables st
                ON st.schemaname = c.table_schema AND st.relname = c.table_name
            LEFT JOIN pg_catalog.pg_description pgd
                ON pgd.objoid = st.relid AND pgd.objsubid = c.ordinal_position
            WHERE c.table_schema = 'memory'
                AND c.table_name = ?
            ORDER BY c.ordinal_position
        ", [$tableName]);

        foreach ($dbColumns as $col) {
            // Use udt_name for custom types like 'vector', 'geography'
            $type = $col->udt_name;
            if ($col->data_type === 'ARRAY') {
                $type = $col->udt_name . '[]';
            } elseif (in_array($col->data_type, ['character varying', 'character'])) {
                $type = $col->data_type;
            } elseif ($col->data_type !== 'USER-DEFINED') {
                $type = $col->data_type;
            }

            $columns[] = [
                'name' => $col->column_name,
                'type' => $type,
                'nullable' => $col->is_nullable === 'YES',
                'default' => $col->column_default,
                'description' => $col->description,
            ];
        }

        return $columns;
    }

    /**
     * Get embeddable fields for a table from schema_registry.
     *
     * @return array<string>
     */
    public function getEmbeddableFields(string $tableName): array
    {
        $registry = DB::connection('pgsql_readonly')
            ->table('memory.schema_registry')
            ->where('table_name', $tableName)
            ->first();

        if (!$registry) {
            return [];
        }

        return $this->pgArrayToArray($registry->embeddable_fields);
    }

    /**
     * Update schema_registry for a table (e.g., after recreate pattern).
     */
    public function updateRegistry(string $tableName, ?string $description = null, ?array $embedFields = null): bool
    {
        $data = ['updated_at' => now()];

        if ($description !== null) {
            $data['description'] = $description;
        }

        if ($embedFields !== null) {
            $data['embeddable_fields'] = $this->arrayToPgArray($embedFields);
        }

        return $this->connection()
            ->table('schema_registry')
            ->where('table_name', $tableName)
            ->update($data) > 0;
    }

    /**
     * Check if a table exists in the memory schema.
     */
    public function tableExists(string $tableName): bool
    {
        $result = DB::connection('pgsql_readonly')->selectOne("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'memory' AND table_name = ?
            ) as exists
        ", [$tableName]);

        return (bool) $result->exists;
    }

    /**
     * Add a COMMENT ON COLUMN to a table.
     */
    protected function addColumnComment(string $tableName, string $columnName, string $description): void
    {
        $quotedTable = '"memory"."' . str_replace('"', '""', $tableName) . '"';
        $quotedColumn = '"' . str_replace('"', '""', $columnName) . '"';
        $escapedDescription = str_replace("'", "''", $description);

        $this->connection()->statement(
            "COMMENT ON COLUMN {$quotedTable}.{$quotedColumn} IS '{$escapedDescription}'"
        );
    }

    /**
     * Validate table name is safe and follows conventions.
     *
     * @return array{valid: bool, error?: string}
     */
    protected function validateTableName(string $tableName): array
    {
        if (empty($tableName)) {
            return ['valid' => false, 'error' => 'Table name cannot be empty'];
        }

        // Only allow alphanumeric and underscores
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $tableName)) {
            return [
                'valid' => false,
                'error' => 'Table name must start with a letter and contain only lowercase letters, numbers, and underscores',
            ];
        }

        // Max length
        if (strlen($tableName) > 63) {
            return ['valid' => false, 'error' => 'Table name must be 63 characters or less'];
        }

        return ['valid' => true];
    }

    /**
     * Check if a table is protected (cannot be dropped/truncated).
     */
    protected function isProtectedTable(string $tableName): bool
    {
        return in_array($tableName, self::PROTECTED_TABLES, true);
    }

    /**
     * Check if SQL is a CREATE TABLE statement.
     */
    protected function isCreateTableStatement(string $sql): bool
    {
        return (bool) preg_match('/^\s*CREATE\s+TABLE\s+/i', $sql);
    }

    /**
     * Check if SQL is an ALTER TABLE statement.
     */
    protected function isAlterTableStatement(string $sql): bool
    {
        return (bool) preg_match('/^\s*ALTER\s+TABLE\s+/i', $sql);
    }

    /**
     * Check if the CREATE TABLE SQL references the memory schema.
     */
    protected function sqlReferencesMemorySchema(string $sql, string $tableName): bool
    {
        // Must be memory.tablename or "memory"."tablename"
        $patterns = [
            '/CREATE\s+TABLE\s+memory\.' . preg_quote($tableName, '/') . '\s*\(/i',
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+memory\.' . preg_quote($tableName, '/') . '\s*\(/i',
            '/CREATE\s+TABLE\s+"memory"\."' . preg_quote($tableName, '/') . '"\s*\(/i',
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+"memory"\."' . preg_quote($tableName, '/') . '"\s*\(/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip string literals from SQL to avoid false positives in validation.
     * Replaces 'string content' with '' placeholder.
     */
    protected function stripStringLiterals(string $sql): string
    {
        // Handle escaped quotes within strings: replace '' with placeholder first
        $sql = str_replace("''", "\x00\x00", $sql);

        // Remove content between single quotes (non-greedy)
        $sql = preg_replace("/'[^']*'/", "''", $sql);

        // Restore escaped quote placeholders
        $sql = str_replace("\x00\x00", "''", $sql);

        return $sql;
    }

    /**
     * Check if SQL only operates on memory schema.
     */
    protected function sqlOperatesOnMemorySchemaOnly(string $sql): bool
    {
        // Strip string literals to avoid false positives from content like {"type": "..."}
        $sqlWithoutStrings = $this->stripStringLiterals($sql);
        $sqlWithoutStrings = strtolower($sqlWithoutStrings);

        // Comprehensive list of SQL keywords that might appear after FROM/JOIN/etc.
        $keywords = [
            // Common clauses
            'set', 'where', 'values', 'select', 'as', 'and', 'or', 'not', 'null', 'true', 'false',
            // Join types
            'inner', 'outer', 'left', 'right', 'full', 'cross', 'natural',
            // Other keywords that might follow table references
            'on', 'using', 'group', 'order', 'having', 'limit', 'offset', 'returning',
            'union', 'except', 'intersect', 'with', 'recursive',
            // CASE expression
            'case', 'when', 'then', 'else', 'end',
            // Misc
            'distinct', 'all', 'exists', 'in', 'between', 'like', 'ilike', 'is', 'any', 'some',
            'default', 'constraint', 'primary', 'key', 'foreign', 'references', 'unique', 'check',
            'index', 'cascade', 'restrict', 'no', 'action', 'initially', 'deferred', 'immediate',
        ];

        // Look for table references that aren't memory. prefixed
        // Common patterns: FROM table, JOIN table, INTO table, UPDATE table, TABLE table
        if (preg_match_all('/\b(from|join|into|update|table)\s+(?!memory\.)([a-z_][a-z0-9_]*)/i', $sqlWithoutStrings, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $potentialTable = strtolower($match[2]);
                // If it's not a keyword, it's likely an unqualified table reference
                if (!in_array($potentialTable, $keywords)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if SQL tries to DROP or TRUNCATE protected tables.
     */
    protected function checkProtectedTables(string $sql): ?string
    {
        $sql = strtolower($sql);

        foreach (self::PROTECTED_TABLES as $protected) {
            if (preg_match('/\b(drop|truncate)\s+table\s+.*\b' . preg_quote($protected, '/') . '\b/', $sql)) {
                return "Cannot DROP or TRUNCATE protected table: {$protected}";
            }
        }

        return null;
    }

    /**
     * Extract table name from a DROP TABLE statement.
     */
    protected function extractDroppedTableName(string $sql): ?string
    {
        if (preg_match('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?memory\.([a-z_][a-z0-9_]*)/i', $sql, $matches)) {
            return $matches[1];
        }

        if (preg_match('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?"memory"\."([^"]+)"/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Convert PHP array to PostgreSQL array literal.
     */
    protected function arrayToPgArray(array $array): string
    {
        if (empty($array)) {
            return '{}';
        }

        $escaped = array_map(function ($item) {
            return '"' . str_replace('"', '\\"', $item) . '"';
        }, $array);

        return '{' . implode(',', $escaped) . '}';
    }

    /**
     * Convert PostgreSQL array literal to PHP array.
     */
    protected function pgArrayToArray(?string $pgArray): array
    {
        if ($pgArray === null || $pgArray === '{}') {
            return [];
        }

        // Remove braces
        $content = trim($pgArray, '{}');
        if (empty($content)) {
            return [];
        }

        // Parse quoted strings
        preg_match_all('/"([^"]*)"/', $content, $matches);
        if (!empty($matches[1])) {
            return $matches[1];
        }

        // Fallback: simple split for unquoted values
        return array_map('trim', explode(',', $content));
    }
}
