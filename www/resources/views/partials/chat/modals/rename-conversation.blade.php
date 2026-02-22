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
            x-bind:maxlength="window.TITLE_MAX_LENGTH"
        />

        <x-text-input
            type="text"
            x-model="renameTabLabel"
            @keydown.enter="saveConversationTitle()"
            @keydown.escape="showRenameModal = false"
            placeholder="e.g. Debug"
            label="Tab Label (optional)"
            hint="Max 6 characters. Shown in screen tabs."
            maxlength="6"
        />

        {{-- Tab preview --}}
        <div class="text-sm">
            <span class="text-gray-400 text-xs">Tab preview:</span>
            <div class="mt-1">
                <span class="inline-flex items-center gap-1.5 px-2 py-1 bg-gray-700 rounded text-sm font-medium">
                    <i class="fa-solid fa-comment text-[10px] text-gray-400"></i>
                    <span x-text="renameTabLabel.trim() || (renameTitle.slice(0, 5) + (renameTitle.length > 5 ? '...' : ''))"></span>
                </span>
            </div>
        </div>

        <div class="text-xs text-gray-500">
            The tab label appears in screen tabs. If not set, the first 5 characters of the title + "..." will be used.
        </div>

        <div class="flex gap-2">
            <x-button variant="secondary" class="flex-1" @click="showRenameModal = false">
                Cancel
            </x-button>
            <x-button
                variant="primary"
                class="flex-1"
                @click="saveConversationTitle()"
                x-bind:disabled="!renameTitle.trim() || renameSaving"
            >
                <span x-show="!renameSaving">Save</span>
                <span x-show="renameSaving">Saving...</span>
            </x-button>
        </div>
    </div>
</x-modal>
