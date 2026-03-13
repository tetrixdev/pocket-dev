<?php

namespace App\Console\Commands;

use App\Models\SystemPackage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SystemPackageCommand extends Command
{
    protected $signature = 'system:package
        {action : The action to perform (list, add, update, remove, export-scripts, status-by-id, fail-all-pending)}
        {--name= : The package name (required for add/remove/update)}
        {--cli_commands= : CLI command(s) to invoke, comma-separated (shown in AI prompt instead of name)}
        {--install_script= : Bash script to run for installation (required for add)}
        {--id= : The package UUID (required for status-by-id, alternative for update)}
        {--status= : The status to set (installed, failed, pending) - for status-by-id}
        {--message= : Status message (error message if failed) - for status-by-id and fail-all-pending}';

    protected $description = 'Manage global system packages for container installation';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listPackages(),
            'add' => $this->addPackage(),
            'update' => $this->updatePackage(),
            'remove' => $this->removePackage(),
            'export-scripts' => $this->exportScripts(),
            'status-by-id' => $this->updateStatusById(),
            'fail-all-pending' => $this->failAllPending(),
            default => $this->invalidAction($action),
        };
    }

    private function listPackages(): int
    {
        $packages = SystemPackage::orderBy('name')->get();

        if ($packages->isEmpty()) {
            $this->outputJson([
                'output' => 'No system packages configured.',
                'packages' => [],
                'count' => 0,
                'is_error' => false,
            ]);

            return Command::SUCCESS;
        }

        $packageList = $packages->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'cli_commands' => $p->cli_commands,
            'install_script' => $p->install_script,
            'status' => $p->status,
            'status_message' => $p->status_message,
        ])->toArray();

        $this->outputJson([
            'output' => 'Found ' . count($packageList) . ' system package(s).',
            'packages' => $packageList,
            'count' => count($packageList),
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    private function addPackage(): int
    {
        $name = $this->option('name');
        $cliCommands = $this->option('cli_commands');
        $installScript = $this->option('install_script');

        // Validate required fields
        if (empty($name)) {
            $this->outputJson([
                'output' => 'The --name option is required.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        if (empty($cliCommands)) {
            $this->outputJson([
                'output' => 'The --cli_commands option is required.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        if (empty($installScript)) {
            $this->outputJson([
                'output' => 'The --install_script option is required.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        // Create the package (name doesn't need to be unique)
        $package = SystemPackage::create([
            'name' => $name,
            'cli_commands' => $cliCommands,
            'install_script' => $installScript,
            'status' => SystemPackage::STATUS_PENDING,
        ]);

        // Attempt hot-install (no restart needed)
        $hotInstallResult = $this->hotInstallPackage($package);

        if ($hotInstallResult['success']) {
            $this->outputJson([
                'output' => "Package '{$name}' installed successfully. AI prompt will show: '{$cliCommands}'. Available immediately, no restart needed.",
                'package' => [
                    'id' => $package->id,
                    'name' => $name,
                    'cli_commands' => $cliCommands,
                    'status' => 'installed',
                ],
                'is_error' => false,
            ]);
        } else {
            // Hot-install failed — fall back to requires_restart
            $package->update([
                'status' => SystemPackage::STATUS_FAILED,
                'status_message' => $hotInstallResult['error'],
            ]);

            $this->outputJson([
                'output' => "Package '{$name}' added but installation failed: {$hotInstallResult['error']}. You can retry by restarting containers (Developer tab in menu).",
                'package' => [
                    'id' => $package->id,
                    'name' => $name,
                    'cli_commands' => $cliCommands,
                    'status' => 'failed',
                    'error' => $hotInstallResult['error'],
                ],
                'is_error' => false,
            ]);
        }

        return Command::SUCCESS;
    }

    private function updatePackage(): int
    {
        $id = $this->option('id');
        $name = $this->option('name');
        $cliCommands = $this->option('cli_commands');
        $installScript = $this->option('install_script');

        // Need either id or name to find the package
        if (empty($id) && empty($name)) {
            $this->outputJson([
                'output' => 'Either --id or --name option is required.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        // Find the package
        $package = $id
            ? SystemPackage::find($id)
            : SystemPackage::where('name', $name)->first();

        if (!$package) {
            $this->outputJson([
                'output' => 'Package not found.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        // Update only provided fields
        $updates = [];
        $requiresRestart = false;

        if ($cliCommands !== null) {
            $updates['cli_commands'] = $cliCommands ?: null; // Empty string becomes null
        }
        if ($installScript !== null) {
            $updates['install_script'] = $installScript;
            $requiresRestart = true;
        }

        if (empty($updates)) {
            $this->outputJson([
                'output' => 'No updates provided. Use --cli_commands or --install_script.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        $package->update($updates);

        $message = "Package '{$package->name}' updated.";

        // If install script changed, hot-install immediately
        if ($requiresRestart) {
            $hotInstallResult = $this->hotInstallPackage($package);

            if ($hotInstallResult['success']) {
                $message .= ' New install script applied successfully, available immediately.';
            } else {
                $package->update([
                    'status' => SystemPackage::STATUS_FAILED,
                    'status_message' => $hotInstallResult['error'],
                ]);
                $message .= " Install script updated but installation failed: {$hotInstallResult['error']}. Retry by restarting containers (Developer tab in menu).";
            }
        }

        $this->outputJson([
            'output' => $message,
            'package' => [
                'id' => $package->id,
                'name' => $package->name,
                'cli_commands' => $package->cli_commands,
                'install_script' => $package->install_script,
                'status' => $package->status,
            ],
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    private function removePackage(): int
    {
        $id = $this->option('id');
        $name = $this->option('name');

        // Validate - need either id or name
        if (empty($id) && empty($name)) {
            $this->outputJson([
                'output' => 'Either --id or --name option is required.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        // Find the package by ID or name
        $package = $id
            ? SystemPackage::find($id)
            : SystemPackage::where('name', $name)->first();

        if (!$package) {
            $this->outputJson([
                'output' => 'Package not found.',
                'is_error' => true,
            ]);
            return Command::FAILURE;
        }

        $packageName = $package->name;
        $package->delete();

        $remainingCount = SystemPackage::count();

        $this->outputJson([
            'output' => "Package '{$packageName}' removed. It remains installed until the next container restart.",
            'package' => $packageName,
            'remaining_count' => $remainingCount,
            'is_error' => false,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Export all packages with their install scripts as JSON (for container entrypoint).
     */
    private function exportScripts(): int
    {
        $packages = SystemPackage::getAllWithScripts();

        // Output JSON (for shell script consumption via jq)
        $this->output->writeln(json_encode($packages));

        return Command::SUCCESS;
    }

    /**
     * Update the installation status of a package by ID.
     * Called from entrypoint script after running install script.
     */
    private function updateStatusById(): int
    {
        $id = $this->option('id');
        $status = $this->option('status');
        $message = $this->option('message');

        // Validate required fields
        if (empty($id)) {
            $this->output->writeln('Error: --id is required');
            return Command::FAILURE;
        }

        if (empty($status)) {
            $this->output->writeln('Error: --status is required');
            return Command::FAILURE;
        }

        // Validate status value
        $validStatuses = [
            SystemPackage::STATUS_REQUIRES_RESTART,
            SystemPackage::STATUS_PENDING,
            SystemPackage::STATUS_INSTALLING,
            SystemPackage::STATUS_INSTALLED,
            SystemPackage::STATUS_FAILED,
        ];
        if (!in_array($status, $validStatuses)) {
            $this->output->writeln("Error: Invalid status '{$status}'. Valid values: " . implode(', ', $validStatuses));
            return Command::FAILURE;
        }

        // Update the package status
        $updated = SystemPackage::updateStatusById($id, $status, $message);

        if (!$updated) {
            $this->output->writeln("Error: Package not found");
            return Command::FAILURE;
        }

        $this->output->writeln("OK");
        return Command::SUCCESS;
    }

    /**
     * Mark all pending packages as failed with a system error message.
     * Called from entrypoint when a system-level error prevents package installation.
     */
    private function failAllPending(): int
    {
        $message = $this->option('message') ?? 'System error during package installation';

        $count = SystemPackage::where('status', SystemPackage::STATUS_PENDING)
            ->update([
                'status' => SystemPackage::STATUS_FAILED,
                'status_message' => $message,
            ]);

        $this->output->writeln("Marked {$count} pending package(s) as failed");
        return Command::SUCCESS;
    }

    /**
     * Hot-install a single package into the running container(s) via docker exec.
     *
     * Runs the package's install script as root inside the container without
     * requiring a restart. The binary is immediately available to all processes
     * (Claude Code, MCP servers, etc.) since they resolve PATH at exec time.
     *
     * @return array{success: bool, error: ?string, output: ?string}
     */
    private function hotInstallPackage(SystemPackage $package): array
    {
        $installScript = $package->install_script;

        if (empty($installScript)) {
            return ['success' => false, 'error' => 'No install script defined', 'output' => null];
        }

        // Check if docker CLI is available
        exec('which docker 2>/dev/null', $dockerCheck, $dockerReturn);
        if ($dockerReturn !== 0) {
            return ['success' => false, 'error' => 'Docker CLI not available for hot-install', 'output' => null];
        }

        // Apply /tmp → /var/tmp rewrite (same as install-system-packages.sh)
        // Fixes curl write errors caused by the shared /tmp volume
        $installScript = $this->rewriteTmpPaths($installScript);

        // Write the install script to a temp file to avoid shell escaping issues
        $tempScript = tempnam('/var/tmp', 'pkg_install_');
        if ($tempScript === false) {
            return ['success' => false, 'error' => 'Failed to create temp file for install script', 'output' => null];
        }
        file_put_contents($tempScript, "#!/bin/bash\nset -e\n" . $installScript);
        chmod($tempScript, 0755);

        $package->update(['status' => SystemPackage::STATUS_INSTALLING]);

        // Install in the queue container (where Claude Code and MCP servers run)
        $containers = ['pocket-dev-queue', 'pocket-dev-php'];
        $lastError = null;
        $queueSuccess = false;

        foreach ($containers as $container) {
            // Copy script into the target container, then execute as root
            $copyCmd = sprintf(
                'docker cp %s %s:/var/tmp/pkg_install.sh 2>&1',
                escapeshellarg($tempScript),
                escapeshellarg($container)
            );
            exec($copyCmd, $copyOutput, $copyReturn);

            if ($copyReturn !== 0) {
                $lastError = "Failed to copy install script to {$container}: " . implode(' ', $copyOutput);
                Log::warning("Hot-install: {$lastError}");
                continue;
            }

            $execCmd = sprintf(
                'docker exec -u root %s bash -c %s 2>&1',
                escapeshellarg($container),
                escapeshellarg('chmod +x /var/tmp/pkg_install.sh && /var/tmp/pkg_install.sh && rm -f /var/tmp/pkg_install.sh')
            );

            exec($execCmd, $execOutput, $execReturn);
            $output = implode("\n", $execOutput);

            if ($execReturn !== 0) {
                // Extract last few lines for error message
                $errorLines = array_slice($execOutput, -3);
                $lastError = "Install failed in {$container}: " . implode(' ', $errorLines);
                Log::warning("Hot-install: {$lastError}", ['output' => $output]);

                if ($container === 'pocket-dev-queue') {
                    // Queue container is critical — if it fails, the package won't work
                    break;
                }
                continue;
            }

            if ($container === 'pocket-dev-queue') {
                $queueSuccess = true;
            }

            Log::info("Hot-install: {$package->name} installed in {$container}");
        }

        // Clean up temp file
        @unlink($tempScript);

        if ($queueSuccess) {
            $package->update([
                'status' => SystemPackage::STATUS_INSTALLED,
                'status_message' => null,
                'installed_at' => now(),
            ]);
            return ['success' => true, 'error' => null, 'output' => 'Installed successfully'];
        }

        return ['success' => false, 'error' => $lastError ?? 'Unknown error', 'output' => null];
    }

    /**
     * Rewrite /tmp/ paths to /var/tmp/ in install scripts.
     *
     * Same logic as install-system-packages.sh — fixes curl write errors
     * caused by the shared /tmp volume.
     */
    private function rewriteTmpPaths(string $script): string
    {
        $replacements = [
            ' /tmp/'  => ' /var/tmp/',
            '"/tmp/'  => '"/var/tmp/',
            "'/tmp/"  => "'/var/tmp/",
            '=/tmp/'  => '=/var/tmp/',
            '>/tmp/'  => '>/var/tmp/',
            '>>/tmp/' => '>>/var/tmp/',
            '2>/tmp/' => '2>/var/tmp/',
            '2>>/tmp/'=> '2>>/var/tmp/',
            '>&/tmp/' => '>&/var/tmp/',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $script);
    }

    private function invalidAction(string $action): int
    {
        $this->outputJson([
            'output' => "Invalid action '{$action}'. Valid actions are: list, add, update, remove, export-scripts, status-by-id, fail-all-pending.",
            'is_error' => true,
        ]);
        return Command::FAILURE;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
