{{-- Restore Chat Modal --}}
<x-modal show="showRestoreChatModal" title="Restore Archived Chat" max-width="sm">
    <div class="space-y-4">
        {{-- Loading state --}}
        <div x-show="loadingArchivedConversations" class="text-center py-4">
            <i class="fas fa-spinner fa-spin text-gray-400"></i>
            <p class="text-gray-400 text-sm mt-2">Loading archived chats...</p>
        </div>

        {{-- Empty state --}}
        <div x-show="!loadingArchivedConversations && archivedConversations.length === 0" class="text-center py-4">
            <i class="fas fa-box-open text-gray-500 text-2xl"></i>
            <p class="text-gray-400 text-sm mt-2">No archived chats in this session</p>
        </div>

        {{-- List of archived conversations --}}
        <div x-show="!loadingArchivedConversations && archivedConversations.length > 0" class="space-y-2 max-h-64 overflow-y-auto">
            <template x-for="conv in archivedConversations" :key="conv.id">
                <div class="flex items-center justify-between p-3 bg-gray-700 rounded-lg hover:bg-gray-650 transition-colors">
                    <div class="flex-1 min-w-0 mr-3">
                        <p class="text-sm text-white truncate" x-text="conv.title || 'Untitled'"></p>
                        <p class="text-xs text-gray-400" x-text="'Archived ' + formatDate(conv.archived_at)"></p>
                    </div>
                    <x-button
                        variant="secondary"
                        size="sm"
                        @click="restoreArchivedConversation(conv.id)"
                    >
                        <i class="fas fa-rotate-left mr-1"></i>
                        Restore
                    </x-button>
                </div>
            </template>
        </div>

        {{-- Close button --}}
        <div class="flex justify-end pt-2">
            <x-button variant="secondary" @click="closeRestoreChatModal()">
                Close
            </x-button>
        </div>
    </div>
</x-modal>
