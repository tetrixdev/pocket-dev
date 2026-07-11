{{-- Chat Modals --}}
{{-- TODO: Refactor @include statements to use Laravel anonymous components (e.g., <x-chat.modals.agent-selector />) for consistency with coding guidelines --}}
@include('partials.chat.toast')
@include('partials.chat.modals.openai-key')
@include('partials.chat.modals.claude-code-auth')
@include('partials.chat.modals.agent-selector')
@include('partials.chat.modals.system-prompt-preview')
@include('partials.chat.modals.workspace-selector')
@include('partials.chat.modals.pricing-settings')
@include('partials.chat.modals.cost-breakdown')
@include('partials.chat.modals.shortcuts')
@include('partials.chat.modals.error')
@include('partials.chat.modals.conversation-search')
@include('partials.chat.modals.rename-conversation')
@include('partials.chat.modals.session-edit')
@include('partials.chat.modals.restore-chat')

{{-- File Preview Modal --}}
<x-file-preview-modal />

{{-- Debug Panel with chat-specific state display --}}
<x-debug-panel>
    <x-slot:stateDisplay>
        <div class="px-4 py-2 bg-gray-850 border-b border-gray-700 font-mono text-xs text-gray-400">
            State: isAtBottom=<span x-text="isAtBottom" :class="isAtBottom ? 'text-green-400' : 'text-red-400'"></span>,
            autoScrollEnabled=<span x-text="autoScrollEnabled" :class="autoScrollEnabled ? 'text-green-400' : 'text-red-400'"></span>,
            ignoreScrollEvents=<span x-text="ignoreScrollEvents" :class="ignoreScrollEvents ? 'text-yellow-400' : 'text-green-400'"></span>
        </div>
    </x-slot:stateDisplay>
    <x-slot:customCopy>
        <div class="flex gap-2">
            <button @click="copyDebugWithState()"
                    class="text-xs text-gray-400 hover:text-white px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded transition-colors"
                    title="Copy to clipboard with state">
                <i class="fas fa-copy mr-1"></i>Copy
            </button>
            <button @click="copyStreamLogPath()"
                    x-show="currentConversationUuid"
                    class="text-xs text-gray-400 hover:text-white px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded transition-colors"
                    title="Copy stream log file path to clipboard">
                <i class="fas fa-file-code mr-1"></i>Log Path
            </button>
        </div>
    </x-slot:customCopy>
</x-debug-panel>
