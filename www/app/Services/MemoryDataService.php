<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for data operations (INSERT, UPDATE, DELETE) on memory schema tables.
 * Handles auto-embedding based on schema_registry configuration.
 */
class MemoryDataService
{
    public function __construct(
        protected MemorySchemaService $schemaService,
        protected MemoryEmbeddingService $embeddingService
    ) {}

    /**
     * Get the database connection for memory data operations.
     */
    protected function connection(): \Illuminate\Database\Connection
    {
        return DB::connection('pgsql_memory_ai');
    }

    /**
     * Insert a row into a memory table with auto-embedding.
     *
     * @param string $tableName Table name (without schema prefix)
     * @param array<string, mixed> $data Column => value pairs
     * @return array{success: bool, id: ?string, message: string, embedded_fields?: array}
     */
    public function insert(string $tableName, array $data): array
    {
        // Validate table name format
        $quotedTable = $this->quoteTableName($tableName);
        if ($quotedTable === null) {
            return [
                'success' => false,
                'id' => null,
                'message' => "Invalid table name: {$tableName}",
            ];
        }

        // Validate table exists
        if (!$this->schemaService->tableExists($tableName)) {
            return [
                'success' => false,
                'id' => null,
                'message' => "Table memory.{$tableName} does not exist",
            ];
        }

        // Validate column names
        foreach (array_keys($data) as $column) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
                return [
                    'success' => false,
                    'id' => null,
                    'message' => "Invalid column name: {$column}",
                ];
            }
        }

        // Get embeddable fields from schema_registry
        $embedFields = $this->schemaService->getEmbeddableFields($tableName);

        // Get column types for proper array/jsonb handling
        $columnTypes = $this->getColumnTypes($tableName);

        try {
            $rowId = null;

            $this->connection()->transaction(function () use ($quotedTable, $data, $columnTypes, &$rowId) {
                // Build and execute INSERT
                $columns = array_keys($data);
                $placeholders = array_map(fn($c) => $this->formatValue($data[$c], $columnTypes[$c] ?? null), $columns);

                $columnsSql = implode(', ', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $columns));
                $valuesSql = implode(', ', $placeholders);

                $sql = "INSERT INTO memory.{$quotedTable} ({$columnsSql}) VALUES ({$valuesSql}) RETURNING id";

                $result = $this->connection()->selectOne($sql);
                $rowId = $result->id ?? null;

                if (!$rowId) {
                    throw new \RuntimeException('Insert did not return an ID');
                }
            });

            // Generate embeddings for embeddable fields (outside transaction to not block)
            $embeddedResult = ['embedded_fields' => [], 'skipped_fields' => [], 'errors' => []];
            if ($rowId && !empty($embedFields)) {
                $fieldsToEmbed = [];
                foreach ($embedFields as $field) {
                    if (isset($data[$field]) && is_string($data[$field])) {
                        $fieldsToEmbed[$field] = $data[$field];
                    }
                }

                if (!empty($fieldsToEmbed)) {
                    $embeddedResult = $this->embeddingService->embedFields($tableName, $rowId, $fieldsToEmbed);
                }
            }

            Log::info('Memory row inserted', [
                'table' => $tableName,
                'id' => $rowId,
                'embedded_fields' => $embeddedResult['embedded_fields'],
            ]);

            return [
                'success' => true,
                'id' => $rowId,
                'message' => "Row inserted into memory.{$tableName}",
                'embedded_fields' => $embeddedResult['embedded_fields'],
            ];
        } catch (\Exception $e) {
            Log::error('Failed to insert memory row', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'id' => null,
                'message' => 'Failed to insert row: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Update rows in a memory table with auto-embedding regeneration.
     *
     * @param string $tableName Table name (without schema prefix)
     * @param array<string, mixed> $data Column => value pairs to update
     * @param string $whereClause WHERE clause (without 'WHERE' keyword)
     * @param array<mixed> $whereParams Parameters for WHERE clause
     * @return array{success: bool, affected_rows: int, message: string, embedded_rows?: int}
     */
    public function update(string $tableName, array $data, string $whereClause, array $whereParams = []): array
    {
        // Validate table name format
        $quotedTable = $this->quoteTableName($tableName);
        if ($quotedTable === null) {
            return [
                'success' => false,
                'affected_rows' => 0,
                'message' => "Invalid table name: {$tableName}",
            ];
        }

        // Validate table exists
        if (!$this->schemaService->tableExists($tableName)) {
            return [
                'success' => false,
                'affected_rows' => 0,
                'message' => "Table memory.{$tableName} does not exist",
            ];
        }

        if (empty($data)) {
            return [
                'success' => false,
                'affected_rows' => 0,
                'message' => 'No data provided for update',
            ];
        }

        if (empty($whereClause)) {
            return [
                'success' => false,
                'affected_rows' => 0,
                'message' => 'WHERE clause is required for updates',
            ];
        }

        // Validate column names
        foreach (array_keys($data) as $column) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
                return [
                    'success' => false,
                    'affected_rows' => 0,
                    'message' => "Invalid column name: {$column}",
                ];
            }
        }

        // Get embeddable fields
        $embedFields = $this->schemaService->getEmbeddableFields($tableName);
        $updatedEmbedFields = array_intersect($embedFields, array_keys($data));

        // Get column types for proper array/jsonb handling
        $columnTypes = $this->getColumnTypes($tableName);

        try {
            // First, get the IDs of rows that will be updated (for embedding regeneration)
            $affectedIds = [];
            if (!empty($updatedEmbedFields)) {
                $idResults = $this->connection()->select(
                    "SELECT id FROM memory.{$quotedTable} WHERE {$whereClause}",
                    $whereParams
                );
                $affectedIds = array_map(fn($r) => $r->id, $idResults);
            }

            // Build and execute UPDATE
            $setClauses = [];
            foreach ($data as $column => $value) {
                $setClauses[] = '"' . str_replace('"', '""', $column) . '" = ' . $this->formatValue($value, $columnTypes[$column] ?? null);
            }
            $setSql = implode(', ', $setClauses);

            $sql = "UPDATE memory.{$quotedTable} SET {$setSql} WHERE {$whereClause}";
            $affectedRows = $this->connection()->update($sql, $whereParams);

            // Regenerate embeddings for affected rows
            $embeddedRows = 0;
            if (!empty($updatedEmbedFields) && !empty($affectedIds)) {
                foreach ($affectedIds as $rowId) {
                    // Get updated row data
                    $row = $this->connection()->selectOne(
                        "SELECT * FROM memory.{$quotedTable} WHERE id = ?",
                        [$rowId]
                    );

                    if ($row) {
                        $fieldsToEmbed = [];
                        foreach ($embedFields as $field) {
                            if (isset($row->$field) && is_string($row->$field)) {
                                $fieldsToEmbed[$field] = $row->$field;
                            }
                        }

                        if (!empty($fieldsToEmbed)) {
                            $this->embeddingService->embedFields($tableName, $rowId, $fieldsToEmbed);
                            $embeddedRows++;
                        }
                    }
                }
            }

            Log::info('Memory rows updated', [
                'table' => $tableName,
                'affected_rows' => $affectedRows,
                'embedded_rows' => $embeddedRows,
            ]);

            return [
                'success' => true,
                'affected_rows' => $affectedRows,
                'message' => "Updated {$affectedRows} row(s) in memory.{$tableName}",
                'embedded_rows' => $embeddedRows,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to update memory rows', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'affected_rows' => 0,
                'message' => 'Failed to update rows: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Delete rows from a memory table and their embeddings.
     *
     * @param string $tableName Table name (without schema prefix)
     * @param string $whereClause WHERE clause (without 'WHERE' keyword)
     * @param array<mixed> $whereParams Parameters for WHERE clause
     * @return array{success: bool, deleted_rows: int, deleted_embeddings: int, message: string}
     */
    public function delete(string $tableName, string $whereClause, array $whereParams = []): array
    {
        // Validate table name format
        $quotedTable = $this->quoteTableName($tableName);
        if ($quotedTable === null) {
            return [
                'success' => false,
                'deleted_rows' => 0,
                'deleted_embeddings' => 0,
                'message' => "Invalid table name: {$tableName}",
            ];
        }

        // Validate table exists
        if (!$this->schemaService->tableExists($tableName)) {
            return [
                'success' => false,
                'deleted_rows' => 0,
                'deleted_embeddings' => 0,
                'message' => "Table memory.{$tableName} does not exist",
            ];
        }

        if (empty($whereClause)) {
            return [
                'success' => false,
                'deleted_rows' => 0,
                'deleted_embeddings' => 0,
                'message' => 'WHERE clause is required for deletes',
            ];
        }

        try {
            // Use privileged connection for transaction to ensure embedding deletes
            // (which also use 'pgsql') are within the same transactional scope.
            // This is safe because the method validates table exists in memory schema.
            return DB::connection('pgsql')->transaction(function () use ($tableName, $quotedTable, $whereClause, $whereParams) {
                // Delete rows and get their IDs in one atomic operation using RETURNING
                $deletedRows = DB::connection('pgsql')->select(
                    "DELETE FROM memory.{$quotedTable} WHERE {$whereClause} RETURNING id",
                    $whereParams
                );
                $ids = array_map(fn($r) => $r->id, $deletedRows);

                if (empty($ids)) {
                    return [
                        'success' => true,
                        'deleted_rows' => 0,
                        'deleted_embeddings' => 0,
                        'message' => 'No rows matched the WHERE clause',
                    ];
                }

                // Delete embeddings for deleted rows
                $deletedEmbeddings = 0;
                foreach ($ids as $rowId) {
                    $deletedEmbeddings += $this->embeddingService->deleteRowEmbeddings($tableName, $rowId);
                }

                Log::info('Memory rows deleted', [
                    'table' => $tableName,
                    'deleted_rows' => count($ids),
                    'deleted_embeddings' => $deletedEmbeddings,
                ]);

                return [
                    'success' => true,
                    'deleted_rows' => count($ids),
                    'deleted_embeddings' => $deletedEmbeddings,
                    'message' => "Deleted " . count($ids) . " row(s) from memory.{$tableName}",
                ];
            });
        } catch (\Exception $e) {
            Log::error('Failed to delete memory rows', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'deleted_rows' => 0,
                'deleted_embeddings' => 0,
                'message' => 'Failed to delete rows: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Execute a raw SELECT query on memory schema (read-only).
     *
     * @param string $sql SELECT query
     * @param array<mixed> $params Query parameters
     * @return array{success: bool, data: array, message: string}
     */
    public function query(string $sql, array $params = []): array
    {
        // Validate it's a SELECT (or WITH ... SELECT)
        if (!preg_match('/^\s*(SELECT|WITH)\s/i', $sql)) {
            return [
                'success' => false,
                'data' => [],
                'message' => 'Only SELECT queries are allowed',
            ];
        }

        // Block mutation keywords that could be used in writable CTEs
        // E.g., WITH deleted AS (DELETE FROM ...) SELECT * FROM deleted
        $dangerousPatterns = [
            '/\bINSERT\s+INTO\b/i',
            '/\bUPDATE\b/i',
            '/\bDELETE\s+FROM\b/i',
            '/\bDROP\s+/i',
            '/\bTRUNCATE\s+/i',
            '/\bALTER\s+/i',
            '/\bCREATE\s+/i',
        ];
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return [
                    'success' => false,
                    'data' => [],
                    'message' => 'Query contains disallowed mutation patterns',
                ];
            }
        }

        try {
            $results = DB::connection('pgsql_readonly')->select($sql, $params);

            return [
                'success' => true,
                'data' => array_map(fn($r) => (array) $r, $results),
                'message' => 'Query executed successfully',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => [],
                'message' => 'Query failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate and quote a table name to prevent SQL injection.
     * Only allows valid PostgreSQL identifiers.
     *
     * @param string $tableName Table name to validate
     * @return string|null Quoted table name or null if invalid
     */
    protected function quoteTableName(string $tableName): ?string
    {
        // Only allow valid PostgreSQL identifiers: lowercase letters, numbers, underscores
        // Must start with a letter
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $tableName)) {
            return null;
        }

        // Double-quote the identifier for safety
        return '"' . $tableName . '"';
    }

    /**
     * Get column types for a table from information_schema.
     *
     * @param string $tableName Table name (without schema prefix)
     * @return array<string, string> Column name => data type
     */
    protected function getColumnTypes(string $tableName): array
    {
        static $cache = [];

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $columns = DB::connection('pgsql_readonly')->select("
            SELECT column_name, data_type, udt_name
            FROM information_schema.columns
            WHERE table_schema = 'memory' AND table_name = ?
        ", [$tableName]);

        $types = [];
        foreach ($columns as $col) {
            // udt_name gives us the base type (e.g., '_text' for text[])
            // data_type gives us 'ARRAY' for arrays
            if ($col->data_type === 'ARRAY') {
                // udt_name starts with _ for array types (e.g., _text, _uuid, _int4)
                $baseType = ltrim($col->udt_name, '_');
                $types[$col->column_name] = $baseType . '[]';
            } else {
                $types[$col->column_name] = $col->data_type;
            }
        }

        $cache[$tableName] = $types;
        return $types;
    }

    /**
     * Format a value for SQL insertion.
     *
     * @param mixed $value The value to format
     * @param string|null $columnType Optional column type for proper casting
     */
    protected function formatValue(mixed $value, ?string $columnType = null): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            // Check if target column is an array type (e.g., text[], uuid[], int4[])
            if ($columnType !== null && str_ends_with($columnType, '[]')) {
                // Format as PostgreSQL array
                return $this->formatPgArray($value, $columnType);
            }

            // Default to JSONB for arrays
            return "'" . str_replace("'", "''", json_encode($value)) . "'::jsonb";
        }

        // String - escape single quotes
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * Format a PHP array as a PostgreSQL array literal.
     *
     * @param array $value The array to format
     * @param string $columnType The PostgreSQL array type (e.g., 'text[]', 'uuid[]')
     */
    protected function formatPgArray(array $value, string $columnType): string
    {
        // Escape each element and wrap in PostgreSQL array syntax
        $escaped = array_map(function ($item) {
            if ($item === null) {
                return 'NULL';
            }
            // Escape backslashes and double quotes, then wrap in quotes
            $escaped = str_replace('\\', '\\\\', (string) $item);
            $escaped = str_replace('"', '\\"', $escaped);
            return '"' . $escaped . '"';
        }, $value);

        return "'{" . implode(',', $escaped) . "}'::" . $columnType;
    }
}
