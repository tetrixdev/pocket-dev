@extends('layouts.config')

@section('title', 'Memory')

@section('content')
<div class="space-y-8">
    {{-- Header --}}
    <div>
        <h1 class="text-2xl font-bold text-white mb-2">Memory System</h1>
        <p class="text-gray-400">Manage memory schema tables and snapshots for disaster recovery.</p>
    </div>

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="bg-green-900 border border-green-700 text-green-200 px-4 py-3 rounded">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="bg-red-900 border border-red-700 text-red-200 px-4 py-3 rounded">
            {{ session('error') }}
        </div>
    @endif

    {{-- User Tables Section --}}
    <section>
        <h2 class="text-lg font-semibold mb-4 text-gray-200">Memory Tables</h2>

        @if(empty($userTables))
            <div class="bg-gray-800 rounded-lg p-6 text-center">
                <p class="text-gray-400 mb-4">No tables created yet.</p>
                <p class="text-sm text-gray-500">Use <code class="bg-gray-700 px-2 py-1 rounded">memory:schema:create-table</code> to create tables.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($userTables as $table)
                    <div class="bg-gray-800 rounded-lg p-4">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <h3 class="font-medium text-white">memory.{{ $table['table_name'] }}</h3>
                                @if($table['description'])
                                    <p class="text-sm text-gray-400">{{ $table['description'] }}</p>
                                @endif
                            </div>
                            <span class="text-sm text-gray-500">{{ number_format($table['row_count']) }} rows</span>
                        </div>

                        @if(!empty($table['embeddable_fields']))
                            <div class="text-sm text-blue-400 mb-2">
                                Auto-embed: {{ implode(', ', $table['embeddable_fields']) }}
                            </div>
                        @endif

                        @if(!empty($table['columns']))
                            <details class="mt-3">
                                <summary class="text-sm text-gray-400 cursor-pointer hover:text-gray-300">
                                    {{ count($table['columns']) }} columns
                                </summary>
                                <div class="mt-2 pl-4 border-l border-gray-700 space-y-1">
                                    @foreach($table['columns'] as $col)
                                        <div class="text-sm">
                                            <span class="text-gray-300">{{ $col['name'] }}</span>
                                            <span class="text-gray-500">({{ $col['type'] }}{{ $col['nullable'] ? '' : ' NOT NULL' }})</span>
                                            @if($col['description'])
                                                <span class="text-gray-400">- {{ $col['description'] }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- System Tables Section --}}
    <section>
        <h2 class="text-lg font-semibold mb-4 text-gray-200">System Tables</h2>
        <p class="text-sm text-gray-400 mb-4">These tables are managed by PocketDev and cannot be dropped.</p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($systemTables as $table)
                <div class="bg-gray-800 rounded-lg p-4 border border-yellow-900">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-medium text-yellow-400">memory.{{ $table['table_name'] }}</h3>
                        <span class="text-xs text-yellow-600 bg-yellow-900 px-2 py-1 rounded">PROTECTED</span>
                    </div>
                    @if($table['description'])
                        <p class="text-sm text-gray-400">{{ $table['description'] }}</p>
                    @endif
                    <p class="text-sm text-gray-500 mt-2">{{ number_format($table['row_count']) }} rows</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Snapshots Section --}}
    <section>
        <h2 class="text-lg font-semibold mb-4 text-gray-200">Snapshots</h2>
        <p class="text-sm text-gray-400 mb-4">
            Hourly snapshots are created automatically. Tiered retention: hourly (24h), 4/day (7d), 1/day (30d).
        </p>

        <div class="flex gap-4 mb-6">
            <form method="POST" action="{{ route('config.memory.snapshots.create') }}">
                @csrf
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                    Create Snapshot Now
                </button>
            </form>

            <form method="POST" action="{{ route('config.memory.snapshots.create') }}">
                @csrf
                <input type="hidden" name="schema_only" value="1">
                <button type="submit" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white text-sm rounded">
                    Schema Only
                </button>
            </form>
        </div>

        @if(empty($snapshots))
            <div class="bg-gray-800 rounded-lg p-6 text-center">
                <p class="text-gray-400">No snapshots yet. Hourly snapshots will start once you have tables.</p>
            </div>
        @else
            <div class="space-y-6">
                @foreach(['hourly' => 'Hourly (Last 24h)', 'daily-4' => 'Daily (Last 7d)', 'daily' => 'Archived (Last 30d)'] as $tier => $label)
                    @if(!empty($snapshotsByTier[$tier]))
                        <div>
                            <h3 class="text-sm font-medium text-gray-400 mb-2">{{ $label }}</h3>
                            <div class="bg-gray-800 rounded-lg divide-y divide-gray-700">
                                @foreach($snapshotsByTier[$tier] as $snapshot)
                                    <div class="p-4 flex items-center justify-between">
                                        <div>
                                            <span class="text-white">{{ $snapshot['filename'] }}</span>
                                            @if($snapshot['schema_only'])
                                                <span class="ml-2 text-xs text-purple-400 bg-purple-900 px-2 py-0.5 rounded">Schema Only</span>
                                            @endif
                                            <div class="text-sm text-gray-400">
                                                {{ \Carbon\Carbon::parse($snapshot['created_at'])->format('M j, Y g:i A') }}
                                                &bull; {{ number_format($snapshot['size'] / 1024, 1) }} KB
                                            </div>
                                        </div>
                                        <div class="flex gap-2">
                                            <form method="POST" action="{{ route('config.memory.snapshots.restore', $snapshot['filename']) }}" onsubmit="return confirm('Restore to this snapshot? Current state will be backed up first.')">
                                                @csrf
                                                <button type="submit" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm rounded">
                                                    Restore
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('config.memory.snapshots.delete', $snapshot['filename']) }}" onsubmit="return confirm('Delete this snapshot?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-sm rounded">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </section>

    {{-- Settings Section --}}
    <section>
        <h2 class="text-lg font-semibold mb-4 text-gray-200">Settings</h2>

        <div class="bg-gray-800 rounded-lg p-6">
            <form method="POST" action="{{ route('config.memory.settings') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="retention_days" class="block text-sm font-medium text-gray-300 mb-2">
                        Snapshot Retention (days)
                    </label>
                    <input
                        type="number"
                        id="retention_days"
                        name="retention_days"
                        value="{{ $retentionDays }}"
                        min="1"
                        max="365"
                        class="w-32 bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white focus:border-blue-500 focus:outline-none"
                    >
                    <p class="text-sm text-gray-500 mt-1">How long to keep daily snapshots (1-365 days)</p>
                </div>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                    Save Settings
                </button>
            </form>
        </div>
    </section>

    {{-- Export/Import Section --}}
    <section>
        <h2 class="text-lg font-semibold mb-4 text-gray-200">Export / Import</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            {{-- Export --}}
            <div class="bg-gray-800 rounded-lg p-6">
                <h3 class="font-medium text-white mb-4">Export Memory Schema</h3>
                <div class="space-y-3">
                    <a href="{{ route('config.memory.export') }}" class="block w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded text-center">
                        Full Backup (Data + Schema)
                    </a>
                    <a href="{{ route('config.memory.export', ['schema_only' => 1]) }}" class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white text-sm rounded text-center">
                        Schema Only (No Data)
                    </a>
                </div>
            </div>

            {{-- Import --}}
            <div class="bg-gray-800 rounded-lg p-6">
                <h3 class="font-medium text-white mb-4">Import Snapshot</h3>
                <form method="POST" action="{{ route('config.memory.import') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-4">
                        <input
                            type="file"
                            name="snapshot_file"
                            accept=".sql,.txt"
                            required
                            class="block w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-medium file:bg-gray-700 file:text-white hover:file:bg-gray-600"
                        >
                    </div>
                    <button type="submit" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm rounded">
                        Upload Snapshot
                    </button>
                    <p class="text-xs text-gray-500 mt-2">After importing, use Restore to apply the snapshot.</p>
                </form>
            </div>
        </div>
    </section>
</div>
@endsection
