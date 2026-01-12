@extends('layouts.config')

@section('title', 'Configure Import')

@section('content')
<div class="space-y-6">
    <div>
        <a href="{{ route('config.memory') }}" class="text-blue-400 hover:text-blue-300 text-sm mb-2 inline-block">&larr; Back to Memory</a>
        <h1 class="text-2xl font-bold text-white mb-2">Configure Import</h1>
        <p class="text-gray-400">Configure how to import the uploaded memory schema.</p>
    </div>

    @if(session('error'))
        <div class="bg-red-900 border border-red-700 text-red-200 px-4 py-3 rounded">
            {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="bg-green-900 border border-green-700 text-green-200 px-4 py-3 rounded">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-gray-800 rounded-lg p-6" x-data="importForm()">
        <form method="POST" action="{{ route('config.memory.import.apply') }}">
            @csrf

            {{-- File info --}}
            <div class="mb-6">
                <h3 class="text-sm font-medium text-gray-300 mb-2">Uploaded File</h3>
                <code class="text-sm text-blue-400 bg-gray-700 px-2 py-1 rounded">{{ $filename }}</code>
            </div>

            {{-- Source Schema --}}
            <div class="mb-6">
                <label for="source_schema" class="block text-sm font-medium text-gray-300 mb-2">
                    Source Schema (in file) <span class="text-red-400">*</span>
                </label>
                <input
                    type="text"
                    id="source_schema"
                    name="source_schema"
                    value="{{ old('source_schema', $detectedSchema) }}"
                    required
                    pattern="memory_[a-z][a-z0-9_]*"
                    class="w-full max-w-md px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent font-mono"
                    placeholder="memory_example"
                >
                @if($detectedSchema)
                    <p class="text-xs text-green-400 mt-1">Auto-detected from file</p>
                @else
                    <p class="text-xs text-yellow-400 mt-1">Could not auto-detect. Please enter the schema name from your SQL file.</p>
                @endif
                @error('source_schema')
                    <p class="text-xs text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Target Schema --}}
            <div class="mb-6">
                <label for="target_schema" class="block text-sm font-medium text-gray-300 mb-2">
                    Import As Schema <span class="text-red-400">*</span>
                </label>
                <input
                    type="text"
                    id="target_schema"
                    name="target_schema"
                    x-model="targetSchema"
                    required
                    pattern="memory_[a-z][a-z0-9_]*"
                    class="w-full max-w-md px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent font-mono"
                    :class="{ 'border-yellow-500': schemaExists && !overwrite }"
                    placeholder="memory_example"
                >
                <p class="text-xs text-gray-400 mt-1">
                    Must start with <code class="bg-gray-600 px-1 rounded">memory_</code> followed by lowercase letters/numbers/underscores.
                </p>
                <p x-show="schemaExists && !overwrite" x-cloak class="text-xs text-yellow-400 mt-1">
                    This schema already exists. Enable "Overwrite" below to replace it.
                </p>
                @error('target_schema')
                    <p class="text-xs text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Overwrite option --}}
            <div class="mb-6">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        name="overwrite"
                        value="1"
                        x-model="overwrite"
                        class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-500 focus:ring-blue-500"
                    >
                    <span class="text-sm text-gray-300">Overwrite existing schema (creates backup first)</span>
                </label>
            </div>

            {{-- Warning for overwrite --}}
            <div x-show="schemaExists && overwrite" x-cloak class="mb-6 p-4 bg-red-900/50 border border-red-700 rounded">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div>
                        <p class="text-red-200 font-medium">Warning: This will delete all data in the existing schema!</p>
                        <p class="text-red-300 text-sm mt-1">A backup will be created automatically before overwriting.</p>
                    </div>
                </div>
            </div>

            {{-- Info box --}}
            <div class="mb-6 p-4 bg-gray-700/50 border border-gray-600 rounded">
                <h4 class="text-sm font-medium text-gray-200 mb-2">What happens during import:</h4>
                <ul class="text-sm text-gray-400 space-y-1 list-disc list-inside">
                    <li>Schema references in the SQL file will be transformed to match the target schema</li>
                    <li>If overwriting, a backup of the existing schema will be created first</li>
                    <li>Table names, indexes, and permissions will be updated automatically</li>
                </ul>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                    Import Schema
                </button>
                <button
                    type="button"
                    onclick="document.getElementById('cancel-form').submit();"
                    class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white text-sm rounded"
                >
                    Cancel
                </button>
            </div>
        </form>

        <form id="cancel-form" method="POST" action="{{ route('config.memory.import.cancel') }}" style="display: none;">
            @csrf
        </form>
    </div>
</div>

<script>
    function importForm() {
        return {
            targetSchema: @js(old('target_schema', $detectedSchema ?? '')),
            overwrite: {{ old('overwrite') ? 'true' : 'false' }},
            existingSchemas: @js($existingSchemas),

            get schemaExists() {
                return this.existingSchemas.includes(this.targetSchema);
            }
        };
    }
</script>
<style>[x-cloak] { display: none !important; }</style>
@endsection
