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
                            pattern="[a-z0-9\-]+"
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

                    <!-- Warning about affected agents -->
                    <div
                        x-show="schemaWarning.show"
                        x-cloak
                        class="p-3 bg-yellow-900/50 border border-yellow-700 rounded mb-3"
                    >
                        <div class="flex items-start gap-2">
                            <svg class="w-5 h-5 text-yellow-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <div class="flex-1 min-w-0">
                                <p class="text-yellow-200 text-sm font-medium">
                                    Disabling "<span x-text="schemaWarning.schemaName"></span>" will affect <span x-text="schemaWarning.affectedCount"></span> agent<span x-show="schemaWarning.affectedCount !== 1">s</span>:
                                </p>
                                <p class="text-yellow-300/80 text-xs mt-1" x-text="schemaWarning.agentNames"></p>
                                <p class="text-yellow-400/70 text-xs mt-1">These agents will lose access to this schema when you save.</p>
                            </div>
                            <button
                                type="button"
                                @click="schemaWarning.show = false"
                                class="text-yellow-400 hover:text-yellow-200"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

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
                                    @change="onSchemaChange({{ Js::from($db->id) }}, {{ Js::from($db->name) }}, $event.target.checked)"
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

                    <!-- Tool groups (shown when not all tools) -->
                    <div x-show="!allToolsEnabled" class="space-y-4">
                        <!-- Native Tools: Claude Code -->
                        <div x-show="toolGroups.claudeCode.length > 0">
                            <div class="flex items-center gap-2 mb-2">
                                <input
                                    type="checkbox"
                                    :checked="isGroupFullyEnabled('claudeCode')"
                                    x-effect="$el.indeterminate = isGroupPartiallyEnabled('claudeCode')"
                                    @change="toggleGroup('claudeCode')"
                                    class="w-3.5 h-3.5 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500"
                                >
                                <h4 class="text-sm font-semibold text-gray-300">
                                    Native Tools: Claude Code
                                    <span class="text-gray-500 font-normal" x-text="'(' + getGroupEnabledCount('claudeCode') + '/' + toolGroups.claudeCode.length + ')'"></span>
                                </h4>
                            </div>
                            <div class="flex flex-wrap gap-2 ml-5">
                                <template x-for="tool in toolGroups.claudeCode" :key="tool.slug">
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

                        <!-- Native Tools: Codex -->
                        <div x-show="toolGroups.codex.length > 0">
                            <div class="flex items-center gap-2 mb-2">
                                <input
                                    type="checkbox"
                                    :checked="isGroupFullyEnabled('codex')"
                                    x-effect="$el.indeterminate = isGroupPartiallyEnabled('codex')"
                                    @change="toggleGroup('codex')"
                                    class="w-3.5 h-3.5 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500"
                                >
                                <h4 class="text-sm font-semibold text-gray-300">
                                    Native Tools: Codex
                                    <span class="text-gray-500 font-normal" x-text="'(' + getGroupEnabledCount('codex') + '/' + toolGroups.codex.length + ')'"></span>
                                </h4>
                            </div>
                            <div class="flex flex-wrap gap-2 ml-5">
                                <template x-for="tool in toolGroups.codex" :key="tool.slug">
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

                        <!-- PocketDev Tools (grouped by category) -->
                        <template x-for="(tools, category) in groupedPocketdevTools" :key="category">
                            <div>
                                <div class="flex items-center gap-2 mb-2">
                                    <input
                                        type="checkbox"
                                        :checked="isCategoryFullyEnabled(category)"
                                        x-effect="$el.indeterminate = isCategoryPartiallyEnabled(category)"
                                        @change="toggleCategory(category)"
                                        class="w-3.5 h-3.5 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500"
                                    >
                                    <h4 class="text-sm font-semibold text-gray-300">
                                        <span x-text="getCategoryTitle(category)"></span>
                                        <span class="text-gray-500 font-normal" x-text="'(' + getCategoryEnabledCount(category) + '/' + tools.length + ')'"></span>
                                    </h4>
                                </div>
                                <div class="flex flex-wrap gap-2 ml-5">
                                    <template x-for="tool in tools" :key="tool.slug">
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

                        <!-- User Tools -->
                        <div x-show="toolGroups.user.length > 0">
                            <div class="flex items-center gap-2 mb-2">
                                <input
                                    type="checkbox"
                                    :checked="isGroupFullyEnabled('user')"
                                    x-effect="$el.indeterminate = isGroupPartiallyEnabled('user')"
                                    @change="toggleGroup('user')"
                                    class="w-3.5 h-3.5 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500"
                                >
                                <h4 class="text-sm font-semibold text-gray-300">
                                    User Tools
                                    <span class="text-gray-500 font-normal" x-text="'(' + getGroupEnabledCount('user') + '/' + toolGroups.user.length + ')'"></span>
                                </h4>
                            </div>
                            <div class="flex flex-wrap gap-2 ml-5">
                                <template x-for="tool in toolGroups.user" :key="tool.slug">
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
                    </div>

                    <p class="text-xs text-gray-400 mt-2" x-text="allToolsEnabled ? 'All tools are enabled for this workspace.' : 'Select which tools are available for agents in this workspace.'"></p>
                </div>

                <!-- Hidden inputs for disabled tools -->
                <template x-for="slug in disabledTools" :key="slug">
                    <input type="hidden" name="disabled_tools[]" :value="slug">
                </template>
            </div>

            <!-- System Packages -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">System Packages</h3>

                @if(empty($allPackages ?? []))
                    <p class="text-gray-400 text-sm">No system packages available. <a href="{{ route('config.environment') }}" class="text-blue-400 hover:text-blue-300">Install packages</a> in the Environment section.</p>
                @else
                    <p class="text-sm text-gray-400 mb-3">Select which packages appear in this workspace's system prompt. Unselected packages are still installed, the AI just won't be told about them.</p>

                    <div class="flex items-center gap-3 p-3 bg-gray-800 border border-gray-700 rounded mb-4">
                        <input
                            type="checkbox"
                            x-model="allPackagesVisible"
                            @change="onAllPackagesChange()"
                            class="w-4 h-4 rounded border-gray-700 bg-gray-800 text-blue-500 focus:ring-blue-500"
                        >
                        <div>
                            <span class="text-white font-medium">Show all packages</span>
                            <p class="text-xs text-gray-400">When enabled, all installed packages appear in the system prompt. Disable to select specific packages.</p>
                        </div>
                    </div>

                    <div x-show="!allPackagesVisible" class="space-y-2">
                        <div class="flex flex-wrap gap-2">
                            @foreach($allPackages ?? [] as $package)
                                <label class="flex items-center gap-1.5 px-2 py-1 bg-gray-800 border border-gray-700 rounded cursor-pointer hover:border-gray-600">
                                    <input
                                        type="checkbox"
                                        name="selected_packages[]"
                                        value="{{ $package }}"
                                        :checked="selectedPackages.includes('{{ $package }}')"
                                        @change="togglePackage('{{ $package }}')"
                                        class="w-3.5 h-3.5 rounded border-gray-700 bg-gray-900 text-green-500 focus:ring-green-500"
                                    >
                                    <code class="text-sm text-green-400">{{ $package }}</code>
                                </label>
                            @endforeach
                        </div>
                        <p class="text-xs text-gray-500">Selected: <span x-text="selectedPackages.length"></span> of {{ count($allPackages ?? []) }}</p>
                    </div>

                    <p class="text-xs text-gray-400 mt-2">
                        <a href="{{ route('config.environment') }}" class="text-blue-400 hover:text-blue-300">Manage packages</a> in the Environment section.
                    </p>
                @endif
            </div>

            @if(isset($workspace))
            <!-- Credentials -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">Credentials</h3>

                @if(($workspaceCredentials ?? collect())->isEmpty())
                    <p class="text-gray-400 text-sm">No credentials configured for this workspace. <a href="{{ route('config.environment') }}" class="text-blue-400 hover:text-blue-300">Add credentials</a> in the Environment section.</p>
                @else
                    <p class="text-sm text-gray-400 mb-3">Credentials available to this workspace (global + workspace-specific):</p>

                    <div class="bg-gray-800 rounded-lg overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-750">
                                <tr>
                                    <th class="px-3 py-2 text-left text-gray-400 font-medium">Env Variable</th>
                                    <th class="px-3 py-2 text-left text-gray-400 font-medium">Scope</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-700">
                                @foreach($workspaceCredentials as $credential)
                                    <tr>
                                        <td class="px-3 py-2">
                                            <code class="text-blue-400 bg-gray-900 px-1.5 py-0.5 rounded">{{ $credential->env_var }}</code>
                                        </td>
                                        <td class="px-3 py-2">
                                            @if($credential->workspace_id === $workspace->id)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-900/50 text-purple-300">
                                                    This workspace
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-700 text-gray-300">
                                                    Global
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="text-xs text-gray-400">
                    <a href="{{ route('config.environment') }}" class="text-blue-400 hover:text-blue-300">Manage credentials</a> in the Environment section. Workspace-specific credentials override global ones with the same env var.
                </p>
            </div>
            @endif

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
    <!-- Delete workspace form -->
    <form method="POST" action="{{ route('config.workspaces.delete', $workspace) }}" class="mt-6">
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
            toolGroups: {
                claudeCode: [],
                codex: [],
                user: [],
            },
            groupedPocketdevTools: {},
            disabledTools: @js($disabledToolSlugs ?? []),
            allToolsEnabled: @js(empty($disabledToolSlugs ?? [])),

            // Package selection state
            selectedPackages: @js($selectedPackages ?? []),
            allPackagesVisible: @js(empty($selectedPackages ?? [])),

            // Schema warning state
            workspaceId: @js($workspace->id ?? null),
            schemaWarning: {
                show: false,
                schemaName: '',
                affectedCount: 0,
                agentNames: '',
            },

            categoryTitles: {
                'memory_data': 'Memory Data',
                'memory_schema': 'Memory Schema',
                'tools': 'Tool Management',
                'file_ops': 'File Operations',
                'custom': 'Custom Tools',
                'conversation': 'Conversation',
            },

            async init() {
                await this.fetchTools();
            },

            async fetchTools() {
                this.toolsLoading = true;
                try {
                    const response = await fetch('/api/tools');
                    const data = await response.json();

                    // Native tools
                    this.toolGroups.claudeCode = data.native?.claude_code || [];
                    this.toolGroups.codex = data.native?.codex || [];

                    // User tools
                    this.toolGroups.user = data.user || [];

                    // PocketDev tools grouped by category
                    const pocketdevTools = data.pocketdev || [];
                    this.groupedPocketdevTools = pocketdevTools.reduce((acc, tool) => {
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
                return this.categoryTitles[category] || category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
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

            // Group toggle functions
            getGroupTools(groupName) {
                return this.toolGroups[groupName] || [];
            },

            getGroupEnabledCount(groupName) {
                const tools = this.getGroupTools(groupName);
                return tools.filter(t => this.isToolEnabled(t.slug)).length;
            },

            isGroupFullyEnabled(groupName) {
                const tools = this.getGroupTools(groupName);
                if (tools.length === 0) return false;
                return tools.every(t => this.isToolEnabled(t.slug));
            },

            isGroupPartiallyEnabled(groupName) {
                const tools = this.getGroupTools(groupName);
                if (tools.length === 0) return false;
                const enabledCount = this.getGroupEnabledCount(groupName);
                return enabledCount > 0 && enabledCount < tools.length;
            },

            toggleGroup(groupName) {
                const tools = this.getGroupTools(groupName);
                const isFullyEnabled = this.isGroupFullyEnabled(groupName);

                tools.forEach(tool => {
                    const idx = this.disabledTools.indexOf(tool.slug);
                    if (isFullyEnabled) {
                        // Disable all tools in group
                        if (idx === -1) {
                            this.disabledTools.push(tool.slug);
                        }
                    } else {
                        // Enable all tools in group
                        if (idx > -1) {
                            this.disabledTools.splice(idx, 1);
                        }
                    }
                });
            },

            // Category toggle functions (for PocketDev tools)
            getCategoryTools(category) {
                return this.groupedPocketdevTools[category] || [];
            },

            getCategoryEnabledCount(category) {
                const tools = this.getCategoryTools(category);
                return tools.filter(t => this.isToolEnabled(t.slug)).length;
            },

            isCategoryFullyEnabled(category) {
                const tools = this.getCategoryTools(category);
                if (tools.length === 0) return false;
                return tools.every(t => this.isToolEnabled(t.slug));
            },

            isCategoryPartiallyEnabled(category) {
                const tools = this.getCategoryTools(category);
                if (tools.length === 0) return false;
                const enabledCount = this.getCategoryEnabledCount(category);
                return enabledCount > 0 && enabledCount < tools.length;
            },

            toggleCategory(category) {
                const tools = this.getCategoryTools(category);
                const isFullyEnabled = this.isCategoryFullyEnabled(category);

                tools.forEach(tool => {
                    const idx = this.disabledTools.indexOf(tool.slug);
                    if (isFullyEnabled) {
                        // Disable all tools in category
                        if (idx === -1) {
                            this.disabledTools.push(tool.slug);
                        }
                    } else {
                        // Enable all tools in category
                        if (idx > -1) {
                            this.disabledTools.splice(idx, 1);
                        }
                    }
                });
            },

            onAllToolsChange() {
                if (this.allToolsEnabled) {
                    // Clear all disabled tools
                    this.disabledTools = [];
                }
            },

            async onSchemaChange(schemaId, schemaName, isChecked) {
                // Only check for affected agents when unchecking on an existing workspace
                if (isChecked || !this.workspaceId) {
                    this.schemaWarning.show = false;
                    return;
                }

                try {
                    const response = await fetch('/api/agents/check-schema-affected', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({
                            workspace_id: this.workspaceId,
                            schema_id: schemaId,
                        }),
                    });

                    const data = await response.json();

                    if (data.affected_count > 0) {
                        this.schemaWarning = {
                            show: true,
                            schemaName: schemaName,
                            affectedCount: data.affected_count,
                            agentNames: data.agents.map(a => a.name).join(', '),
                        };
                    } else {
                        this.schemaWarning.show = false;
                    }
                } catch (error) {
                    console.error('Failed to check affected agents:', error);
                }
            },

            // Package selection functions
            togglePackage(packageName) {
                const idx = this.selectedPackages.indexOf(packageName);
                if (idx > -1) {
                    this.selectedPackages.splice(idx, 1);
                } else {
                    this.selectedPackages.push(packageName);
                }
            },

            onAllPackagesChange() {
                if (this.allPackagesVisible) {
                    // Clear selection (show all packages)
                    this.selectedPackages = [];
                }
            }
        };
    }
</script>
@endpush
