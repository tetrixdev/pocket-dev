<?php

namespace App\Console\Commands;

use App\Services\MemorySnapshotService;
use Illuminate\Console\Command;

/**
 * Manage memory schema snapshots.
 * Not exposed as an AI tool - only available via artisan and scheduler.
 */
class MemorySnapshotCommand extends Command
{
    protected $signature = 'memory:snapshot
        {action=list : Action to perform: create, list, prune, delete}
        {--schema-only : Create schema-only snapshot (no data)}
        {--filename= : Filename for delete action}';

    protected $description = 'Manage memory schema snapshots (create, list, prune, delete)';

    public function handle(MemorySnapshotService $service): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'create' => $this->createSnapshot($service),
            'list' => $this->listSnapshots($service),
            'prune' => $this->pruneSnapshots($service),
            'delete' => $this->deleteSnapshot($service),
            default => $this->invalidAction($action),
        };
    }

    private function createSnapshot(MemorySnapshotService $service): int
    {
        $schemaOnly = (bool) $this->option('schema-only');
        $result = $service->create($schemaOnly);

        $this->output->writeln(json_encode([
            'success' => $result['success'],
            'filename' => $result['filename'],
            'message' => $result['message'],
        ], JSON_PRETTY_PRINT));

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function listSnapshots(MemorySnapshotService $service): int
    {
        $snapshots = $service->list();

        if (empty($snapshots)) {
            $this->output->writeln(json_encode([
                'snapshots' => [],
                'message' => 'No snapshots found',
            ], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        // Group by tier for display
        $grouped = [
            'hourly' => [],
            'daily-4' => [],
            'daily' => [],
            'expired' => [],
        ];

        foreach ($snapshots as $snapshot) {
            $tier = $snapshot['tier'];
            $grouped[$tier][] = [
                'filename' => $snapshot['filename'],
                'created_at' => $snapshot['created_at'],
                'size_kb' => round($snapshot['size'] / 1024, 1),
                'schema_only' => $snapshot['schema_only'],
            ];
        }

        $this->output->writeln(json_encode([
            'total' => count($snapshots),
            'retention_days' => $service->getRetentionDays(),
            'snapshots' => $grouped,
        ], JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }

    private function pruneSnapshots(MemorySnapshotService $service): int
    {
        $result = $service->prune();

        $this->output->writeln(json_encode([
            'success' => $result['success'],
            'deleted' => $result['deleted'],
            'message' => $result['message'],
        ], JSON_PRETTY_PRINT));

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function deleteSnapshot(MemorySnapshotService $service): int
    {
        $filename = $this->option('filename');

        if (empty($filename)) {
            $this->output->writeln(json_encode([
                'success' => false,
                'message' => 'The --filename option is required for delete action',
            ], JSON_PRETTY_PRINT));
            return Command::FAILURE;
        }

        $result = $service->delete($filename);

        $this->output->writeln(json_encode([
            'success' => $result['success'],
            'message' => $result['message'],
        ], JSON_PRETTY_PRINT));

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function invalidAction(string $action): int
    {
        $this->output->writeln(json_encode([
            'success' => false,
            'message' => "Invalid action: {$action}. Use: create, list, prune, or delete",
        ], JSON_PRETTY_PRINT));

        return Command::FAILURE;
    }
}
