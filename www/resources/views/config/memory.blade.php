@extends('layouts.config')

@section('title', 'Memory')

@section('content')
<div class="space-y-8">
    {{-- Header --}}
    <div>
        <h1 class="text-2xl font-bold text-white mb-2">Memory System</h1>
        <p class="text-gray-400">Manage memory schemas and their tables, snapshots, and backups.</p>
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

    {{-- ============================================== --}}
    {{-- SECTION 1: Memory Schemas Management --}}
    {{-- ============================================== --}}
    <section class="bg-gray-800 rounded-lg p-6">
        <h2 class="text-lg font-semibold mb-4 text-white">Memory Schemas</h2>
        <p class="text-sm text-gray-400 mb-4">Each schema is an isolated PostgreSQL namespace for storing memory tables.</p>

        {{-- Schema List --}}
        @if($databases->count() > 0)
            <div class="space-y-2 mb-6">
                @foreach($databases as $db)
                    <div class="group flex flex-col sm:flex-row sm:items-center gap-2 p-3 rounded-lg {{ $selectedDatabase && $selectedDatabase->id === $db->id ? 'bg-blue-900/30 border border-blue-700' : 'bg-gray-700/50 hover:bg-gray-700' }}">
                        <a href="{{ route('config.memory', ['db' => $db->id]) }}" class="flex-1 min-w-0 block">
                            <div class="flex items-center gap-2">
                                <span class="font-medium {{ $selectedDatabase && $selectedDatabase->id === $db->id ? 'text-blue-300' : 'text-white group-hover:text-blue-300' }}">
                                    {{ $db->name }}
                                </span>
                                <code class="text-xs text-gray-500 bg-gray-800 px-1.5 py-0.5 rounded">{{ $db->getFullSchemaName() }}</code>
                                @if($selectedDatabase && $selectedDatabase->id === $db->id)
                                    <span class="text-xs text-blue-400 bg-blue-900/50 px-2 py-0.5 rounded">Selected</span>
                                @endif
                            </div>
                            @if($db->description)
                                <p class="text-sm text-gray-400 mt-1 truncate">{{ $db->description }}</p>
                            @endif
                        </a>
                        <span class="text-sm text-blue-400 whitespace-nowrap">
                            {{ $selectedDatabase && $selectedDatabase->id === $db->id ? 'Viewing' : 'Select' }}
                        </span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-6 text-gray-400 mb-6">
                <p>No memory schemas created yet.</p>
            </div>
        @endif

        {{-- Create New Schema --}}
        <div x-data="{ showCreate: false }" class="border-t border-gray-700 pt-4">
            <button
                @click="showCreate = !showCreate"
                class="flex items-center gap-2 text-sm text-blue-400 hover:text-blue-300"
            >
                <svg x-show="!showCreate" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <span x-text="showCreate ? 'Cancel' : 'Create New Schema'"></span>
            </button>

            <form
                x-show="showCreate"
                x-cloak
                method="POST"
                action="{{ route('config.memory.create-database') }}"
                class="mt-4 space-y-4"
            >
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="db_name" class="block text-sm font-medium text-gray-300 mb-1">Name <span class="text-red-400">*</span></label>
                        <input
                            type="text"
                            id="db_name"
                            name="name"
                            required
                            class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            placeholder="My Campaign"
                        >
                    </div>
                    <div>
                        <label for="db_schema_name" class="block text-sm font-medium text-gray-300 mb-1">Schema Suffix</label>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-500 text-sm">memory_</span>
                            <input
                                type="text"
                                id="db_schema_name"
                                name="schema_name"
                                pattern="[a-z][a-z0-9_]*"
                                class="flex-1 px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent font-mono"
                                placeholder="auto-generated"
                            >
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Lowercase letters/numbers/underscores. Leave blank to auto-generate.</p>
                    </div>
                </div>
                <div>
                    <label for="db_description" class="block text-sm font-medium text-gray-300 mb-1">Description</label>
                    <input
                        type="text"
                        id="db_description"
                        name="description"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="Optional description..."
                    >
                </div>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                    Create Schema
                </button>
            </form>
        </div>
    </section>

    {{-- ============================================== --}}
    {{-- SECTION 2: Selected Schema Details --}}
    {{-- ============================================== --}}
    @if($selectedDatabase)
    <section class="space-y-6">
        {{-- Schema Header --}}
        <div class="bg-gray-800 rounded-lg p-4" x-data="memoryDatabaseEditor()">
            <div class="flex flex-col sm:flex-row sm:items-start gap-4">
                <div class="flex-1 min-w-0">
                    <h2 class="text-lg font-semibold text-white mb-1">{{ $selectedDatabase->name }}</h2>
                    <code class="text-sm text-gray-500 bg-gray-700 px-2 py-0.5 rounded">{{ $selectedDatabase->getFullSchemaName() }}</code>

                    {{-- Description display/edit --}}
                    <div class="mt-3" x-show="!editing">
                        @if($selectedDatabase->description)
                            <p class="text-sm text-gray-300">{{ $selectedDatabase->description }}</p>
                        @else
                            <p class="text-sm text-gray-500 italic">No description</p>
                        @endif
                    </div>

                    {{-- Edit form --}}
                    <form
                        x-show="editing"
                        x-cloak
                        method="POST"
                        action="{{ route('config.memory.update-database', $selectedDatabase) }}"
                        class="mt-3 space-y-2"
                    >
                        @csrf
                        @method('PATCH')
                        <textarea
                            name="description"
                            x-ref="descriptionInput"
                            rows="2"
                            class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            placeholder="Describe this memory schema..."
                        >{{ $selectedDatabase->description }}</textarea>
                        <div class="flex gap-2">
                            <button type="submit" class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-xs rounded">
                                Save
                            </button>
                            <button type="button" @click="editing = false" class="px-3 py-1 bg-gray-600 hover:bg-gray-500 text-white text-xs rounded">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>

                {{-- Action buttons --}}
                <div x-show="!editing" class="flex items-center gap-3">
                    <button
                        @click="startEditing()"
                        class="text-sm text-blue-400 hover:text-blue-300 whitespace-nowrap"
                    >
                        Edit Description
                    </button>
                    <button
                        @click="showDeleteConfirm = true"
                        class="text-sm text-red-400 hover:text-red-300 whitespace-nowrap"
                    >
                        Delete
                    </button>
                </div>

                {{-- Delete confirmation modal --}}
                <div
                    x-show="showDeleteConfirm"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                    @click.self="showDeleteConfirm = false"
                    @keydown.escape.window="showDeleteConfirm = false"
                >
                    <div class="bg-gray-800 rounded-lg p-6 max-w-md w-full shadow-xl border border-gray-700" @click.stop>
                        <h3 class="text-lg font-semibold text-white mb-4">Delete Memory Schema</h3>

                        <p class="text-gray-300 mb-4">
                            Are you sure you want to delete <strong>{{ $selectedDatabase->name }}</strong>?
                        </p>

                        @php
                            $wsCount = $selectedDatabase->workspaces()->count();
                        @endphp

                        @if($wsCount > 0)
                            <div class="bg-yellow-900/50 border border-yellow-700 rounded p-3 mb-4">
                                <p class="text-yellow-200 text-sm">
                                    This schema is currently linked to <strong>{{ $wsCount }}</strong> workspace{{ $wsCount > 1 ? 's' : '' }}.
                                    It will be unlinked automatically.
                                </p>
                            </div>
                        @endif

                        <div class="bg-gray-700/50 border border-gray-600 rounded p-3 mb-4">
                            <p class="text-gray-300 text-sm">
                                The PostgreSQL schema and all data will be preserved. Only the database record is removed.
                            </p>
                        </div>

                        <form method="POST" action="{{ route('config.memory.delete-database', $selectedDatabase) }}">
                            @csrf
                            @method('DELETE')

                            <div class="flex gap-3 justify-end">
                                <button
                                    type="button"
                                    @click="showDeleteConfirm = false"
                                    class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white text-sm rounded"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded"
                                >
                                    Delete
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- User Tables --}}
        <div>
            <h3 class="text-md font-semibold mb-3 text-gray-200">Tables</h3>

            @if(empty($userTables))
                <div class="bg-gray-800 rounded-lg p-6 text-center">
                    <p class="text-gray-400 mb-2">No tables created yet.</p>
                    <p class="text-sm text-gray-500">Use <code class="bg-gray-700 px-2 py-1 rounded">memory:schema:create-table</code> to create tables.</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($userTables as $table)
                        <div class="bg-gray-800 rounded-lg p-4">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 mb-2">
                                <div class="min-w-0 flex-1">
                                    <h4 class="font-medium text-white break-words">{{ $table['table_name'] }}</h4>
                                    @if($table['description'])
                                        <p class="text-sm text-gray-400">{{ Str::limit($table['description'], 150) }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 flex-shrink-0">
                                    <a href="{{ route('config.memory.browse', $table['table_name']) }}?db={{ $selectedDatabase->id }}"
                                       class="text-sm text-blue-400 hover:text-blue-300 transition-colors">
                                        Browse
                                    </a>
                                    <span class="text-sm text-gray-500">{{ number_format($table['row_count']) }} rows</span>
                                </div>
                            </div>

                            @if(!empty($table['embeddable_fields']))
                                <div class="text-sm text-blue-400 mb-2">
                                    Auto-embed: {{ implode(', ', $table['embeddable_fields']) }}
                                </div>
                            @endif

                            {{-- System Prompt Preview --}}
                            @php
                                $schemaPrefix = $selectedDatabase->getFullSchemaName();
                                $rawMarkdown = "### {$schemaPrefix}.{$table['table_name']}\n";
                                if ($table['description']) {
                                    $rawMarkdown .= "{$table['description']}\n";
                                }
                                if (!empty($table['embeddable_fields'])) {
                                    $rawMarkdown .= "**Auto-embed:** " . implode(', ', $table['embeddable_fields']) . "\n";
                                }
                                if (!empty($table['columns'])) {
                                    $rawMarkdown .= "\n**Columns:**\n";
                                    foreach ($table['columns'] as $col) {
                                        $nullable = $col['nullable'] ? '' : ' NOT NULL';
                                        $desc = $col['description'] ? ": {$col['description']}" : '';
                                        $rawMarkdown .= "- `{$col['name']}` ({$col['type']}{$nullable}){$desc}\n";
                                    }
                                }
                            @endphp
                            <details class="mt-3" x-data="{ copied: false, markdown: {{ Illuminate\Support\Js::from($rawMarkdown) }} }">
                                <summary class="text-sm text-purple-400 cursor-pointer hover:text-purple-300 font-medium">
                                    Show System Prompt Preview
                                </summary>
                                <div
                                    class="mt-2 bg-gray-900 rounded p-3 overflow-x-auto cursor-pointer hover:bg-gray-800 transition-colors relative group"
                                    @click="
                                        navigator.clipboard.writeText(markdown);
                                        copied = true;
                                        setTimeout(() => copied = false, 2000);
                                    "
                                    title="Click to copy raw markdown"
                                >
                                    <div class="text-sm markdown-content" x-html="DOMPurify.sanitize(marked.parse(markdown))"></div>
                                    <div
                                        class="absolute top-2 right-2 text-xs px-2 py-1 rounded transition-opacity"
                                        :class="copied ? 'bg-green-600 text-white opacity-100' : 'bg-gray-700 text-gray-400 opacity-0 group-hover:opacity-100'"
                                        x-text="copied ? 'Copied!' : 'Click to copy'"
                                    ></div>
                                </div>
                            </details>

                            {{-- Database Structure --}}
                            @if(!empty($table['columns']))
                                <details class="mt-2">
                                    <summary class="text-sm text-gray-400 cursor-pointer hover:text-gray-300">
                                        Show Database Structure ({{ count($table['columns']) }} columns)
                                    </summary>
                                    <div class="mt-2 pl-4 border-l border-gray-700 space-y-1">
                                        @foreach($table['columns'] as $col)
                                            <div class="text-sm">
                                                <span class="text-gray-300">{{ $col['name'] }}</span>
                                                <span class="text-gray-500">({{ $col['type'] }}{{ $col['nullable'] ? '' : ' NOT NULL' }})</span>
                                                @if($col['description'])
                                                    <p class="text-gray-400 text-xs mt-1 ml-4">{{ $col['description'] }}</p>
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
        </div>

        {{-- System Tables --}}
        @if(count($systemTables) > 0)
        <div>
            <h3 class="text-md font-semibold mb-3 text-gray-200">System Tables</h3>
            <p class="text-sm text-gray-400 mb-3">Managed by PocketDev and cannot be dropped.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($systemTables as $table)
                    <div class="bg-gray-800 rounded-lg p-4 border border-yellow-900/50">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-2 mb-2">
                            <h4 class="font-medium text-yellow-400 break-words">{{ $table['table_name'] }}</h4>
                            <span class="text-xs text-yellow-600 bg-yellow-900/50 px-2 py-1 rounded w-fit">PROTECTED</span>
                        </div>
                        @if($table['description'])
                            <p class="text-sm text-gray-400">{{ $table['description'] }}</p>
                        @endif
                        <p class="text-sm text-gray-500 mt-2">{{ number_format($table['row_count']) }} rows</p>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Snapshots --}}
        <div>
            <h3 class="text-md font-semibold mb-3 text-gray-200">Snapshots</h3>
            <p class="text-sm text-gray-400 mb-3">
                Hourly snapshots created automatically. Tiered retention: hourly (24h), 4/day (7d), 1/day (30d).
            </p>

            <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 mb-4">
                <form method="POST" action="{{ route('config.memory.snapshots.create') }}">
                    @csrf
                    <button type="submit" class="w-full sm:w-auto px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                        Create Snapshot
                    </button>
                </form>

                <form method="POST" action="{{ route('config.memory.snapshots.create') }}">
                    @csrf
                    <input type="hidden" name="schema_only" value="1">
                    <button type="submit" class="w-full sm:w-auto px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white text-sm rounded">
                        Schema Only
                    </button>
                </form>
            </div>

            @if(empty($snapshots))
                <div class="bg-gray-800 rounded-lg p-4 text-center">
                    <p class="text-gray-400 text-sm">No snapshots yet. Hourly snapshots start once you have tables.</p>
                </div>
            @else
                <div class="space-y-4">
                    @foreach(['hourly' => 'Hourly (Last 24h)', 'daily-4' => 'Daily (Last 7d)', 'daily' => 'Archived (Last 30d)'] as $tier => $label)
                        @if(!empty($snapshotsByTier[$tier]))
                            <div>
                                <h4 class="text-sm font-medium text-gray-400 mb-2">{{ $label }}</h4>
                                <div class="bg-gray-800 rounded-lg divide-y divide-gray-700">
                                    @foreach($snapshotsByTier[$tier] as $snapshot)
                                        <div class="p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-white text-sm break-all">{{ $snapshot['filename'] }}</span>
                                                    @if($snapshot['schema_only'])
                                                        <span class="text-xs text-purple-400 bg-purple-900 px-2 py-0.5 rounded whitespace-nowrap">Schema Only</span>
                                                    @endif
                                                </div>
                                                <div class="text-xs text-gray-400 mt-1">
                                                    {{ \Carbon\Carbon::parse($snapshot['created_at'])->format('M j, Y g:i A') }}
                                                    &bull; {{ number_format($snapshot['size'] / 1024, 1) }} KB
                                                </div>
                                            </div>
                                            <div class="flex gap-2 flex-shrink-0">
                                                <form method="POST" action="{{ route('config.memory.snapshots.restore', $snapshot['filename']) }}" onsubmit="return confirm('Restore to this snapshot? Current state will be backed up first.')">
                                                    @csrf
                                                    <button type="submit" class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white text-xs rounded">
                                                        Restore
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('config.memory.snapshots.delete', $snapshot['filename']) }}" onsubmit="return confirm('Delete this snapshot?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-xs rounded">
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
        </div>

        {{-- Settings & Export/Import --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Settings --}}
            <div class="bg-gray-800 rounded-lg p-4">
                <h3 class="font-medium text-white mb-3">Settings</h3>
                <form method="POST" action="{{ route('config.memory.settings') }}" class="space-y-3">
                    @csrf
                    <div>
                        <label for="retention_days" class="block text-sm text-gray-300 mb-1">
                            Snapshot Retention (days)
                        </label>
                        <input
                            type="number"
                            id="retention_days"
                            name="retention_days"
                            value="{{ $retentionDays }}"
                            min="1"
                            max="365"
                            class="w-24 bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white text-sm focus:border-blue-500 focus:outline-none"
                        >
                    </div>
                    <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                        Save
                    </button>
                </form>
            </div>

            {{-- Export --}}
            <div class="bg-gray-800 rounded-lg p-4">
                <h3 class="font-medium text-white mb-3">Export</h3>
                <div class="space-y-2">
                    <a href="{{ route('config.memory.export', ['db' => $selectedDatabase->id]) }}" class="block w-full px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded text-center">
                        Full Backup
                    </a>
                    <a href="{{ route('config.memory.export', ['db' => $selectedDatabase->id, 'schema_only' => 1]) }}" class="block w-full px-3 py-2 bg-gray-600 hover:bg-gray-500 text-white text-sm rounded text-center">
                        Schema Only
                    </a>
                </div>
            </div>

            {{-- Import --}}
            <div class="bg-gray-800 rounded-lg p-4">
                <h3 class="font-medium text-white mb-3">Import</h3>
                <form method="POST" action="{{ route('config.memory.import') }}" enctype="multipart/form-data">
                    @csrf
                    <input
                        type="file"
                        name="snapshot_file"
                        accept=".sql,.txt"
                        required
                        class="block w-full text-xs text-gray-400 file:mr-2 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:bg-gray-700 file:text-white hover:file:bg-gray-600 mb-2"
                    >
                    <button type="submit" class="w-full px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-sm rounded">
                        Upload
                    </button>
                </form>
            </div>
        </div>
    </section>
    @else
        {{-- No schema selected message --}}
        <div class="bg-gray-800 rounded-lg p-8 text-center">
            <p class="text-gray-400">Select or create a memory schema above to manage its tables and snapshots.</p>
        </div>
    @endif
</div>

{{-- Markdown rendering dependencies --}}
<script src="https://cdn.jsdelivr.net/npm/marked@15.0.7/marked.min.js"
        integrity="sha384-H+hy9ULve6xfxRkWIh/YOtvDdpXgV2fmAGQkIDTxIgZwNoaoBal14Di2YTMR6MzR"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.3.1/dist/purify.min.js"
        integrity="sha384-80VlBZnyAwkkqtSfg5NhPyZff6nU4K/qniLBL8Jnm4KDv6jZhLiYtJbhglg/i9ww"
        crossorigin="anonymous"></script>
<script>
    function memoryDatabaseEditor() {
        return {
            editing: false,
            showDeleteConfirm: false,
            startEditing() {
                this.editing = true;
                this.$nextTick(() => {
                    this.$refs.descriptionInput?.focus();
                });
            }
        };
    }
</script>
<style>
    [x-cloak] { display: none !important; }
    .markdown-content { line-height: 1.6; }
    .markdown-content h1 { font-size: 1.5em; font-weight: bold; margin: 1em 0 0.5em; }
    .markdown-content h2 { font-size: 1.3em; font-weight: bold; margin: 1em 0 0.5em; }
    .markdown-content h3 { font-size: 1.1em; font-weight: bold; margin: 0.5em 0 0.5em; color: #e5e7eb; }
    .markdown-content p { margin: 0.5em 0; color: #d1d5db; }
    .markdown-content ul, .markdown-content ol { margin: 0.5em 0; padding-left: 2em; }
    .markdown-content li { margin: 0.25em 0; color: #d1d5db; }
    .markdown-content code { background: #374151; padding: 0.2em 0.4em; border-radius: 0.25em; font-size: 0.9em; color: #93c5fd; }
    .markdown-content pre { background: #1f2937; padding: 1em; border-radius: 0.5em; overflow-x: auto; margin: 1em 0; }
    .markdown-content pre code { background: none; padding: 0; }
    .markdown-content strong { color: #e5e7eb; }
</style>
@endsection
