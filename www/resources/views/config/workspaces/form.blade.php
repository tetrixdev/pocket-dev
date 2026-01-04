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

            <!-- Memory Schemas -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">Memory Schemas</h3>

                @if($memoryDatabases->isEmpty())
                    <p class="text-gray-400 text-sm">No memory schemas available. Create one in the Memory section.</p>
                @else
                    <p class="text-sm text-gray-400 mb-3">Select memory schemas to make available for agents in this workspace:</p>

                    <div class="space-y-2">
                        @foreach($memoryDatabases as $db)
                            @php
                                $isEnabled = isset($enabledDbIds) && in_array($db->id, $enabledDbIds);
                            @endphp
                            <label class="flex items-center gap-3 p-3 bg-gray-800 border border-gray-700 rounded cursor-pointer hover:bg-gray-750">
                                <input
                                    type="checkbox"
                                    name="memory_databases[]"
                                    value="{{ $db->id }}"
                                    {{ old('memory_databases') ? (in_array($db->id, old('memory_databases', [])) ? 'checked' : '') : ($isEnabled ? 'checked' : '') }}
                                    class="w-4 h-4 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500"
                                >
                                <div class="flex-1">
                                    <span class="text-white font-medium">{{ $db->name }}</span>
                                    <code class="text-gray-500 text-xs ml-2 bg-gray-700 px-1.5 py-0.5 rounded">{{ $db->schema_name }}</code>
                                    @if($db->description)
                                        <p class="text-xs text-gray-400 mt-0.5">{{ Str::limit($db->description, 100) }}</p>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>

                    <p class="text-xs text-gray-400">
                        Enabled schemas become available for agents to select. Agents must explicitly enable each schema they need.
                    </p>
                @endif
            </div>

            <!-- Tool Selection -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">Tools</h3>

                <!-- Loading state -->
                <div x-show="toolsLoading" class="flex items-center gap-2 text-gray-400">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm">Loading tools...</span>
                </div>

                <div x-show="!toolsLoading">
                    <!-- Allow all tools toggle -->
                    <div class="flex items-center gap-3 p-3 bg-gray-800 border border-gray-700 rounded mb-4">
                        <input
                            type="checkbox"
                            x-model="allToolsEnabled"
                            @change="onAllToolsChange()"
                            class="w-4 h-4 rounded border-gray-700 bg-gray-800 text-blue-500 focus:ring-blue-500"
                        >
                        <div>
                            <span class="text-white font-medium">Allow all tools</span>
                            <p class="text-xs text-gray-400">When enabled, all tools are available. Disable to restrict specific tools.</p>
                        </div>
                    </div>

                    <!-- Tool categories (shown when not all tools) -->
                    <div x-show="!allToolsEnabled" class="space-y-4">
                        <template x-for="(categoryTools, category) in groupedTools" :key="category">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-300 mb-2" x-text="getCategoryTitle(category)"></h4>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="tool in categoryTools" :key="tool.slug">
                                        <label class="flex items-center gap-1.5 px-2 py-1 bg-gray-800 border border-gray-700 rounded cursor-pointer hover:border-gray-600" :title="tool.description">
                                            <input
                                                type="checkbox"
                                                :checked="isToolEnabled(tool.slug)"
                                                @change="toggleTool(tool.slug)"
                                                class="w-3.5 h-3.5 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500"
                                            >
                                            <span class="text-sm text-gray-200" x-text="tool.name"></span>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <p class="text-xs text-gray-400 mt-2" x-text="allToolsEnabled ? 'All tools are enabled for this workspace.' : 'Select which tools are available for agents in this workspace.'"></p>
                </div>

                <!-- Hidden inputs for disabled tools -->
                <template x-for="slug in disabledTools" :key="slug">
                    <input type="hidden" name="disabled_tools[]" :value="slug">
                </template>
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
            toolsLoading: true,
            allTools: [],
            groupedTools: {},
            disabledTools: @js($disabledToolSlugs ?? []),
            allToolsEnabled: @js(empty($disabledToolSlugs ?? [])),

            categoryTitles: {
                'memory_data': 'Memory Data',
                'memory_schema': 'Memory Schema',
                'tools': 'Tool Management',
                'file_ops': 'File Operations',
                'custom': 'Custom Tools',
            },

            async init() {
                await this.fetchTools();
            },

            async fetchTools() {
                this.toolsLoading = true;
                try {
                    const response = await fetch('/api/tools');
                    const data = await response.json();
                    this.allTools = data.tools || [];

                    // Group tools by category
                    this.groupedTools = this.allTools.reduce((acc, tool) => {
                        const category = tool.category || 'custom';
                        if (!acc[category]) acc[category] = [];
                        acc[category].push(tool);
                        return acc;
                    }, {});
                } catch (error) {
                    console.error('Failed to fetch tools:', error);
                } finally {
                    this.toolsLoading = false;
                }
            },

            getCategoryTitle(category) {
                return this.categoryTitles[category] || category.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
            },

            isToolEnabled(slug) {
                return !this.disabledTools.includes(slug);
            },

            toggleTool(slug) {
                const idx = this.disabledTools.indexOf(slug);
                if (idx > -1) {
                    // Tool was disabled, enable it
                    this.disabledTools.splice(idx, 1);
                } else {
                    // Tool was enabled, disable it
                    this.disabledTools.push(slug);
                }
            },

            onAllToolsChange() {
                if (this.allToolsEnabled) {
                    // Clear all disabled tools
                    this.disabledTools = [];
                }
            }
        };
    }
</script>
@endpush
