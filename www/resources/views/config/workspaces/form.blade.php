@extends('layouts.config')

@section('title', isset($workspace) ? 'Edit Workspace: ' . $workspace->name : 'Create Workspace')

@section('content')
<div x-data="workspaceForm()" x-init="init()">
    <form
        method="POST"
        action="{{ isset($workspace) ? route('config.workspaces.update', $workspace) : route('config.workspaces.store') }}"
    >
        @csrf
        @if(isset($workspace))
            @method('PUT')
        @endif

        <div class="space-y-6">
            <!-- Basic Information -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">Basic Information</h3>

                <!-- Name -->
                <div>
                    <label for="name" class="block text-sm font-medium mb-2">Name <span class="text-red-400 font-bold">*</span></label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name', $workspace->name ?? '') }}"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        required
                        placeholder="My Project"
                    >
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium mb-2">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        rows="2"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="Describe what this workspace is for..."
                    >{{ old('description', $workspace->description ?? '') }}</textarea>
                </div>

                <!-- Directory -->
                <div>
                    <label for="directory" class="block text-sm font-medium mb-2">Directory</label>
                    <div class="flex items-center gap-2">
                        <span class="text-gray-400 text-sm">/workspace/</span>
                        <input
                            type="text"
                            id="directory"
                            name="directory"
                            value="{{ old('directory', $workspace->directory ?? '') }}"
                            class="flex-1 px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent font-mono"
                            placeholder="auto-generated from name"
                            pattern="[a-z0-9-]+"
                        >
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Lowercase letters, numbers, and hyphens only. Leave blank to auto-generate from name.</p>
                </div>
            </div>

            <!-- Memory Databases -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">Memory Databases</h3>

                @if($memoryDatabases->isEmpty())
                    <p class="text-gray-400 text-sm">No memory databases available. Create one in the Memory section.</p>
                @else
                    <p class="text-sm text-gray-400 mb-3">Select memory databases to enable for this workspace:</p>

                    <div class="space-y-2">
                        @foreach($memoryDatabases as $db)
                            @php
                                $isEnabled = isset($enabledDbIds) && in_array($db->id, $enabledDbIds);
                                $isDefault = isset($defaultDbId) && $defaultDbId === $db->id;
                            @endphp
                            <div class="flex items-center gap-4 p-3 bg-gray-800 border border-gray-700 rounded">
                                <label class="flex items-center gap-2 cursor-pointer flex-1">
                                    <input
                                        type="checkbox"
                                        name="memory_databases[]"
                                        value="{{ $db->id }}"
                                        {{ old('memory_databases') ? (in_array($db->id, old('memory_databases', [])) ? 'checked' : '') : ($isEnabled ? 'checked' : '') }}
                                        @change="onMemoryDbChange('{{ $db->id }}')"
                                        x-ref="memdb_{{ $db->id }}"
                                        class="w-4 h-4 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500"
                                    >
                                    <div>
                                        <span class="text-white font-medium">{{ $db->name }}</span>
                                        <span class="text-gray-500 text-xs ml-2">{{ $db->schema_name }}</span>
                                        @if($db->description)
                                            <p class="text-xs text-gray-400 mt-0.5">{{ Str::limit($db->description, 100) }}</p>
                                        @endif
                                    </div>
                                </label>

                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <input
                                        type="radio"
                                        name="default_memory_database"
                                        value="{{ $db->id }}"
                                        {{ old('default_memory_database', $defaultDbId ?? '') === $db->id ? 'checked' : '' }}
                                        x-ref="memdb_default_{{ $db->id }}"
                                        class="w-4 h-4 border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500"
                                    >
                                    <span class="text-gray-400">Default</span>
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <p class="text-xs text-gray-400">
                        The default memory database will be used when a conversation doesn't specify one.
                    </p>
                @endif
            </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-3 mt-6 pt-4 border-t border-gray-700">
            <x-button type="submit" variant="primary">
                {{ isset($workspace) ? 'Update Workspace' : 'Create Workspace' }}
            </x-button>
            <a href="{{ route('config.workspaces') }}">
                <x-button type="button" variant="secondary">
                    Cancel
                </x-button>
            </a>
        </div>
    </form>

    @if(isset($workspace))
        <!-- Separate delete form -->
        <form method="POST" action="{{ route('config.workspaces.delete', $workspace) }}" class="mt-4">
            @csrf
            @method('DELETE')
            <x-button type="submit" variant="danger" onclick="return confirm('Are you sure you want to delete this workspace? This action cannot be undone.')">
                Delete Workspace
            </x-button>
        </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
    function workspaceForm() {
        return {
            init() {
                // Nothing to initialize for now
            },

            onMemoryDbChange(dbId) {
                // If unchecking a memory db, also uncheck default
                const checkbox = this.$refs['memdb_' + dbId];
                const defaultRadio = this.$refs['memdb_default_' + dbId];
                if (checkbox && defaultRadio && !checkbox.checked && defaultRadio.checked) {
                    defaultRadio.checked = false;
                }
            }
        };
    }
</script>
@endpush
