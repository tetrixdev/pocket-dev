{{-- Rename Session Modal --}}
<x-modal show="showRenameSessionModal" title="Rename Session" max-width="sm">
    <div class="space-y-4">
        <x-text-input
            type="text"
            x-model="renameSessionName"
            x-ref="renameSessionInput"
            @keydown.enter="saveSessionName()"
            @keydown.escape="showRenameSessionModal = false"
            placeholder="Enter session name..."
            label="Name"
            x-bind:maxlength="window.TITLE_MAX_LENGTH"
        />

        {{-- Preview with yellow highlighting for chars beyond mobile truncation --}}
        <div x-show="renameSessionName.length > 0" class="text-sm">
            <span class="text-gray-400 text-xs">Preview:</span>
            <div class="mt-1 font-medium">
                <span class="text-white" x-text="renameSessionName.slice(0, window.TITLE_MOBILE_LENGTH)"></span><span class="text-yellow-400" x-text="renameSessionName.slice(window.TITLE_MOBILE_LENGTH, window.TITLE_MAX_LENGTH)"></span>
            </div>
        </div>

        <div class="text-xs space-y-1">
            <p class="text-gray-500">
                Maximum <span x-text="window.TITLE_MAX_LENGTH"></span> characters. Desktop shows full name, mobile truncates at ~<span x-text="window.TITLE_MOBILE_LENGTH"></span>.
            </p>
            <p class="text-yellow-400/80" x-show="renameSessionName.length > window.TITLE_MOBILE_LENGTH">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                Yellow characters may not be visible on mobile.
            </p>
        </div>

        <div class="flex gap-2">
            <x-button variant="secondary" class="flex-1" @click="showRenameSessionModal = false">
                Cancel
            </x-button>
            <x-button
                variant="primary"
                class="flex-1"
                @click="saveSessionName()"
                x-bind:disabled="!renameSessionName.trim() || renameSessionSaving"
            >
                <span x-show="!renameSessionSaving">Save</span>
                <span x-show="renameSessionSaving">Saving...</span>
            </x-button>
        </div>
    </div>
</x-modal>
