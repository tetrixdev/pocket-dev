<?php

namespace App\Http\Controllers;

use App\Services\MemorySchemaService;
use App\Services\MemorySnapshotService;
use App\Services\AppSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Browse data in a memory table.
     */
    public function browseTable(Request $request, string $tableName)
    {
        $request->session()->put('config_last_section', 'memory');

        // Validate table exists
        if (!$this->schemaService->tableExists($tableName)) {
            abort(404, "Table '{$tableName}' not found in memory schema");
        }

        // Get table metadata
        $tables = $this->schemaService->listTables();
        $tableInfo = collect($tables)->firstWhere('table_name', $tableName);

        if (!$tableInfo) {
            abort(404, "Table '{$tableName}' not found");
        }

        // Pagination settings
        $perPage = $request->input('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 25;
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;

        // Sorting
        $sortColumn = $request->input('sort', 'id');
        $sortDirection = $request->input('dir', 'asc');
        $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) ? strtolower($sortDirection) : 'asc';

        // Validate sort column exists
        $columnNames = array_column($tableInfo['columns'], 'name');
        if (!in_array($sortColumn, $columnNames)) {
            $sortColumn = $columnNames[0] ?? 'id';
        }

        // Build column list excluding vector/embedding columns (too large to display)
        $selectColumns = [];
        $columnTypes = [];
        foreach ($tableInfo['columns'] as $col) {
            $columnTypes[$col['name']] = $col['type'];
            // Skip vector columns (1536 dimensions)
            if ($col['type'] === 'vector') {
                continue;
            }
            $selectColumns[] = '"' . $col['name'] . '"';
        }

        $selectList = implode(', ', $selectColumns);

        try {
            // Get total count
            $countResult = DB::connection('pgsql_readonly')
                ->selectOne("SELECT COUNT(*) as total FROM memory.{$tableName}");
            $totalRows = $countResult->total ?? 0;

            // Get paginated data
            $rows = DB::connection('pgsql_readonly')
                ->select("SELECT {$selectList} FROM memory.{$tableName} ORDER BY \"{$sortColumn}\" {$sortDirection} LIMIT ? OFFSET ?", [$perPage, $offset]);

            // Convert to array and process for display
            $processedRows = [];
            foreach ($rows as $row) {
                $processedRow = [];
                foreach ((array) $row as $column => $value) {
                    $processedRow[$column] = $this->formatCellValue($value, $columnTypes[$column] ?? 'text');
                }
                $processedRows[] = $processedRow;
            }

            $totalPages = (int) ceil($totalRows / $perPage);

            return view('config.memory-browse', [
                'tableName' => $tableName,
                'tableInfo' => $tableInfo,
                'columns' => array_filter($tableInfo['columns'], fn($c) => $c['type'] !== 'vector'),
                'rows' => $processedRows,
                'totalRows' => $totalRows,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'perPage' => $perPage,
                'sortColumn' => $sortColumn,
                'sortDirection' => $sortDirection,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to browse memory table', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to load table data: ' . $e->getMessage());
        }
    }

    /**
     * Format a cell value for display.
     *
     * TODO: Consider extracting shared logic with formatFullValue() into a base method
     * with a "truncate" flag parameter to reduce duplication. (CodeRabbit suggestion)
     */
    protected function formatCellValue(mixed $value, string $type): array
    {
        if ($value === null) {
            return ['display' => 'NULL', 'full' => null, 'type' => 'null'];
        }

        // Handle JSONB
        if ($type === 'jsonb' || $type === 'json') {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Malformed JSON - display as raw text
                    $preview = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
                    return ['display' => $preview, 'full' => $value, 'type' => 'text'];
                }
                $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $preview = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
                return ['display' => $preview, 'full' => $formatted, 'type' => 'json'];
            }
            return ['display' => json_encode($value), 'full' => json_encode($value, JSON_PRETTY_PRINT), 'type' => 'json'];
        }

        // Handle arrays (PostgreSQL text[], etc.)
        if (str_ends_with($type, '[]') || (is_string($value) && str_starts_with($value, '{'))) {
            if (is_string($value) && str_starts_with($value, '{')) {
                // Parse PostgreSQL array format
                $parsed = $this->parsePgArray($value);
                $display = count($parsed) . ' items';
                return ['display' => $display, 'full' => implode("\n", $parsed), 'type' => 'array', 'count' => count($parsed)];
            }
            return ['display' => $value, 'full' => $value, 'type' => 'array'];
        }

        // Handle timestamps
        if (in_array($type, ['timestamp without time zone', 'timestamp with time zone', 'timestamptz', 'timestamp'])) {
            return ['display' => $value, 'full' => $value, 'type' => 'timestamp'];
        }

        // Handle UUIDs
        if ($type === 'uuid') {
            $short = substr($value, 0, 8) . '...';
            return ['display' => $short, 'full' => $value, 'type' => 'uuid'];
        }

        // Handle geography/geometry
        if (in_array($type, ['geography', 'geometry'])) {
            // Try to extract coordinates from WKT or WKB
            return ['display' => '[geo]', 'full' => $value, 'type' => 'geo'];
        }

        // Handle boolean
        if ($type === 'boolean' || $type === 'bool') {
            return ['display' => $value ? 'true' : 'false', 'full' => $value ? 'true' : 'false', 'type' => 'boolean'];
        }

        // Handle long text
        $stringValue = (string) $value;
        if (strlen($stringValue) > 100) {
            return ['display' => substr($stringValue, 0, 100) . '...', 'full' => $stringValue, 'type' => 'text'];
        }

        return ['display' => $stringValue, 'full' => $stringValue, 'type' => 'text'];
    }

    /**
     * Parse PostgreSQL array format to PHP array.
     *
     * TODO: Consider using MemorySchemaService::pgArrayToArray() instead to eliminate
     * duplication. That method also handles edge cases like null and unterminated quotes.
     * (CodeRabbit suggestion)
     */
    protected function parsePgArray(string $pgArray): array
    {
        if ($pgArray === '{}') {
            return [];
        }

        $content = trim($pgArray, '{}');
        if (empty($content)) {
            return [];
        }

        $result = [];
        $current = '';
        $inQuotes = false;
        $escaped = false;

        for ($i = 0; $i < strlen($content); $i++) {
            $char = $content[$i];

            if ($escaped) {
                $current .= $char;
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($char === ',' && !$inQuotes) {
                $result[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result;
    }

    /**
     * Show a single row from a memory table.
     */
    public function showRow(Request $request, string $tableName, string $rowId)
    {
        $request->session()->put('config_last_section', 'memory');

        // Validate table exists
        if (!$this->schemaService->tableExists($tableName)) {
            abort(404, "Table '{$tableName}' not found in memory schema");
        }

        // Get table metadata
        $tables = $this->schemaService->listTables();
        $tableInfo = collect($tables)->firstWhere('table_name', $tableName);

        if (!$tableInfo) {
            abort(404, "Table '{$tableName}' not found");
        }

        // Build column list excluding vector columns
        $selectColumns = [];
        $columnTypes = [];
        foreach ($tableInfo['columns'] as $col) {
            $columnTypes[$col['name']] = $col['type'];
            if ($col['type'] === 'vector') {
                continue;
            }
            $selectColumns[] = '"' . $col['name'] . '"';
        }

        $selectList = implode(', ', $selectColumns);

        try {
            // Try to find the row by id (UUID)
            $row = DB::connection('pgsql_readonly')
                ->selectOne("SELECT {$selectList} FROM memory.{$tableName} WHERE id = ?", [$rowId]);

            if (!$row) {
                abort(404, "Row not found");
            }

            // Process row data for full display (no truncation)
            $processedFields = [];
            foreach ((array) $row as $column => $value) {
                $colInfo = collect($tableInfo['columns'])->firstWhere('name', $column);
                $processedFields[] = [
                    'name' => $column,
                    'type' => $columnTypes[$column] ?? 'text',
                    'description' => $colInfo['description'] ?? null,
                    'value' => $this->formatFullValue($value, $columnTypes[$column] ?? 'text'),
                ];
            }

            return view('config.memory-show', [
                'tableName' => $tableName,
                'tableInfo' => $tableInfo,
                'rowId' => $rowId,
                'fields' => $processedFields,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to show memory row', [
                'table' => $tableName,
                'rowId' => $rowId,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to load row: ' . $e->getMessage());
        }
    }

    /**
     * Format a value for full display (no truncation).
     */
    protected function formatFullValue(mixed $value, string $type): array
    {
        if ($value === null) {
            return ['content' => null, 'type' => 'null'];
        }

        // Handle JSONB
        if ($type === 'jsonb' || $type === 'json') {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                return ['content' => $formatted, 'type' => 'json'];
            }
            return ['content' => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'type' => 'json'];
        }

        // Handle arrays
        if (str_ends_with($type, '[]') || (is_string($value) && str_starts_with($value, '{'))) {
            if (is_string($value) && str_starts_with($value, '{')) {
                $parsed = $this->parsePgArray($value);
                return ['content' => $parsed, 'type' => 'array'];
            }
            return ['content' => [$value], 'type' => 'array'];
        }

        // Handle boolean
        if ($type === 'boolean' || $type === 'bool') {
            return ['content' => $value ? 'true' : 'false', 'type' => 'boolean'];
        }

        // All other types - return as string
        return ['content' => (string) $value, 'type' => $type];
    }
}
