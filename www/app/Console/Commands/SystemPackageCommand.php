<?php

namespace App\Console\Commands;

use App\Models\SystemPackage;
use Illuminate\Console\Command;

class SystemPackageCommand extends Command
{
    protected $signature = 'system:package
        {action : The action to perform (list, add, remove, export-scripts, status-by-id, fail-all-pending)}
        {--name= : The package name (required for add/remove)}
        {--install_script= : Bash script to run for installation (required for add)}
        {--id= : The package UUID (required for status-by-id)}
        {--status= : The status to set (installed, failed, pending) - for status-by-id}
        {--message= : Status message (error message if failed) - for status-by-id and fail-all-pending}';

    protected $description = 'Manage global system packages for container installation';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listPackages(),
            'add' => $this->addPackage(),
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
        $installScript = $this->option('install_script');

        // Validate required fields
        if (empty($name)) {
            $this->outputJson([
                'output' => 'The --name option is required.',
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
            'install_script' => $installScript,
            'status' => SystemPackage::STATUS_PENDING,
        ]);

        $this->outputJson([
            'output' => "Package '{$name}' added. Restart containers to install (Developer tab in menu).",
            'package' => [
                'id' => $package->id,
                'name' => $name,
                'install_script' => $installScript,
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
            'output' => "Invalid action '{$action}'. Valid actions are: list, add, remove, export-scripts, status-by-id, fail-all-pending.",
            'is_error' => true,
        ]);
        return Command::FAILURE;
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
