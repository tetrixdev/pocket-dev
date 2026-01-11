{{-- Rename Conversation Modal --}}
<x-modal show="showRenameModal" title="Rename Conversation" max-width="sm">
    <div class="space-y-4">
        <x-text-input
            type="text"
            x-model="renameTitle"
            x-ref="renameTitleInput"
            @keydown.enter="saveConversationTitle()"
            @keydown.escape="showRenameModal = false"
            placeholder="Enter conversation name..."
            label="Title"
            maxlength="30"
        />

        <p class="text-gray-500 text-xs">
            Maximum 30 characters. This name will appear in the header and sidebar.
        </p>

        <div class="flex gap-2">
            <x-button variant="secondary" class="flex-1" @click="showRenameModal = false">
                Cancel
            </x-button>
            <x-button
                variant="primary"
                class="flex-1"
                @click="saveConversationTitle()"
                :disabled="!renameTitle.trim() || renameSaving"
            >
                <span x-show="!renameSaving">Save</span>
                <span x-show="renameSaving">Saving...</span>
            </x-button>
        </div>
    </div>
</x-modal>
