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
            maxlength="50"
        />

        {{-- Preview with yellow highlighting for chars 26-50 --}}
        <div x-show="renameTitle.length > 0" class="text-sm">
            <span class="text-gray-400 text-xs">Preview:</span>
            <div class="mt-1 font-medium">
                <span class="text-white" x-text="renameTitle.slice(0, 25)"></span><span class="text-yellow-400" x-text="renameTitle.slice(25, 50)"></span>
            </div>
        </div>

        <div class="text-xs space-y-1">
            <p class="text-gray-500">
                Maximum 50 characters. Desktop shows full title, mobile truncates at ~25.
            </p>
            <p class="text-yellow-400/80" x-show="renameTitle.length > 25">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                Yellow characters may not be visible on mobile.
            </p>
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
