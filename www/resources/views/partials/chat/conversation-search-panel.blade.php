{{--
Conversation Search Panel - Shared between desktop sidebar and mobile drawer

Props:
- $sessionInputRef: x-ref name for session filter input (e.g., 'sidebarSearchInput' or 'mobileSearchInput')
- $conversationInputRef: x-ref name for conversation search input (e.g., 'conversationSearchInput' or 'mobileConversationSearchInput')
--}}
@props(['sessionInputRef' => 'sidebarSearchInput', 'conversationInputRef' => 'conversationSearchInput'])

{{-- Search Mode Tabs --}}
<div class="flex gap-1 mb-3">
    <button @click="sidebarSearchMode = 'sessions'; $nextTick(() => $refs.{{ $sessionInputRef }}?.focus())"
            :class="sidebarSearchMode === 'sessions' ? 'bg-gray-600 text-white' : 'bg-gray-700 text-gray-400 hover:text-gray-200'"
            class="flex-1 py-1.5 px-2 rounded text-xs font-medium transition-colors cursor-pointer">
        Sessions
    </button>
    <button @click="sidebarSearchMode = 'conversations'; $nextTick(() => $refs.{{ $conversationInputRef }}?.focus())"
            :class="sidebarSearchMode === 'conversations' ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-400 hover:text-gray-200'"
            class="flex-1 py-1.5 px-2 rounded text-xs font-medium transition-colors cursor-pointer">
        Search Chats
    </button>
</div>

{{-- Session Filter Mode --}}
<div x-show="sidebarSearchMode === 'sessions'">
    {{-- Archive Toggle --}}
    <label class="flex items-center gap-2 text-sm text-gray-300 mb-3 cursor-pointer hover:text-white">
        <input type="checkbox"
               x-model="showArchivedSessions"
               @change="fetchSessions()"
               class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-500 focus:ring-blue-500 focus:ring-offset-0">
        <span>Show Archived Sessions</span>
    </label>

    {{-- Session Name Filter --}}
    <div class="border-t border-gray-600 pt-3">
        <p class="text-xs text-gray-400 mb-2">Filter sessions</p>
        <div class="relative">
            <input type="text"
                   x-model="sessionSearchQuery"
                   x-ref="{{ $sessionInputRef }}"
                   @input.debounce.400ms="filterSessions()"
                   @keydown.escape="showSearchInput = false; sessionSearchQuery = ''"
                   placeholder="Filter by name..."
                   class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-sm text-white placeholder-gray-400 focus:outline-none focus:border-blue-500">
        </div>
    </div>
</div>

{{-- Conversation Search Mode --}}
<div x-show="sidebarSearchMode === 'conversations'">
    <div class="relative">
        <input type="text"
               x-model="conversationSearchQuery"
               x-ref="{{ $conversationInputRef }}"
               @input.debounce.500ms="searchConversations()"
               @keydown.escape="showSearchInput = false; clearConversationSearch()"
               placeholder="Describe what you're looking for..."
               class="w-full px-3 py-2 pr-8 bg-gray-900 border border-gray-700 rounded-lg text-sm text-white placeholder-gray-400 focus:outline-none focus:border-blue-500">
        {{-- Loading spinner --}}
        <div x-show="conversationSearchLoading" class="absolute right-3 top-1/2 -translate-y-1/2">
            <svg class="w-4 h-4 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>
    </div>
    <p class="text-xs text-gray-500 mt-1">Search by meaning, not keywords</p>

    {{-- Include archived toggle --}}
    <label class="flex items-center gap-2 text-xs text-gray-400 mt-2 cursor-pointer hover:text-white">
        <input type="checkbox"
               x-model="showArchivedConversations"
               @change="if(conversationSearchQuery) searchConversations()"
               class="w-3 h-3 rounded border-gray-600 bg-gray-700 text-blue-500">
        <span>Include archived</span>
    </label>
</div>
