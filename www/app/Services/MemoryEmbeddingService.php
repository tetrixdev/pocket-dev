<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing embeddings in the memory.embeddings table.
 * Handles embedding generation, storage, and hash-based change detection.
 */
class MemoryEmbeddingService
{
    public function __construct(
        protected EmbeddingService $embedder
    ) {}

    /**
     * Generate and store embeddings for specified fields of a row.
     *
     * @param string $tableName Table name (without schema prefix)
     * @param string $rowId UUID of the row
     * @param array<string, string> $fields Field name => content to embed
     * @return array{success: bool, embedded_fields: array<string>, skipped_fields: array<string>, errors: array<string>}
     */
    public function embedFields(string $tableName, string $rowId, array $fields): array
    {
        $embedded = [];
        $skipped = [];
        $errors = [];

        if (empty($fields)) {
            return ['success' => true, 'embedded_fields' => [], 'skipped_fields' => [], 'errors' => []];
        }

        // Check which fields need embedding (content changed)
        $fieldsToEmbed = [];
        foreach ($fields as $fieldName => $content) {
            if (!is_string($content) || empty(trim($content))) {
                $skipped[] = $fieldName;
                continue;
            }

            $contentHash = $this->hashContent($content);

            // Check if embedding exists and is current
            $existing = DB::connection('pgsql_readonly')->selectOne("
                SELECT content_hash FROM memory.embeddings
                WHERE source_table = ? AND source_id = ? AND field_name = ? AND chunk_index = 0
            ", [$tableName, $rowId, $fieldName]);

            if ($existing && $existing->content_hash === $contentHash) {
                $skipped[] = $fieldName;
                continue;
            }

            $fieldsToEmbed[$fieldName] = [
                'content' => $content,
                'hash' => $contentHash,
                'exists' => $existing !== null,
            ];
        }

        if (empty($fieldsToEmbed)) {
            return ['success' => true, 'embedded_fields' => [], 'skipped_fields' => $skipped, 'errors' => []];
        }

        // Generate embeddings in batch
        $contents = array_column($fieldsToEmbed, 'content');
        $embeddings = $this->embedder->embedBatch($contents);

        if ($embeddings === null) {
            return [
                'success' => false,
                'embedded_fields' => [],
                'skipped_fields' => $skipped,
                'errors' => ['Failed to generate embeddings - check API key configuration'],
            ];
        }

        // Store embeddings
        $fieldNames = array_keys($fieldsToEmbed);
        foreach ($fieldNames as $index => $fieldName) {
            $embedding = $embeddings[$index] ?? null;
            if ($embedding === null) {
                $errors[] = "Failed to generate embedding for field: {$fieldName}";
                continue;
            }

            $fieldData = $fieldsToEmbed[$fieldName];
            $vectorString = '[' . implode(',', $embedding) . ']';

            try {
                if ($fieldData['exists']) {
                    // Update existing embedding
                    DB::connection('pgsql_memory_ai')->statement("
                        UPDATE memory.embeddings
                        SET content = ?, content_hash = ?, embedding = ?, updated_at = NOW()
                        WHERE source_table = ? AND source_id = ? AND field_name = ? AND chunk_index = 0
                    ", [$fieldData['content'], $fieldData['hash'], $vectorString, $tableName, $rowId, $fieldName]);
                } else {
                    // Insert new embedding
                    DB::connection('pgsql_memory_ai')->statement("
                        INSERT INTO memory.embeddings
                        (source_table, source_id, field_name, chunk_index, content, content_hash, embedding)
                        VALUES (?, ?, ?, 0, ?, ?, ?)
                    ", [$tableName, $rowId, $fieldName, $fieldData['content'], $fieldData['hash'], $vectorString]);
                }

                $embedded[] = $fieldName;
            } catch (\Exception $e) {
                $errors[] = "Failed to store embedding for {$fieldName}: " . $e->getMessage();
                Log::error('Failed to store embedding', [
                    'table' => $tableName,
                    'row_id' => $rowId,
                    'field' => $fieldName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => empty($errors),
            'embedded_fields' => $embedded,
            'skipped_fields' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Delete all embeddings for a specific row.
     *
     * @param string $tableName Table name (without schema prefix)
     * @param string $rowId UUID of the row
     * @return int Number of embeddings deleted
     */
    public function deleteRowEmbeddings(string $tableName, string $rowId): int
    {
        return DB::connection('pgsql_memory_ai')->delete("
            DELETE FROM memory.embeddings
            WHERE source_table = ? AND source_id = ?
        ", [$tableName, $rowId]);
    }

    /**
     * Delete embeddings for specific fields of a row.
     *
     * @param string $tableName Table name (without schema prefix)
     * @param string $rowId UUID of the row
     * @param array<string> $fieldNames Fields to delete embeddings for
     * @return int Number of embeddings deleted
     */
    public function deleteFieldEmbeddings(string $tableName, string $rowId, array $fieldNames): int
    {
        if (empty($fieldNames)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($fieldNames), '?'));

        return DB::connection('pgsql_memory_ai')->delete("
            DELETE FROM memory.embeddings
            WHERE source_table = ? AND source_id = ? AND field_name IN ({$placeholders})
        ", array_merge([$tableName, $rowId], $fieldNames));
    }

    /**
     * Update source_table for embeddings (used in recreate pattern).
     *
     * @param string $oldTableName Old table name
     * @param string $newTableName New table name
     * @return int Number of embeddings updated
     */
    public function updateSourceTable(string $oldTableName, string $newTableName): int
    {
        return DB::connection('pgsql_memory_ai')->update("
            UPDATE memory.embeddings
            SET source_table = ?, updated_at = NOW()
            WHERE source_table = ?
        ", [$newTableName, $oldTableName]);
    }

    /**
     * Delete all embeddings for a table (used when dropping a table).
     *
     * @param string $tableName Table name (without schema prefix)
     * @return int Number of embeddings deleted
     */
    public function deleteTableEmbeddings(string $tableName): int
    {
        return DB::connection('pgsql_memory_ai')->delete("
            DELETE FROM memory.embeddings WHERE source_table = ?
        ", [$tableName]);
    }

    /**
     * Get embedding count for a table.
     */
    public function getTableEmbeddingCount(string $tableName): int
    {
        $result = DB::connection('pgsql_readonly')->selectOne("
            SELECT COUNT(*) as count FROM memory.embeddings WHERE source_table = ?
        ", [$tableName]);

        return (int) ($result->count ?? 0);
    }

    /**
     * Regenerate all embeddings for a table.
     * Useful after changing embeddable_fields in schema_registry.
     *
     * @param string $tableName Table name
     * @param array<string> $embedFields Fields to embed
     * @param callable|null $progressCallback Optional callback(int $processed, int $total)
     * @return array{success: bool, processed: int, errors: array<string>}
     */
    public function regenerateTableEmbeddings(
        string $tableName,
        array $embedFields,
        ?callable $progressCallback = null
    ): array {
        $processed = 0;
        $errors = [];

        if (empty($embedFields)) {
            return ['success' => true, 'processed' => 0, 'errors' => []];
        }

        // Get all rows
        $rows = DB::connection('pgsql_readonly')->select(
            "SELECT * FROM memory.{$tableName}"
        );

        $total = count($rows);

        foreach ($rows as $row) {
            $rowId = $row->id ?? null;
            if (!$rowId) {
                $errors[] = "Row without ID found in {$tableName}";
                continue;
            }

            // Extract embeddable field values
            $fields = [];
            foreach ($embedFields as $fieldName) {
                if (isset($row->$fieldName) && is_string($row->$fieldName)) {
                    $fields[$fieldName] = $row->$fieldName;
                }
            }

            if (!empty($fields)) {
                $result = $this->embedFields($tableName, $rowId, $fields);
                if (!$result['success']) {
                    $errors = array_merge($errors, $result['errors']);
                }
            }

            $processed++;

            if ($progressCallback) {
                $progressCallback($processed, $total);
            }
        }

        return [
            'success' => empty($errors),
            'processed' => $processed,
            'errors' => $errors,
        ];
    }

    /**
     * Check if embedding service is available.
     */
    public function isAvailable(): bool
    {
        return $this->embedder->isAvailable();
    }

    /**
     * Generate SHA256 hash of content.
     */
    public function hashContent(string $content): string
    {
        return hash('sha256', $content);
    }
}
