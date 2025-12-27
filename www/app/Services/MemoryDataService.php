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
        // Validate table exists
        if (!$this->schemaService->tableExists($tableName)) {
            return [
                'success' => false,
                'id' => null,
                'message' => "Table memory.{$tableName} does not exist",
            ];
        }

        // Get embeddable fields from schema_registry
        $embedFields = $this->schemaService->getEmbeddableFields($tableName);

        try {
            $rowId = null;

            $this->connection()->transaction(function () use ($tableName, $data, $embedFields, &$rowId) {
                // Build and execute INSERT
                $columns = array_keys($data);
                $placeholders = array_map(fn($c) => $this->formatValue($data[$c]), $columns);

                $columnsSql = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
                $valuesSql = implode(', ', $placeholders);

                $sql = "INSERT INTO memory.{$tableName} ({$columnsSql}) VALUES ({$valuesSql}) RETURNING id";

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

        // Get embeddable fields
        $embedFields = $this->schemaService->getEmbeddableFields($tableName);
        $updatedEmbedFields = array_intersect($embedFields, array_keys($data));

        try {
            // First, get the IDs of rows that will be updated (for embedding regeneration)
            $affectedIds = [];
            if (!empty($updatedEmbedFields)) {
                $idResults = $this->connection()->select(
                    "SELECT id FROM memory.{$tableName} WHERE {$whereClause}",
                    $whereParams
                );
                $affectedIds = array_map(fn($r) => $r->id, $idResults);
            }

            // Build and execute UPDATE
            $setClauses = [];
            foreach ($data as $column => $value) {
                $setClauses[] = '"' . $column . '" = ' . $this->formatValue($value);
            }
            $setSql = implode(', ', $setClauses);

            $sql = "UPDATE memory.{$tableName} SET {$setSql} WHERE {$whereClause}";
            $affectedRows = $this->connection()->update($sql, $whereParams);

            // Regenerate embeddings for affected rows
            $embeddedRows = 0;
            if (!empty($updatedEmbedFields) && !empty($affectedIds)) {
                foreach ($affectedIds as $rowId) {
                    // Get updated row data
                    $row = $this->connection()->selectOne(
                        "SELECT * FROM memory.{$tableName} WHERE id = ?",
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
            // Get IDs of rows to be deleted
            $rowsToDelete = $this->connection()->select(
                "SELECT id FROM memory.{$tableName} WHERE {$whereClause}",
                $whereParams
            );
            $ids = array_map(fn($r) => $r->id, $rowsToDelete);

            if (empty($ids)) {
                return [
                    'success' => true,
                    'deleted_rows' => 0,
                    'deleted_embeddings' => 0,
                    'message' => 'No rows matched the WHERE clause',
                ];
            }

            // Delete embeddings first
            $deletedEmbeddings = 0;
            foreach ($ids as $rowId) {
                $deletedEmbeddings += $this->embeddingService->deleteRowEmbeddings($tableName, $rowId);
            }

            // Delete the rows
            $deletedRows = $this->connection()->delete(
                "DELETE FROM memory.{$tableName} WHERE {$whereClause}",
                $whereParams
            );

            Log::info('Memory rows deleted', [
                'table' => $tableName,
                'deleted_rows' => $deletedRows,
                'deleted_embeddings' => $deletedEmbeddings,
            ]);

            return [
                'success' => true,
                'deleted_rows' => $deletedRows,
                'deleted_embeddings' => $deletedEmbeddings,
                'message' => "Deleted {$deletedRows} row(s) from memory.{$tableName}",
            ];
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
        // Validate it's a SELECT
        if (!preg_match('/^\s*SELECT\s/i', $sql)) {
            return [
                'success' => false,
                'data' => [],
                'message' => 'Only SELECT queries are allowed',
            ];
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
     * Format a value for SQL insertion.
     */
    protected function formatValue(mixed $value): string
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
            // JSON for complex arrays
            return "'" . str_replace("'", "''", json_encode($value)) . "'::jsonb";
        }

        // String - escape single quotes
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}
