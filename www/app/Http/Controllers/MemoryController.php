<?php

namespace App\Http\Controllers;

use App\Models\MemoryDatabase;
use App\Models\Workspace;
use App\Services\MemoryDatabaseService;
use App\Services\MemorySchemaService;
use App\Services\MemorySnapshotService;
use App\Services\AppSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MemoryController extends Controller
{
    public function __construct(
        protected MemoryDatabaseService $databaseService,
        protected MemorySchemaService $schemaService,
        protected MemorySnapshotService $snapshotService,
        protected AppSettingsService $settings
    ) {}

    /**
     * Get the active workspace from session.
     * Returns null if no workspace is active.
     */
    protected function getActiveWorkspace(Request $request): ?Workspace
    {
        $workspaceId = $request->session()->get('active_workspace_id');

        if (!$workspaceId) {
            return null;
        }

        return Workspace::find($workspaceId);
    }

    /**
     * Get the selected memory database from request or default to first available.
     * When a workspace is active, filters to databases enabled in that workspace.
     */
    protected function getSelectedDatabase(Request $request): ?MemoryDatabase
    {
        $workspace = $this->getActiveWorkspace($request);

        if ($workspace) {
            // Filter to databases enabled in the active workspace
            $databases = $workspace->enabledMemoryDatabases()->orderBy('name')->get();
        } else {
            // No workspace context - show all databases
            // TODO: Consider requiring workspace context or filtering by user's accessible workspaces
            $databases = MemoryDatabase::orderBy('name')->get();
        }

        if ($databases->isEmpty()) {
            return null;
        }

        $selectedId = $request->input('db');
        if ($selectedId) {
            $selected = $databases->firstWhere('id', $selectedId);
            if ($selected) {
                return $selected;
            }
        }

        // Default to first database (or workspace default if available)
        if ($workspace) {
            $defaultDb = $workspace->defaultMemoryDatabase();
            if ($defaultDb && $databases->contains('id', $defaultDb->id)) {
                return $defaultDb;
            }
        }

        return $databases->first();
    }

    /**
     * Configure services with the selected database.
     */
    protected function configureServices(?MemoryDatabase $database): void
    {
        if ($database) {
            $this->schemaService->setMemoryDatabase($database);
            $this->snapshotService->setMemoryDatabase($database);
        }
    }

    /**
     * Show memory management page.
     */
    public function index(Request $request)
    {
        $request->session()->put('config_last_section', 'memory');

        // Get workspace and filter databases accordingly
        $workspace = $this->getActiveWorkspace($request);

        if ($workspace) {
            // Filter to databases enabled in the active workspace
            $databases = $workspace->enabledMemoryDatabases()->orderBy('name')->get();
        } else {
            // No workspace context - show all databases
            // TODO: Consider requiring workspace context or filtering by user's accessible workspaces
            $databases = MemoryDatabase::orderBy('name')->get();
        }

        $selectedDatabase = $this->getSelectedDatabase($request);
        $this->configureServices($selectedDatabase);

        $tables = [];
        $snapshots = [];

        if ($selectedDatabase) {
            try {
                $tables = $this->schemaService->listTables();
                $snapshots = $this->snapshotService->list();
            } catch (\Exception $e) {
                // Database not ready yet
                Log::warning('Memory schema not available', [
                    'database' => $selectedDatabase->name,
                    'schema' => $selectedDatabase->getFullSchemaName(),
                    'error' => $e->getMessage(),
                ]);
            }
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
            'databases' => $databases,
            'selectedDatabase' => $selectedDatabase,
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
        $selectedDatabase = $this->getSelectedDatabase($request);
        $this->configureServices($selectedDatabase);

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
        $selectedDatabase = $this->getSelectedDatabase($request);
        $this->configureServices($selectedDatabase);

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
        $selectedDatabase = $this->getSelectedDatabase($request);
        $this->configureServices($selectedDatabase);

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
        $selectedDatabase = $this->getSelectedDatabase($request);
        $this->configureServices($selectedDatabase);

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
        $selectedDatabase = $this->getSelectedDatabase($request);
        $this->configureServices($selectedDatabase);

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

        // Get selected database and configure service
        $selectedDatabase = $this->getSelectedDatabase($request);
        if (!$selectedDatabase) {
            abort(404, "No memory database available");
        }
        $this->configureServices($selectedDatabase);
        $schemaName = $selectedDatabase->getFullSchemaName();

        // Validate table exists
        if (!$this->schemaService->tableExists($tableName)) {
            abort(404, "Table '{$tableName}' not found in {$schemaName} schema");
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
                ->selectOne("SELECT COUNT(*) as total FROM {$schemaName}.{$tableName}");
            $totalRows = $countResult->total ?? 0;

            // Get paginated data
            $rows = DB::connection('pgsql_readonly')
                ->select("SELECT {$selectList} FROM {$schemaName}.{$tableName} ORDER BY \"{$sortColumn}\" {$sortDirection} LIMIT ? OFFSET ?", [$perPage, $offset]);

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
                'selectedDatabase' => $selectedDatabase,
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

        // Get selected database and configure service
        $selectedDatabase = $this->getSelectedDatabase($request);
        if (!$selectedDatabase) {
            abort(404, "No memory database available");
        }
        $this->configureServices($selectedDatabase);
        $schemaName = $selectedDatabase->getFullSchemaName();

        // Validate table exists
        if (!$this->schemaService->tableExists($tableName)) {
            abort(404, "Table '{$tableName}' not found in {$schemaName} schema");
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
                ->selectOne("SELECT {$selectList} FROM {$schemaName}.{$tableName} WHERE id = ?", [$rowId]);

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
                'selectedDatabase' => $selectedDatabase,
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
     * Update a memory database's metadata (name, description).
     */
    public function updateDatabase(Request $request, MemoryDatabase $memoryDatabase)
    {
        $validated = $request->validate([
            'description' => 'nullable|string|max:1024',
        ]);

        $memoryDatabase->update([
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()
            ->route('config.memory', ['db' => $memoryDatabase->id])
            ->with('success', 'Database description updated');
    }

    /**
     * Create a new memory database.
     */
    public function createDatabase(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'schema_name' => 'nullable|string|max:55|regex:/^[a-z][a-z0-9_]*$/',
            'description' => 'nullable|string|max:1024',
        ]);

        // Generate schema name from display name if not provided
        $schemaName = $validated['schema_name'] ?? null;
        if (empty($schemaName)) {
            $schemaName = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::slug($validated['name'], '_'));
            // Ensure it starts with a letter
            if (!preg_match('/^[a-z]/', $schemaName)) {
                $schemaName = 'db_' . $schemaName;
            }
            // Truncate to fit limit
            $schemaName = substr($schemaName, 0, 55);
        }

        $result = $this->databaseService->create(
            $validated['name'],
            $schemaName,
            $validated['description'] ?? null
        );

        if ($result['success']) {
            return redirect()
                ->route('config.memory', ['db' => $result['memory_database']->id])
                ->with('success', $result['message']);
        } else {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $result['message']);
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
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Malformed JSON - display as raw text
                    return ['content' => $value, 'type' => 'text'];
                }
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
