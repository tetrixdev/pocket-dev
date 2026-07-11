<?php

namespace App\Console\Commands;

use App\Models\SystemPackage;
use Illuminate\Console\Command;

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
            'status' => SystemPackage::STATUS_REQUIRES_RESTART,
        ]);

        $this->outputJson([
            'output' => "Package '{$name}' added. AI prompt will show: '{$cliCommands}'. Restart containers to install (Developer tab in menu).",
            'package' => [
                'id' => $package->id,
                'name' => $name,
                'cli_commands' => $cliCommands,
                'install_script' => $installScript,
            ],
            'is_error' => false,
        ]);

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

        // If install script changed, mark as requires_restart
        if ($requiresRestart) {
            $updates['status'] = SystemPackage::STATUS_REQUIRES_RESTART;
        }

        $package->update($updates);

        $message = "Package '{$package->name}' updated.";
        if ($requiresRestart) {
            $message .= ' Restart containers to apply install script changes.';
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
            'output' => "Package '{$packageName}' removed. Still installed in current container until restart.",
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
