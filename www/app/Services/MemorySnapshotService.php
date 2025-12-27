<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Service for managing memory schema snapshots using pg_dump/pg_restore.
 * Implements tiered retention: hourly (24h) → 4/day (7d) → 1/day (30d).
 */
class MemorySnapshotService
{
    protected string $directory;
    protected int $retentionDays;

    public function __construct(
        protected AppSettingsService $settings
    ) {
        $this->directory = config('memory.snapshot_directory', storage_path('memory-snapshots'));
        $this->retentionDays = $this->settings->get('memory_snapshot_retention_days', config('memory.snapshot_retention_days', 30));
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

        $timestamp = now()->format('Ymd_His');
        $suffix = $schemaOnly ? '_schema' : '';
        $filename = "memory_{$timestamp}{$suffix}.sql";
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
            '-n', 'memory',  // Only memory schema
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

        $files = glob($this->directory . '/memory_*.sql') ?: [];
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

        // Must match expected pattern: memory_YYYYMMDD_HHMMSS[_schema][_imported].sql
        if (!preg_match('/^memory_\d{8}_\d{6}(_schema)?(_imported)?\.sql$/', $filename)) {
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

        $dbHost = config('database.connections.pgsql.host');
        $dbPort = config('database.connections.pgsql.port');
        $dbName = config('database.connections.pgsql.database');
        $dbUser = config('database.connections.pgsql.username');
        $dbPassword = config('database.connections.pgsql.password');

        try {
            // Drop and recreate memory schema
            $dropResult = Process::env(['PGPASSWORD' => $dbPassword])
                ->timeout(60)
                ->run([
                    'psql',
                    '-h', $dbHost,
                    '-p', (string) $dbPort,
                    '-U', $dbUser,
                    '-d', $dbName,
                    '-c', 'DROP SCHEMA IF EXISTS memory CASCADE; CREATE SCHEMA memory;',
                ]);

            if (!$dropResult->successful()) {
                Log::error('Failed to reset memory schema', [
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

        // Generate new filename with current timestamp
        $timestamp = now()->format('Ymd_His');
        $filename = "memory_{$timestamp}_imported.sql";
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
        // Expected format: memory_YYYYMMDD_HHMMSS.sql or memory_YYYYMMDD_HHMMSS_schema.sql
        if (!preg_match('/^memory_(\d{8})_(\d{6})(_schema)?(_imported)?\.sql$/', $filename, $matches)) {
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
}
