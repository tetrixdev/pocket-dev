<?php

namespace App\Http\Controllers;

use App\Services\MemorySchemaService;
use App\Services\MemorySnapshotService;
use App\Services\AppSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MemoryController extends Controller
{
    public function __construct(
        protected MemorySchemaService $schemaService,
        protected MemorySnapshotService $snapshotService,
        protected AppSettingsService $settings
    ) {}

    /**
     * Show memory management page.
     */
    public function index(Request $request)
    {
        $request->session()->put('config_last_section', 'memory');

        try {
            $tables = $this->schemaService->listTables();
            $snapshots = $this->snapshotService->list();
        } catch (\Exception $e) {
            // Database not ready yet
            Log::warning('Memory schema not available', ['error' => $e->getMessage()]);
            $tables = [];
            $snapshots = [];
        }

        // Separate system tables from user tables
        $systemTables = array_filter($tables, fn($t) => in_array($t['table_name'], ['embeddings', 'schema_registry']));
        $userTables = array_filter($tables, fn($t) => !in_array($t['table_name'], ['embeddings', 'schema_registry']));

        // Group snapshots by tier
        $snapshotsByTier = [
            'hourly' => [],
            'daily-4' => [],
            'daily' => [],
        ];
        foreach ($snapshots as $snapshot) {
            $tier = $snapshot['tier'];
            if (isset($snapshotsByTier[$tier])) {
                $snapshotsByTier[$tier][] = $snapshot;
            }
        }

        return view('config.memory', [
            'userTables' => $userTables,
            'systemTables' => $systemTables,
            'snapshots' => $snapshots,
            'snapshotsByTier' => $snapshotsByTier,
            'retentionDays' => $this->snapshotService->getRetentionDays(),
        ]);
    }

    /**
     * Update memory settings.
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'retention_days' => 'required|integer|min:1|max:365',
        ]);

        try {
            $this->snapshotService->setRetentionDays((int) $validated['retention_days']);
            return redirect()->back()->with('success', 'Settings saved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to save memory settings', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to save settings');
        }
    }

    /**
     * Create a new snapshot.
     */
    public function createSnapshot(Request $request)
    {
        $schemaOnly = $request->boolean('schema_only', false);

        try {
            $result = $this->snapshotService->create($schemaOnly);

            if ($result['success']) {
                return redirect()->back()->with('success', $result['message']);
            } else {
                return redirect()->back()->with('error', $result['message']);
            }
        } catch (\Exception $e) {
            Log::error('Failed to create snapshot', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to create snapshot');
        }
    }

    /**
     * Restore from a snapshot.
     */
    public function restoreSnapshot(Request $request, string $filename)
    {
        try {
            $result = $this->snapshotService->restore($filename);

            if ($result['success']) {
                return redirect()->back()->with('success', $result['message']);
            } else {
                return redirect()->back()->with('error', $result['message']);
            }
        } catch (\Exception $e) {
            Log::error('Failed to restore snapshot', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to restore snapshot');
        }
    }

    /**
     * Delete a snapshot.
     */
    public function deleteSnapshot(Request $request, string $filename)
    {
        try {
            $result = $this->snapshotService->delete($filename);

            if ($result['success']) {
                return redirect()->back()->with('success', $result['message']);
            } else {
                return redirect()->back()->with('error', $result['message']);
            }
        } catch (\Exception $e) {
            Log::error('Failed to delete snapshot', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to delete snapshot');
        }
    }

    /**
     * Export memory schema (download).
     */
    public function export(Request $request)
    {
        $schemaOnly = $request->boolean('schema_only', false);

        try {
            // Create a new snapshot for download
            $result = $this->snapshotService->create($schemaOnly);

            if (!$result['success']) {
                return redirect()->back()->with('error', $result['message']);
            }

            return response()->download($result['path'], $result['filename'])->deleteFileAfterSend(false);
        } catch (\Exception $e) {
            Log::error('Failed to export memory schema', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to export memory schema');
        }
    }

    /**
     * Import memory schema (upload).
     */
    public function import(Request $request)
    {
        $request->validate([
            'snapshot_file' => 'required|file|mimes:sql,txt|max:102400', // 100MB max
        ]);

        try {
            $file = $request->file('snapshot_file');
            $result = $this->snapshotService->import($file->getRealPath());

            if ($result['success']) {
                return redirect()->back()->with('success', $result['message']);
            } else {
                return redirect()->back()->with('error', $result['message']);
            }
        } catch (\Exception $e) {
            Log::error('Failed to import snapshot', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to import snapshot');
        }
    }
}
