{{-- Workspace Selector Modal --}}
<x-modal show="showWorkspaceSelector" title="Select Workspace" max-width="md">
    <div class="space-y-4">
        {{-- Loading State --}}
        <template x-if="workspacesLoading">
            <div class="text-center py-8">
                <p class="text-gray-400">Loading workspaces...</p>
            </div>
        </template>

        {{-- Empty State --}}
        <template x-if="!workspacesLoading && workspaces.length === 0">
            <div class="text-center py-8">
                <p class="text-gray-400 mb-4">No workspaces available.</p>
                <a href="{{ route('config.workspaces') }}" class="text-blue-400 hover:underline text-sm">
                    Create a workspace in settings
                </a>
            </div>
        </template>

        {{-- Workspace List --}}
        <template x-if="!workspacesLoading && workspaces.length > 0">
            <div class="space-y-2 max-h-[60vh] overflow-y-auto pr-2">
                <template x-for="workspace in workspaces" :key="workspace.id">
                    <button
                        @click="selectWorkspace(workspace)"
                        class="w-full text-left p-3 rounded-lg border transition-all"
                        :class="currentWorkspaceId === workspace.id
                            ? 'bg-blue-600/20 border-blue-500 text-white'
                            : 'bg-gray-800 border-gray-700 hover:border-gray-600 text-gray-200'"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium" x-text="workspace.name"></span>
                                </div>
                                <div class="text-xs text-gray-400 mt-1">
                                    <span class="font-mono bg-gray-700/50 px-1 rounded" x-text="'/workspace/' + workspace.directory"></span>
                                </div>
                                <p x-show="workspace.description" class="text-xs text-gray-400 mt-1 line-clamp-2" x-text="workspace.description"></p>
                                <div class="text-xs text-gray-500 mt-1">
                                    <span x-text="workspace.agents_count + ' agents'"></span>
                                    <span class="mx-1">|</span>
                                    <span x-text="workspace.conversations_count + ' conversations'"></span>
                                </div>
                            </div>
                            <template x-if="currentWorkspaceId === workspace.id">
                                <svg class="w-5 h-5 text-blue-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            </template>
                        </div>
                    </button>
                </template>
            </div>
        </template>

        {{-- Footer --}}
        <div class="flex justify-between items-center pt-3 border-t border-gray-700">
            <a href="{{ route('config.workspaces') }}" class="text-xs text-gray-400 hover:text-gray-300">
                Manage Workspaces
            </a>
            <x-button variant="secondary" @click="showWorkspaceSelector = false">
                Close
            </x-button>
        </div>
    </div>
</x-modal>
