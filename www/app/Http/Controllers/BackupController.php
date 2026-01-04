<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupController extends Controller
{
    /**
     * Directory where backups are stored
     */
    protected string $backupDir = 'backups';

    /**
     * Volumes to backup (excluding postgres - we use pg_dump instead)
     */
    protected array $volumes = [
        'pocket-dev-redis',
        'pocket-dev-workspace',
        'pocket-dev-user',
        'pocket-dev-proxy-config',
        'pocket-dev-storage',
    ];

    /**
     * Show the backup management page
     */
    public function show(Request $request)
    {
        $request->session()->put('config_last_section', 'backup');

        // Get existing backups
        $backups = $this->listBackups();

        // Check if user has downloaded a backup within the last 5 minutes (safety requirement before restore)
        $downloadedAt = $request->session()->get('backup_downloaded_at');
        $hasDownloadedBackup = $downloadedAt && (time() - $downloadedAt) < 300;

        // Check for processing conversations
        $processingCount = \App\Models\Conversation::where('status', \App\Models\Conversation::STATUS_PROCESSING)->count();

        return view('config.backup', [
            'backups' => $backups,
            'hasDownloadedBackup' => $hasDownloadedBackup,
            'processingCount' => $processingCount,
        ]);
    }

    /**
     * Create a new backup
     */
    public function create(Request $request)
    {
        try {
            $timestamp = now()->format('Y-m-d_H-i-s');
            $backupName = "pocketdev-backup-{$timestamp}";
            $tempDir = "/tmp/{$backupName}";

            // Ensure backup directory exists
            $backupDir = storage_path("app/{$this->backupDir}");
            if (!is_dir($backupDir)) {
                if (!mkdir($backupDir, 0775, true)) {
                    throw new \RuntimeException("Failed to create backup directory: {$backupDir}");
                }
            }

            // Create temp directory for backup contents
            exec("mkdir -p {$tempDir}", $output, $returnCode);
            if ($returnCode !== 0) {
                throw new \RuntimeException('Failed to create temp directory');
            }

            // 1. Database dump using pg_dump
            $this->backupDatabase($tempDir);

            // 2. Backup volumes
            $this->backupVolumes($tempDir);

            // 3. Create manifest file
            $this->createManifest($tempDir, $backupName);

            // 4. Create the final archive
            $backupPath = storage_path("app/{$this->backupDir}/{$backupName}.tar.gz");
            exec("tar -czf \"{$backupPath}\" -C /tmp \"{$backupName}\" 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                throw new \RuntimeException('Failed to create backup archive: ' . implode("\n", $output));
            }

            // 5. Cleanup temp directory
            exec("rm -rf \"{$tempDir}\"");

            // Get file size for display
            $fileSize = $this->formatFileSize(filesize($backupPath));

            return redirect()->route('config.backup')
                ->with('success', "Backup created successfully: {$backupName}.tar.gz ({$fileSize})");

        } catch (\Exception $e) {
            Log::error('Backup creation failed', ['error' => $e->getMessage()]);
            // Cleanup on failure
            if (isset($tempDir)) {
                exec("rm -rf \"{$tempDir}\"");
            }
            return redirect()->route('config.backup')
                ->with('error', 'Backup failed: ' . $e->getMessage());
        }
    }

    /**
     * Download a backup file
     */
    public function download(Request $request, string $filename)
    {
        $path = storage_path("app/{$this->backupDir}/{$filename}");

        if (!file_exists($path)) {
            return redirect()->route('config.backup')
                ->with('error', 'Backup file not found');
        }

        // Store download timestamp - restore is allowed within 5 minutes of downloading
        $request->session()->put('backup_downloaded_at', time());

        return response()->download($path);
    }

    /**
     * Delete a backup file
     */
    public function delete(Request $request, string $filename)
    {
        $path = storage_path("app/{$this->backupDir}/{$filename}");

        if (!file_exists($path)) {
            return redirect()->route('config.backup')
                ->with('error', 'Backup file not found');
        }

        // Security: only allow .tar.gz files in our backup directory
        if (!str_ends_with($filename, '.tar.gz') || str_contains($filename, '..')) {
            return redirect()->route('config.backup')
                ->with('error', 'Invalid backup filename');
        }

        unlink($path);

        return redirect()->route('config.backup')
            ->with('success', 'Backup deleted successfully');
    }

    /**
     * Restore from an uploaded backup
     */
    public function restore(Request $request)
    {
        // Safety check: require a backup download within the last 5 minutes
        $downloadedAt = $request->session()->get('backup_downloaded_at');
        if (!$downloadedAt || (time() - $downloadedAt) >= 300) {
            return redirect()->route('config.backup')
                ->with('error', 'You must download a backup first before restoring. This ensures you have a safety copy. (Download expires after 5 minutes)');
        }

        $request->validate([
            'backup_file' => 'required|file|mimes:gz|max:1048576', // 1GB max
        ]);

        try {
            $file = $request->file('backup_file');
            $tempDir = '/tmp/pocketdev-restore-' . uniqid();

            // Create temp directory
            exec("mkdir -p {$tempDir}", $output, $returnCode);
            if ($returnCode !== 0) {
                throw new \RuntimeException('Failed to create temp directory');
            }

            // Move uploaded file to temp location
            $uploadedPath = "{$tempDir}/backup.tar.gz";
            $file->move($tempDir, 'backup.tar.gz');

            // Extract archive
            exec("tar -xzf \"{$uploadedPath}\" -C \"{$tempDir}\" 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                throw new \RuntimeException('Failed to extract backup: ' . implode("\n", $output));
            }

            // Find the extracted backup directory
            $extractedDirs = glob("{$tempDir}/pocketdev-backup-*");
            if (empty($extractedDirs)) {
                throw new \RuntimeException('Invalid backup archive: no backup directory found');
            }
            $backupDir = $extractedDirs[0];

            // Verify manifest exists
            if (!file_exists("{$backupDir}/manifest.json")) {
                throw new \RuntimeException('Invalid backup: manifest.json not found');
            }

            $manifest = json_decode(file_get_contents("{$backupDir}/manifest.json"), true);
            if (!$manifest || !isset($manifest['version'])) {
                throw new \RuntimeException('Invalid backup manifest');
            }

            // 1. Restore database
            $this->restoreDatabase($backupDir);

            // 2. Restore volumes
            $this->restoreVolumes($backupDir);

            // Cleanup
            exec("rm -rf \"{$tempDir}\"");

            // 3. Restart containers to apply changes
            $this->restartContainers();

            return redirect()->route('config.backup')
                ->with('success', 'Backup restored successfully. Containers are restarting.');

        } catch (\Exception $e) {
            Log::error('Backup restore failed', ['error' => $e->getMessage()]);
            if (isset($tempDir)) {
                exec("rm -rf \"{$tempDir}\"");
            }
            return redirect()->route('config.backup')
                ->with('error', 'Restore failed: ' . $e->getMessage());
        }
    }

    /**
     * Backup the database using pg_dump
     */
    protected function backupDatabase(string $tempDir): void
    {
        $dbName = config('database.connections.pgsql.database');
        $dbUser = config('database.connections.pgsql.username');

        $dumpFile = "{$tempDir}/database.sql";

        // First verify docker is accessible
        exec('docker --version 2>&1', $dockerCheck, $dockerReturnCode);
        if ($dockerReturnCode !== 0) {
            throw new \RuntimeException('Docker not accessible: ' . implode("\n", $dockerCheck));
        }

        // Use docker exec to run pg_dump in the postgres container
        // Capture output directly then write to file (more reliable than shell redirection)
        $command = sprintf(
            'docker exec pocket-dev-postgres pg_dump -U %s %s 2>&1',
            escapeshellarg($dbUser),
            escapeshellarg($dbName)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMsg = implode("\n", $output);
            if (empty($errorMsg)) {
                $errorMsg = "pg_dump failed with exit code {$returnCode}";
            }
            throw new \RuntimeException('Database backup failed: ' . $errorMsg);
        }

        // Write output to file
        $dumpContent = implode("\n", $output);
        if (empty($dumpContent)) {
            throw new \RuntimeException('Database dump is empty');
        }

        file_put_contents($dumpFile, $dumpContent);
    }

    /**
     * Backup Docker volumes
     */
    protected function backupVolumes(string $tempDir): void
    {
        foreach ($this->volumes as $volume) {
            $volumeDir = "{$tempDir}/volumes/{$volume}";
            exec("mkdir -p \"{$volumeDir}\"");

            // Use a temporary container to copy volume contents
            $command = sprintf(
                'docker run --rm -v %s:/source -v "%s":/backup alpine sh -c "cd /source && tar -cf - . | (cd /backup && tar -xf -)" 2>&1',
                escapeshellarg($volume),
                $volumeDir
            );

            exec($command, $output, $returnCode);
            if ($returnCode !== 0) {
                Log::warning("Failed to backup volume {$volume}", ['output' => implode("\n", $output)]);
                // Don't fail completely - some volumes might be empty
            }
        }
    }

    /**
     * Create backup manifest
     */
    protected function createManifest(string $tempDir, string $backupName): void
    {
        $manifest = [
            'version' => '1.0',
            'name' => $backupName,
            'created_at' => now()->toIso8601String(),
            'pocketdev_version' => config('app.version', 'unknown'),
            'volumes' => $this->volumes,
            'includes_database' => true,
        ];

        file_put_contents("{$tempDir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * Restore database from backup
     */
    protected function restoreDatabase(string $backupDir): void
    {
        $dumpFile = "{$backupDir}/database.sql";
        if (!file_exists($dumpFile)) {
            throw new \RuntimeException('Database dump not found in backup');
        }

        $dbName = config('database.connections.pgsql.database');
        $dbUser = config('database.connections.pgsql.username');

        // Validate db name/user contain only safe characters (alphanumeric, underscore, hyphen)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $dbName) || !preg_match('/^[a-zA-Z0-9_-]+$/', $dbUser)) {
            throw new \RuntimeException('Invalid database name or username');
        }

        // Drop and recreate the database, then restore
        // First terminate all connections
        $terminateCmd = sprintf(
            'docker exec pocket-dev-postgres psql -U %s -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = \'%s\' AND pid <> pg_backend_pid();" 2>&1',
            $dbUser,
            $dbName
        );
        exec($terminateCmd);

        // Drop the database
        $dropCmd = sprintf(
            'docker exec pocket-dev-postgres psql -U %s -d postgres -c "DROP DATABASE IF EXISTS \\"%s\\";" 2>&1',
            $dbUser,
            $dbName
        );
        exec($dropCmd, $output, $returnCode);

        // Create fresh database
        $createCmd = sprintf(
            'docker exec pocket-dev-postgres psql -U %s -d postgres -c "CREATE DATABASE \\"%s\\" OWNER \\"%s\\";" 2>&1',
            $dbUser,
            $dbName,
            $dbUser
        );
        exec($createCmd, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to create database: ' . implode("\n", $output));
        }

        // Restore the dump
        $restoreCmd = sprintf(
            'docker exec -i pocket-dev-postgres psql -U %s %s < "%s" 2>&1',
            $dbUser,
            $dbName,
            $dumpFile
        );
        exec($restoreCmd, $output, $returnCode);
        if ($returnCode !== 0) {
            Log::warning('Database restore had warnings', ['output' => implode("\n", $output)]);
            // Don't fail on warnings - pg_restore often has non-fatal warnings
        }
    }

    /**
     * Restore Docker volumes from backup
     */
    protected function restoreVolumes(string $backupDir): void
    {
        $volumesDir = "{$backupDir}/volumes";
        if (!is_dir($volumesDir)) {
            Log::warning('No volumes directory in backup');
            return;
        }

        foreach ($this->volumes as $volume) {
            $volumeBackup = "{$volumesDir}/{$volume}";
            if (!is_dir($volumeBackup)) {
                Log::warning("Volume backup not found: {$volume}");
                continue;
            }

            // Use a temporary container to restore volume contents
            $command = sprintf(
                'docker run --rm -v %s:/target -v "%s":/backup alpine sh -c "rm -rf /target/* /target/.[!.]* 2>/dev/null; cd /backup && tar -cf - . | (cd /target && tar -xf -)" 2>&1',
                escapeshellarg($volume),
                $volumeBackup
            );

            exec($command, $output, $returnCode);
            if ($returnCode !== 0) {
                Log::warning("Failed to restore volume {$volume}", ['output' => implode("\n", $output)]);
            }
        }

        // Fix ownership after restore - Alpine restores as root, but services need proper ownership
        $this->fixVolumePermissions();
    }

    /**
     * Fix volume permissions after restore
     */
    protected function fixVolumePermissions(): void
    {
        // Get the host user/group IDs from environment (containers run as this user, not www-data)
        $userId = env('USER_ID');
        $groupId = env('GROUP_ID');

        if (!$userId || !$groupId) {
            throw new \RuntimeException('USER_ID and GROUP_ID must be set in .env for volume permission fixes');
        }

        // user-data: needs host user ownership for CLI tools (Claude, Codex, etc.)
        exec("docker run --rm -v pocket-dev-user:/data alpine chown -R {$userId}:{$groupId} /data 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            Log::warning('Failed to fix pocket-dev-user permissions', ['output' => implode("\n", $output)]);
        }

        // storage: needs host user ownership for Laravel
        exec("docker run --rm -v pocket-dev-storage:/data alpine chown -R {$userId}:{$groupId} /data 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            Log::warning('Failed to fix pocket-dev-storage permissions', ['output' => implode("\n", $output)]);
        }

        // workspace: needs host user ownership
        exec("docker run --rm -v pocket-dev-workspace:/data alpine chown -R {$userId}:{$groupId} /data 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            Log::warning('Failed to fix pocket-dev-workspace permissions', ['output' => implode("\n", $output)]);
        }

        // proxy-config: needs to be writable by host user group
        exec("docker run --rm -v pocket-dev-proxy-config:/data alpine sh -c \"chown -R root:{$groupId} /data && chmod -R 775 /data\" 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            Log::warning('Failed to fix pocket-dev-proxy-config permissions', ['output' => implode("\n", $output)]);
        }

        // redis: needs redis user (999:999)
        exec('docker run --rm -v pocket-dev-redis:/data alpine chown -R 999:999 /data 2>&1', $output, $returnCode);
        if ($returnCode !== 0) {
            Log::warning('Failed to fix pocket-dev-redis permissions', ['output' => implode("\n", $output)]);
        }
    }

    /**
     * Restart containers after restore
     */
    protected function restartContainers(): void
    {
        $hostProjectPath = env('HOST_PROJECT_PATH');

        if (empty($hostProjectPath)) {
            Log::warning('HOST_PROJECT_PATH not set, skipping container restart');
            return;
        }

        // Spawn a helper container that survives the PHP container restart
        $command = sprintf(
            'docker run --rm -d ' .
            '-v /var/run/docker.sock:/var/run/docker.sock ' .
            '-v "%s:%s" ' .
            '-w "%s" ' .
            'docker:27-cli ' .
            'docker compose up -d --force-recreate 2>&1',
            $hostProjectPath,
            $hostProjectPath,
            $hostProjectPath
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            Log::warning('Failed to spawn restart container', ['output' => implode("\n", $output)]);
        }
    }

    /**
     * List existing backups
     */
    protected function listBackups(): array
    {
        $backups = [];
        $backupPath = storage_path("app/{$this->backupDir}");

        if (!is_dir($backupPath)) {
            return $backups;
        }

        $files = glob("{$backupPath}/*.tar.gz");
        foreach ($files as $file) {
            $filename = basename($file);
            $backups[] = [
                'filename' => $filename,
                'size' => $this->formatFileSize(filesize($file)),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        // Sort by creation date descending
        usort($backups, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    /**
     * Format file size for display
     */
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
