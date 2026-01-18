<?php

namespace App\Services;

use App\Models\MemoryDatabase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Service for managing memory schema snapshots using pg_dump/pg_restore.
 * Implements tiered retention: hourly (24h) → 4/day (7d) → 1/day (30d).
 *
 * Supports multiple memory databases through setMemoryDatabase().
 * When no memory database is set, defaults to the 'memory' schema for backward compatibility.
 */
class MemorySnapshotService
{
    protected string $directory;
    protected int $retentionDays;
    protected ?MemoryDatabase $memoryDatabase = null;

    public function __construct(
        protected AppSettingsService $settings
    ) {
        $this->directory = config('memory.snapshot_directory', storage_path('memory-snapshots'));
        $this->retentionDays = $this->settings->get('memory_snapshot_retention_days', config('memory.snapshot_retention_days', 30));
    }

    /**
     * Set the memory database to operate on.
     *
     * @param MemoryDatabase $memoryDatabase The memory database to use
     * @return $this
     */
    public function setMemoryDatabase(MemoryDatabase $memoryDatabase): self
    {
        $this->memoryDatabase = $memoryDatabase;
        return $this;
    }

    /**
     * Get the current memory database, if any.
     */
    public function getMemoryDatabase(): ?MemoryDatabase
    {
        return $this->memoryDatabase;
    }

    /**
     * Get the schema name to use for operations.
     * Returns 'memory' for backward compatibility if no memory database is set.
     */
    protected function getSchemaName(): string
    {
        return $this->memoryDatabase?->getFullSchemaName() ?? 'memory';
    }

    /**
     * Get the schema prefix for filenames.
     * Returns empty string for 'memory' (backward compatibility), otherwise schema name.
     */
    protected function getSchemaPrefix(): string
    {
        $schemaName = $this->getSchemaName();
        return $schemaName === 'memory' ? '' : $schemaName . '_';
    }

    /**
     * Create a snapshot of the memory schema.
     *
     * @param bool $schemaOnly If true, only dump schema (no data)
     * @return array{success: bool, filename: ?string, path: ?string, message: string}
     */
    public function create(bool $schemaOnly = false): array
    {
        $this->ensureDirectory();

        $schemaName = $this->getSchemaName();
        $schemaPrefix = $this->getSchemaPrefix();
        $timestamp = now()->format('Ymd_His');
        $suffix = $schemaOnly ? '_schema' : '';
        $filename = "{$schemaPrefix}memory_{$timestamp}{$suffix}.sql";
        $path = $this->directory . '/' . $filename;

        // Build pg_dump command
        $dbHost = config('database.connections.pgsql.host');
        $dbPort = config('database.connections.pgsql.port');
        $dbName = config('database.connections.pgsql.database');
        $dbUser = config('database.connections.pgsql.username');
        $dbPassword = config('database.connections.pgsql.password');

        $command = [
            'pg_dump',
            '-h', $dbHost,
            '-p', (string) $dbPort,
            '-U', $dbUser,
            '-d', $dbName,
            '-n', $schemaName,  // Use dynamic schema name
            '-f', $path,
        ];

        if ($schemaOnly) {
            $command[] = '--schema-only';
        }

        try {
            $result = Process::env(['PGPASSWORD' => $dbPassword])
                ->timeout(300) // 5 minute timeout
                ->run($command);

            if (!$result->successful()) {
                Log::error('pg_dump failed', [
                    'output' => $result->output(),
                    'error' => $result->errorOutput(),
                ]);

                return [
                    'success' => false,
                    'filename' => null,
                    'path' => null,
                    'message' => 'pg_dump failed: ' . $result->errorOutput(),
                ];
            }

            Log::info('Memory snapshot created', [
                'filename' => $filename,
                'schema' => $schemaName,
                'schema_only' => $schemaOnly,
            ]);

            return [
                'success' => true,
                'filename' => $filename,
                'path' => $path,
                'message' => "Snapshot created: {$filename}",
            ];
        } catch (\Exception $e) {
            Log::error('Snapshot creation failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'filename' => null,
                'path' => null,
                'message' => 'Snapshot creation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * List all available snapshots with metadata.
     *
     * @return array<array{filename: string, path: string, created_at: string, size: int, schema_only: bool, tier: string}>
     */
    public function list(): array
    {
        $this->ensureDirectory();

        $schemaPrefix = $this->getSchemaPrefix();
        $pattern = $this->directory . '/' . $schemaPrefix . 'memory_*.sql';
        $files = glob($pattern) ?: [];
        $snapshots = [];

        foreach ($files as $path) {
            $filename = basename($path);
            $info = $this->parseFilename($filename);

            if (!$info) {
                continue;
            }

            $snapshots[] = [
                'filename' => $filename,
                'path' => $path,
                'created_at' => $info['created_at']->toIso8601String(),
                'size' => filesize($path) ?: 0,
                'schema_only' => $info['schema_only'],
                'tier' => $this->getTier($info['created_at']),
            ];
        }

        // Sort by creation date descending
        usort($snapshots, fn($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $snapshots;
    }

    /**
     * Validate filename to prevent path traversal attacks.
     *
     * @param string $filename Filename to validate
     * @return bool True if filename is safe
     */
    protected function isValidFilename(string $filename): bool
    {
        // Reject empty filenames
        if (empty($filename)) {
            return false;
        }

        // Reject path traversal attempts
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        // Must match expected pattern: [schema_prefix_]memory_YYYYMMDD_HHMMSS[_schema][_imported].sql
        // Schema prefix is optional for backward compatibility (e.g., memory_default_memory_*.sql)
        if (!preg_match('/^(memory_[a-z][a-z0-9_]*_)?memory_\d{8}_\d{6}(_schema)?(_imported)?\.sql$/', $filename)) {
            return false;
        }

        return true;
    }

    /**
     * Restore the memory schema from a snapshot.
     * Automatically creates a snapshot of current state first.
     *
     * @param string $filename Snapshot filename to restore
     * @return array{success: bool, message: string, backup_filename?: string}
     */
    public function restore(string $filename): array
    {
        // Validate filename to prevent path traversal
        if (!$this->isValidFilename($filename)) {
            return [
                'success' => false,
                'message' => 'Invalid filename. Must be a valid snapshot filename.',
            ];
        }

        $path = $this->directory . '/' . $filename;

        if (!file_exists($path)) {
            return [
                'success' => false,
                'message' => "Snapshot not found: {$filename}",
            ];
        }

        // Create backup of current state first
        $backupResult = $this->create();
        if (!$backupResult['success']) {
            return [
                'success' => false,
                'message' => 'Failed to create backup before restore: ' . $backupResult['message'],
            ];
        }

        $schemaName = $this->getSchemaName();

        $dbHost = config('database.connections.pgsql.host');
        $dbPort = config('database.connections.pgsql.port');
        $dbName = config('database.connections.pgsql.database');
        $dbUser = config('database.connections.pgsql.username');
        $dbPassword = config('database.connections.pgsql.password');

        try {
            // Drop and recreate the schema
            $dropResult = Process::env(['PGPASSWORD' => $dbPassword])
                ->timeout(60)
                ->run([
                    'psql',
                    '-h', $dbHost,
                    '-p', (string) $dbPort,
                    '-U', $dbUser,
                    '-d', $dbName,
                    '-c', "DROP SCHEMA IF EXISTS {$schemaName} CASCADE; CREATE SCHEMA {$schemaName};",
                ]);

            if (!$dropResult->successful()) {
                Log::error('Failed to reset memory schema', [
                    'schema' => $schemaName,
                    'error' => $dropResult->errorOutput(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to reset schema: ' . $dropResult->errorOutput(),
                    'backup_filename' => $backupResult['filename'],
                ];
            }

            // Restore from snapshot
            $restoreResult = Process::env(['PGPASSWORD' => $dbPassword])
                ->timeout(300)
                ->run([
                    'psql',
                    '-h', $dbHost,
                    '-p', (string) $dbPort,
                    '-U', $dbUser,
                    '-d', $dbName,
                    '-f', $path,
                ]);

            if (!$restoreResult->successful()) {
                Log::error('Failed to restore snapshot', [
                    'error' => $restoreResult->errorOutput(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Restore failed: ' . $restoreResult->errorOutput(),
                    'backup_filename' => $backupResult['filename'],
                ];
            }

            Log::info('Memory schema restored', [
                'snapshot' => $filename,
                'schema' => $schemaName,
                'backup' => $backupResult['filename'],
            ]);

            return [
                'success' => true,
                'message' => "Restored from {$filename}. Previous state backed up to {$backupResult['filename']}",
                'backup_filename' => $backupResult['filename'],
            ];
        } catch (\Exception $e) {
            Log::error('Restore failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Restore failed: ' . $e->getMessage(),
                'backup_filename' => $backupResult['filename'],
            ];
        }
    }

    /**
     * Delete a specific snapshot.
     *
     * @param string $filename Snapshot filename to delete
     * @return array{success: bool, message: string}
     */
    public function delete(string $filename): array
    {
        // Validate filename to prevent path traversal
        if (!$this->isValidFilename($filename)) {
            return [
                'success' => false,
                'message' => 'Invalid filename. Must be a valid snapshot filename.',
            ];
        }

        $path = $this->directory . '/' . $filename;

        if (!file_exists($path)) {
            return [
                'success' => false,
                'message' => "Snapshot not found: {$filename}",
            ];
        }

        if (!unlink($path)) {
            return [
                'success' => false,
                'message' => "Failed to delete snapshot: {$filename}",
            ];
        }

        Log::info('Snapshot deleted', ['filename' => $filename]);

        return [
            'success' => true,
            'message' => "Deleted snapshot: {$filename}",
        ];
    }

    /**
     * Apply tiered retention policy to prune old snapshots.
     *
     * Retention tiers:
     * - 0-24 hours: Keep all hourly snapshots
     * - 1-7 days: Keep 4 per day (00:00, 06:00, 12:00, 18:00)
     * - 7-30 days: Keep 1 per day (00:00)
     * - >30 days: Delete all
     *
     * @return array{success: bool, deleted: int, message: string}
     */
    public function prune(): array
    {
        $snapshots = $this->list();
        $now = now();
        $deleted = 0;

        // Group snapshots by date for tiered retention
        $byDate = [];
        foreach ($snapshots as $snapshot) {
            $createdAt = Carbon::parse($snapshot['created_at']);
            $date = $createdAt->format('Y-m-d');
            $hour = (int) $createdAt->format('H');

            if (!isset($byDate[$date])) {
                $byDate[$date] = [];
            }

            $byDate[$date][] = [
                'snapshot' => $snapshot,
                'hour' => $hour,
                'created_at' => $createdAt,
            ];
        }

        foreach ($byDate as $date => $daySnapshots) {
            $dateCarbon = Carbon::parse($date);
            $daysAgo = $now->diffInDays($dateCarbon);

            // Sort by hour ascending
            usort($daySnapshots, fn($a, $b) => $a['hour'] <=> $b['hour']);

            if ($daysAgo > $this->retentionDays) {
                // Beyond retention - delete all
                foreach ($daySnapshots as $item) {
                    $this->delete($item['snapshot']['filename']);
                    $deleted++;
                }
            } elseif ($daysAgo >= 7) {
                // 7-30 days: Keep only 1 per day (closest to midnight, prefer hour < 6)
                // First, try to find one before 6 AM
                $keptIndex = null;
                foreach ($daySnapshots as $index => $item) {
                    if ($item['hour'] < 6) {
                        $keptIndex = $index;
                        break; // Keep the earliest one before 6 AM
                    }
                }

                // If none before 6 AM, keep the first (earliest) snapshot of the day
                if ($keptIndex === null && !empty($daySnapshots)) {
                    $keptIndex = 0;
                }

                // Delete all except the kept one
                foreach ($daySnapshots as $index => $item) {
                    if ($index !== $keptIndex) {
                        $this->delete($item['snapshot']['filename']);
                        $deleted++;
                    }
                }
            } elseif ($daysAgo >= 1) {
                // 1-7 days: Keep 4 per day (00, 06, 12, 18)
                $targetHours = [0, 6, 12, 18];
                $keptHours = [];

                foreach ($daySnapshots as $item) {
                    // Find closest target hour
                    $closestTarget = null;
                    $closestDiff = 24;
                    foreach ($targetHours as $target) {
                        $diff = abs($item['hour'] - $target);
                        if ($diff < $closestDiff && !in_array($target, $keptHours)) {
                            $closestDiff = $diff;
                            $closestTarget = $target;
                        }
                    }

                    if ($closestTarget !== null && $closestDiff <= 3) {
                        $keptHours[] = $closestTarget;
                    } else {
                        $this->delete($item['snapshot']['filename']);
                        $deleted++;
                    }
                }
            }
            // 0-24 hours: Keep all
        }

        Log::info('Snapshot pruning complete', ['deleted' => $deleted]);

        return [
            'success' => true,
            'deleted' => $deleted,
            'message' => "Pruned {$deleted} snapshot(s)",
        ];
    }

    /**
     * Get snapshot file for download (export).
     *
     * @param string $filename Snapshot filename
     * @return array{success: bool, path?: string, message: string}
     */
    public function getForDownload(string $filename): array
    {
        // Validate filename to prevent path traversal
        if (!$this->isValidFilename($filename)) {
            return [
                'success' => false,
                'message' => 'Invalid filename. Must be a valid snapshot filename.',
            ];
        }

        $path = $this->directory . '/' . $filename;

        if (!file_exists($path)) {
            return [
                'success' => false,
                'message' => "Snapshot not found: {$filename}",
            ];
        }

        return [
            'success' => true,
            'path' => $path,
            'message' => 'File ready for download',
        ];
    }

    /**
     * Import a snapshot from uploaded file.
     *
     * @param string $uploadedPath Path to the uploaded SQL file
     * @return array{success: bool, filename?: string, message: string}
     */
    public function import(string $uploadedPath): array
    {
        $this->ensureDirectory();

        // Generate new filename with current timestamp and schema prefix
        $schemaPrefix = $this->getSchemaPrefix();
        $timestamp = now()->format('Ymd_His');
        $filename = "{$schemaPrefix}memory_{$timestamp}_imported.sql";
        $destinationPath = $this->directory . '/' . $filename;

        // Move file to snapshots directory
        if (!rename($uploadedPath, $destinationPath)) {
            return [
                'success' => false,
                'message' => 'Failed to move uploaded file to snapshots directory',
            ];
        }

        Log::info('Snapshot imported', ['filename' => $filename]);

        return [
            'success' => true,
            'filename' => $filename,
            'message' => "Imported as {$filename}. Use restore to apply it.",
        ];
    }

    /**
     * Get retention days setting.
     */
    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    /**
     * Set retention days setting.
     */
    public function setRetentionDays(int $days): void
    {
        $this->retentionDays = max(1, min(365, $days));
        $this->settings->set('memory_snapshot_retention_days', $this->retentionDays);
    }

    /**
     * Ensure snapshot directory exists.
     */
    protected function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    /**
     * Parse snapshot filename to extract metadata.
     *
     * @return array{created_at: Carbon, schema_only: bool}|null
     */
    protected function parseFilename(string $filename): ?array
    {
        // Expected format: [schema_prefix_]memory_YYYYMMDD_HHMMSS[_schema][_imported].sql
        // Schema prefix is optional for backward compatibility
        if (!preg_match('/^(?:memory_[a-z][a-z0-9_]*_)?memory_(\d{8})_(\d{6})(_schema)?(_imported)?\.sql$/', $filename, $matches)) {
            return null;
        }

        $date = $matches[1];
        $time = $matches[2];
        $schemaOnly = isset($matches[3]) && $matches[3] === '_schema';

        try {
            $createdAt = Carbon::createFromFormat('Ymd_His', "{$date}_{$time}");
            return [
                'created_at' => $createdAt,
                'schema_only' => $schemaOnly,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Determine which retention tier a snapshot belongs to.
     */
    protected function getTier(Carbon $createdAt): string
    {
        $hoursAgo = now()->diffInHours($createdAt);

        if ($hoursAgo < 24) {
            return 'hourly';
        } elseif ($hoursAgo < 24 * 7) {
            return 'daily-4';
        } elseif ($hoursAgo < 24 * $this->retentionDays) {
            return 'daily';
        } else {
            return 'expired';
        }
    }

    /**
     * Detect the source schema name from a SQL dump file.
     * Looks for CREATE SCHEMA, SET search_path, or qualified table names.
     *
     * @param string $filePath Path to the SQL file
     * @return array{success: bool, schema_name: ?string, message: string}
     */
    public function detectSourceSchema(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'schema_name' => null,
                'message' => 'File not found',
            ];
        }

        // Read first 100KB of file (schema info is always near the top)
        $content = file_get_contents($filePath, false, null, 0, 102400);

        if ($content === false) {
            return [
                'success' => false,
                'schema_name' => null,
                'message' => 'Failed to read file',
            ];
        }

        // Pattern 1: CREATE SCHEMA memory_xxx
        if (preg_match('/CREATE SCHEMA\s+(?:IF NOT EXISTS\s+)?(memory_[a-z][a-z0-9_]*)/i', $content, $matches)) {
            return [
                'success' => true,
                'schema_name' => strtolower($matches[1]),
                'message' => 'Schema detected from CREATE SCHEMA statement',
            ];
        }

        // Pattern 2: SET search_path = memory_xxx
        if (preg_match('/SET\s+search_path\s*=\s*(memory_[a-z][a-z0-9_]*)/i', $content, $matches)) {
            return [
                'success' => true,
                'schema_name' => strtolower($matches[1]),
                'message' => 'Schema detected from search_path',
            ];
        }

        // Pattern 3: memory_xxx.table_name in any statement
        if (preg_match('/(memory_[a-z][a-z0-9_]*)\./', $content, $matches)) {
            return [
                'success' => true,
                'schema_name' => strtolower($matches[1]),
                'message' => 'Schema detected from qualified table name',
            ];
        }

        return [
            'success' => false,
            'schema_name' => null,
            'message' => 'Could not detect schema name from SQL file',
        ];
    }

    /**
     * Import a snapshot file and store it for configuration.
     * Returns information needed for the configuration step.
     *
     * @param string $uploadedPath Path to the uploaded SQL file
     * @return array{success: bool, filename?: string, detected_schema?: string, message: string}
     */
    public function importForConfiguration(string $uploadedPath): array
    {
        $this->ensureDirectory();

        // Generate new filename with timestamp and random suffix to prevent collisions
        $timestamp = now()->format('Ymd_His');
        $randomSuffix = bin2hex(random_bytes(4));
        $filename = "pending_import_{$timestamp}_{$randomSuffix}.sql";
        $destinationPath = $this->directory . '/' . $filename;

        // Copy file (not move, since temp file may be needed)
        if (!copy($uploadedPath, $destinationPath)) {
            return [
                'success' => false,
                'message' => 'Failed to copy uploaded file to snapshots directory',
            ];
        }

        // Detect source schema
        $detection = $this->detectSourceSchema($destinationPath);

        Log::info('Snapshot uploaded for configuration', [
            'filename' => $filename,
            'detected_schema' => $detection['schema_name'],
        ]);

        return [
            'success' => true,
            'filename' => $filename,
            'detected_schema' => $detection['schema_name'],
            'message' => 'File uploaded. Configure import options.',
        ];
    }

    /**
     * Apply import with schema transformation.
     *
     * @param string $filename The pending import file
     * @param string $sourceSchema Original schema name in file
     * @param string $targetSchema Target schema name
     * @param bool $overwrite Whether to overwrite existing schema
     * @return array{success: bool, message: string, backup_filename?: string}
     */
    public function applyImportWithTransform(
        string $filename,
        string $sourceSchema,
        string $targetSchema,
        bool $overwrite = false
    ): array {
        // Validate filename format (supports both old and new format with random suffix)
        if (!preg_match('/^pending_import_\d{8}_\d{6}(_[a-f0-9]{8})?\.sql$/', $filename)) {
            return ['success' => false, 'message' => 'Invalid import file format'];
        }

        $path = $this->directory . '/' . $filename;
        if (!file_exists($path)) {
            return ['success' => false, 'message' => 'Import file not found'];
        }

        // Validate schema names
        if (!preg_match('/^memory_[a-z][a-z0-9_]*$/', $sourceSchema)) {
            return ['success' => false, 'message' => 'Invalid source schema name format'];
        }
        if (!preg_match('/^memory_[a-z][a-z0-9_]*$/', $targetSchema)) {
            return ['success' => false, 'message' => 'Invalid target schema name format'];
        }

        $dbHost = config('database.connections.pgsql.host');
        $dbPort = config('database.connections.pgsql.port');
        $dbName = config('database.connections.pgsql.database');
        $dbUser = config('database.connections.pgsql.username');
        $dbPassword = config('database.connections.pgsql.password');

        // Check if target exists
        $schemaExists = \Illuminate\Support\Facades\DB::connection('pgsql')->selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as exists",
            [$targetSchema]
        )->exists;

        if ($schemaExists && !$overwrite) {
            return [
                'success' => false,
                'message' => "Schema '{$targetSchema}' already exists. Enable overwrite to replace it.",
            ];
        }

        // Create backup if overwriting
        $backupFilename = null;
        if ($schemaExists && $overwrite) {
            $memoryDb = MemoryDatabase::where('schema_name', str_replace('memory_', '', $targetSchema))->first();
            if ($memoryDb) {
                $this->setMemoryDatabase($memoryDb);
                $backupResult = $this->create();
                if ($backupResult['success']) {
                    $backupFilename = $backupResult['filename'];
                }
            }
        }

        try {
            // Stream-transform SQL line by line to handle large files (1GB+)
            $transformedPath = $this->directory . '/transformed_' . $filename;

            $sourceHandle = @fopen($path, 'r');
            if ($sourceHandle === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to open source SQL file for reading',
                    'backup_filename' => $backupFilename,
                ];
            }

            $destHandle = @fopen($transformedPath, 'w');
            if ($destHandle === false) {
                fclose($sourceHandle);
                return [
                    'success' => false,
                    'message' => 'Failed to create transformed SQL file',
                    'backup_filename' => $backupFilename,
                ];
            }

            // Prepare transformation patterns if schemas differ
            $needsTransform = $sourceSchema !== $targetSchema;
            $sourceSchemaShort = str_replace('memory_', '', $sourceSchema);
            $targetSchemaShort = str_replace('memory_', '', $targetSchema);

            // Stream line by line
            while (($line = fgets($sourceHandle)) !== false) {
                if ($needsTransform) {
                    // Replace qualified names: source_schema.table -> target_schema.table
                    $line = str_replace($sourceSchema . '.', $targetSchema . '.', $line);

                    // Replace CREATE SCHEMA statements (preserve IF NOT EXISTS)
                    $line = preg_replace(
                        '/CREATE SCHEMA(\s+IF NOT EXISTS)?\s+' . preg_quote($sourceSchema, '/') . '(\s|;)/i',
                        'CREATE SCHEMA$1 ' . $targetSchema . '$2',
                        $line
                    );

                    // Replace SET search_path statements
                    $line = preg_replace(
                        '/SET\s+search_path\s*=\s*' . preg_quote($sourceSchema, '/') . '/i',
                        'SET search_path = ' . $targetSchema,
                        $line
                    );

                    // Replace GRANT/REVOKE ON SCHEMA statements
                    $line = preg_replace(
                        '/ON SCHEMA\s+' . preg_quote($sourceSchema, '/') . '/i',
                        'ON SCHEMA ' . $targetSchema,
                        $line
                    );

                    // Replace IN SCHEMA statements (for default privileges)
                    $line = preg_replace(
                        '/IN SCHEMA\s+' . preg_quote($sourceSchema, '/') . '/i',
                        'IN SCHEMA ' . $targetSchema,
                        $line
                    );

                    // Replace index names that include schema prefix: idx_schemaname_
                    $line = str_replace('idx_' . $sourceSchemaShort . '_', 'idx_' . $targetSchemaShort . '_', $line);
                }

                if (fwrite($destHandle, $line) === false) {
                    fclose($sourceHandle);
                    fclose($destHandle);
                    @unlink($transformedPath);
                    return [
                        'success' => false,
                        'message' => 'Failed to write to transformed SQL file',
                        'backup_filename' => $backupFilename,
                    ];
                }
            }

            fclose($sourceHandle);
            fclose($destHandle);

            // Drop existing schema if overwriting
            if ($schemaExists) {
                $dropResult = Process::env(['PGPASSWORD' => $dbPassword])
                    ->timeout(60)
                    ->run([
                        'psql',
                        '-h', $dbHost,
                        '-p', (string) $dbPort,
                        '-U', $dbUser,
                        '-d', $dbName,
                        '-c', "DROP SCHEMA IF EXISTS {$targetSchema} CASCADE;",
                    ]);

                if (!$dropResult->successful()) {
                    @unlink($transformedPath);
                    return [
                        'success' => false,
                        'message' => 'Failed to drop existing schema: ' . $dropResult->errorOutput(),
                        'backup_filename' => $backupFilename,
                    ];
                }
            }

            // Execute transformed SQL with ON_ERROR_STOP to fail fast on errors
            $restoreResult = Process::env(['PGPASSWORD' => $dbPassword])
                ->timeout(300)
                ->run([
                    'psql',
                    '-h', $dbHost,
                    '-p', (string) $dbPort,
                    '-U', $dbUser,
                    '-d', $dbName,
                    '-v', 'ON_ERROR_STOP=1',
                    '-f', $transformedPath,
                ]);

            // Clean up transformed file (always)
            @unlink($transformedPath);

            if (!$restoreResult->successful()) {
                // Keep pending file for retry - don't delete $path here
                return [
                    'success' => false,
                    'message' => 'Import failed: ' . $restoreResult->errorOutput(),
                    'backup_filename' => $backupFilename,
                ];
            }

            // Only delete pending file on success
            @unlink($path);

            // Ensure MemoryDatabase record exists for the target schema
            $targetSchemaShortName = str_replace('memory_', '', $targetSchema);
            $memoryDb = MemoryDatabase::where('schema_name', $targetSchemaShortName)->first();
            if (!$memoryDb) {
                // Check if there's a soft-deleted record we can restore
                $trashedDb = MemoryDatabase::withTrashed()
                    ->where('schema_name', $targetSchemaShortName)
                    ->whereNotNull('deleted_at')
                    ->first();

                if ($trashedDb) {
                    $trashedDb->restore();
                    $trashedDb->update(['description' => 'Restored via import from ' . $sourceSchema]);
                    $memoryDb = $trashedDb;
                    Log::info('Restored soft-deleted MemoryDatabase record for imported schema', [
                        'schema_name' => $targetSchemaShortName,
                        'memory_database_id' => $memoryDb->id,
                    ]);
                } else {
                    $memoryDb = MemoryDatabase::create([
                        'name' => ucfirst(str_replace('_', ' ', $targetSchemaShortName)),
                        'schema_name' => $targetSchemaShortName,
                        'description' => 'Imported from ' . $sourceSchema,
                    ]);
                    Log::info('Created MemoryDatabase record for imported schema', [
                        'schema_name' => $targetSchemaShortName,
                        'memory_database_id' => $memoryDb->id,
                    ]);
                }
            }

            Log::info('Schema imported with transformation', [
                'source_schema' => $sourceSchema,
                'target_schema' => $targetSchema,
                'overwrite' => $overwrite,
            ]);

            $message = "Successfully imported to schema '{$targetSchema}'";
            if ($backupFilename) {
                $message .= ". Previous state backed up to {$backupFilename}";
            }

            return [
                'success' => true,
                'message' => $message,
                'backup_filename' => $backupFilename,
            ];
        } catch (\Exception $e) {
            // Clean up transformed file if it exists
            if (isset($transformedPath) && file_exists($transformedPath)) {
                @unlink($transformedPath);
            }

            Log::error('Import failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'backup_filename' => $backupFilename,
            ];
        }
    }

    /**
     * Delete a pending import file.
     */
    public function deletePendingImport(string $filename): bool
    {
        // Supports both old and new format with random suffix
        if (!preg_match('/^pending_import_\d{8}_\d{6}(_[a-f0-9]{8})?\.sql$/', $filename)) {
            return false;
        }

        $path = $this->directory . '/' . $filename;
        if (file_exists($path)) {
            return @unlink($path);
        }
        return true;
    }
}
