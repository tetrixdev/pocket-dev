{{-- Session Edit Modal - Edit session name and chat labels --}}
<x-modal show="showSessionEditModal" title="Edit Session" max-width="md">
    <div class="space-y-6">
        {{-- Session Name Section --}}
        <div class="space-y-2">
            <x-text-input
                type="text"
                x-model="sessionEditName"
                x-ref="sessionEditNameInput"
                @keydown.enter.prevent="saveSessionEdit()"
                @keydown.escape="showSessionEditModal = false"
                placeholder="Enter session name..."
                label="Session Name"
                x-bind:maxlength="window.TITLE_MAX_LENGTH"
            />

            {{-- Preview with yellow highlighting for chars beyond mobile truncation --}}
            <div x-show="sessionEditName.length > 0" class="text-sm">
                <span class="text-gray-400 text-xs">Preview:</span>
                <div class="mt-1 font-medium">
                    <span class="text-white" x-text="sessionEditName.slice(0, window.TITLE_MOBILE_LENGTH)"></span><span class="text-yellow-400" x-text="sessionEditName.slice(window.TITLE_MOBILE_LENGTH, window.TITLE_MAX_LENGTH)"></span>
                </div>
            </div>

            <p class="text-xs text-gray-500">
                Maximum <span x-text="window.TITLE_MAX_LENGTH"></span> characters. Desktop shows full name, mobile truncates at ~<span x-text="window.TITLE_MOBILE_LENGTH"></span>.
            </p>
            <p class="text-yellow-400/80 text-xs" x-show="sessionEditName.length > window.TITLE_MOBILE_LENGTH">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                Yellow characters may not be visible on mobile.
            </p>
        </div>

        {{-- Chat Labels Section --}}
        <div x-show="sessionEditChats.length > 0" class="space-y-3">
            <div class="border-t border-gray-700 pt-4">
                <h4 class="text-sm font-medium text-gray-300 mb-3">Chat Labels</h4>
                <p class="text-xs text-gray-500 mb-3">
                    Optionally give chats a short label (max 6 characters) to replace the number in tabs.
                </p>

                <div class="space-y-2 max-h-48 overflow-y-auto">
                    <template x-for="chat in sessionEditChats" :key="chat.screenId">
                        <div class="flex items-center gap-3">
                            {{-- Chat number badge --}}
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded bg-gray-700 text-sm font-medium text-gray-300 shrink-0"
                                  x-text="chat.chatNumber + '.'"></span>

                            {{-- Label input --}}
                            <input type="text"
                                   x-model="chat.label"
                                   maxlength="6"
                                   placeholder="Label..."
                                   @keydown.enter.prevent="saveSessionEdit()"
                                   class="w-20 px-2 py-1.5 bg-gray-700 border border-gray-600 rounded text-sm text-white placeholder-gray-500 focus:outline-none focus:border-blue-500">

                            {{-- Tab preview --}}
                            <span class="inline-flex items-center gap-1.5 px-2 py-1 bg-gray-700 rounded text-xs font-medium text-gray-300 shrink-0">
                                <i class="fa-solid fa-comment text-[10px] text-gray-400"></i>
                                <span x-text="chat.label.trim() || (chat.chatNumber + '.')"></span>
                            </span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Empty state when no chats --}}
        <div x-show="sessionEditChats.length === 0" class="text-center text-gray-500 text-sm py-4">
            No chat screens in this session.
        </div>

        {{-- Action buttons --}}
        <div class="flex gap-2 pt-2">
            <x-button variant="secondary" class="flex-1" @click="showSessionEditModal = false">
                Cancel
            </x-button>
            <x-button
                variant="primary"
                class="flex-1"
                @click="saveSessionEdit()"
                x-bind:disabled="!sessionEditName.trim() || sessionEditSaving"
            >
                <span x-show="!sessionEditSaving">Save</span>
                <span x-show="sessionEditSaving">Saving...</span>
            </x-button>
        </div>
    </div>
</x-modal>
