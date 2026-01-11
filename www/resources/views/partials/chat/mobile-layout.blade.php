{{-- Mobile Header (Fixed) --}}
<div class="fixed top-0 left-0 right-0 z-10 bg-gray-800 border-b border-gray-700 p-2 flex items-center justify-between">
    <button @click="showMobileDrawer = true" class="text-gray-300 hover:text-white p-2">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>
    <div class="flex flex-col items-center">
        <button @click="openRenameModal()"
                :disabled="!currentConversationUuid"
                class="text-base font-semibold leading-tight hover:text-blue-400 transition-colors max-w-[25ch] truncate disabled:cursor-default disabled:hover:text-white"
                :class="{ 'cursor-pointer': currentConversationUuid }"
                :title="currentConversationUuid ? 'Click to rename' : ''"
                x-text="currentConversationTitle || 'New Conversation'">
        </button>
        <div class="flex items-center gap-2">
            <button @click="showAgentSelector = true"
                    class="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-200 underline decoration-gray-600 hover:decoration-gray-400"
                    aria-label="Select AI agent">
                <span class="w-1.5 h-1.5 rounded-full shrink-0"
                      :class="{
                          'bg-orange-500': currentAgent?.provider === 'anthropic',
                          'bg-green-500': currentAgent?.provider === 'openai',
                          'bg-purple-500': currentAgent?.provider === 'claude_code',
                          'bg-gray-500': !currentAgent
                      }"></span>
                <span x-text="currentAgent?.name || 'Select Agent'"></span>
            </button>
            {{-- Conversation status badge --}}
            <span x-show="currentConversationUuid && currentConversationStatus"
                  class="inline-flex items-center justify-center w-3.5 h-3.5 rounded-sm"
                  :class="getStatusColorClass(currentConversationStatus)"
                  :title="'Status: ' + currentConversationStatus">
                <i class="text-white text-[8px]" :class="getStatusIconClass(currentConversationStatus)"></i>
            </span>
            {{-- Context progress bar (compact) --}}
            <x-chat.context-progress :compact="true" />
        </div>
    </div>
    {{-- Conversation Menu Dropdown --}}
    <div class="relative">
        <button @click="showConversationMenu = !showConversationMenu"
                @keydown.escape="showConversationMenu = false"
                :aria-expanded="showConversationMenu"
                aria-haspopup="true"
                class="text-gray-300 hover:text-white p-2"
                title="Menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
        </button>
        {{-- Dropdown Menu --}}
        <div x-show="showConversationMenu"
             x-cloak
             @click.outside="showConversationMenu = false"
             @keydown.escape="showConversationMenu = false"
             role="menu"
             aria-orientation="vertical"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="transform opacity-0 scale-95"
             x-transition:enter-end="transform opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="transform opacity-100 scale-100"
             x-transition:leave-end="transform opacity-0 scale-95"
             class="absolute right-0 mt-1 w-48 bg-gray-700 rounded-lg shadow-lg border border-gray-600 py-1 z-50">
            {{-- Workspace --}}
            <button @click="openWorkspaceSelector(); showConversationMenu = false"
                    role="menuitem"
                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600 w-full text-left">
                <i class="fa-solid fa-folder w-4 text-center"></i>
                <span class="flex-1">Workspace</span>
                <span class="text-xs text-gray-400 truncate max-w-[80px]" x-text="currentWorkspace?.name || 'Default'"></span>
            </button>
            {{-- Settings --}}
            <a href="/config"
               role="menuitem"
               class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600">
                <i class="fa-solid fa-cog w-4 text-center"></i>
                Settings
            </a>
            {{-- Archive/Unarchive --}}
            <button @click="toggleArchiveConversation(); showConversationMenu = false"
                    role="menuitem"
                    :disabled="!currentConversationUuid"
                    :class="!currentConversationUuid ? 'text-gray-500 cursor-not-allowed' : 'text-gray-200 hover:bg-gray-600'"
                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                <i class="fa-solid fa-box-archive w-4 text-center"></i>
                <span x-text="currentConversationStatus === 'archived' ? 'Unarchive' : 'Archive'"></span>
            </button>
            {{-- Delete --}}
            <button @click="deleteConversation(); showConversationMenu = false"
                    role="menuitem"
                    :disabled="!currentConversationUuid"
                    :class="!currentConversationUuid ? 'text-gray-500 cursor-not-allowed' : 'text-red-400 hover:bg-gray-600'"
                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                <i class="fa-solid fa-trash w-4 text-center"></i>
                Delete
            </button>
        </div>
    </div>
</div>

{{-- Mobile Drawer Overlay --}}
<div x-show="showMobileDrawer"
     @click="showMobileDrawer = false"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-300"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 bg-black bg-opacity-50 z-40"
     style="display: none;">
</div>

{{-- Mobile Drawer --}}
<div x-show="showMobileDrawer"
     x-transition:enter="transition ease-out duration-300 transform"
     x-transition:enter-start="-translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-300 transform"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="-translate-x-full"
     class="fixed inset-y-0 left-0 w-5/6 max-w-sm bg-gray-800 z-50 flex flex-col"
     style="display: none;">
    <div class="p-4 border-b border-gray-700">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-semibold">PocketDev</h2>
            {{-- Close drawer --}}
            <button @click="showMobileDrawer = false" class="w-8 h-8 flex items-center justify-center text-gray-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="flex items-center gap-2">
            {{-- New conversation button (green) --}}
            <button @click="newConversation(); showMobileDrawer = false"
                    class="flex-1 py-2 rounded-lg text-sm flex items-center justify-center gap-2 transition-colors bg-emerald-600/90 hover:bg-emerald-500 text-white"
                    title="New conversation">
                <i class="fa-solid fa-comment-medical"></i>
            </button>
            {{-- Filter/Search button (blue) --}}
            <button @click="showSearchInput = !showSearchInput; if(showSearchInput) $nextTick(() => $refs.mobileSearchInput?.focus())"
                    :class="showSearchInput ? 'bg-blue-500' : 'bg-blue-600/90 hover:bg-blue-500'"
                    class="flex-1 py-2 rounded-lg text-sm flex items-center justify-center gap-2 transition-colors text-white"
                    title="Search conversations">
                <i class="fa-solid fa-filter"></i>
            </button>
            {{-- Clear filters button (red, only visible when filters active) --}}
            <button x-show="conversationSearchQuery || showArchivedConversations"
                    x-cloak
                    @click="clearAllFilters()"
                    class="flex-1 py-2 rounded-lg text-sm flex items-center justify-center gap-2 transition-colors bg-rose-600/90 hover:bg-rose-500 text-white"
                    title="Clear all filters">
                <i class="fa-solid fa-filter-circle-xmark"></i>
            </button>
        </div>
    </div>

    {{-- Filter Panel (shown when filter button clicked) --}}
    <div x-show="showSearchInput" x-cloak class="px-4 pt-3 pb-3 border-b border-gray-700">
        {{-- Archive Toggle --}}
        <label class="flex items-center gap-2 text-sm text-gray-300 mb-3 cursor-pointer hover:text-white">
            <input type="checkbox"
                   x-model="showArchivedConversations"
                   @change="fetchConversations(); if (conversationSearchQuery) searchConversations()"
                   class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-500 focus:ring-blue-500 focus:ring-offset-0">
            <span>Show Archived Conversations</span>
        </label>

        {{-- Search Input --}}
        <div class="border-t border-gray-600 pt-3">
            <p class="text-xs text-gray-400 mb-2">Search in conversations</p>
            <div class="relative">
                <input type="text"
                       x-model="conversationSearchQuery"
                       x-ref="mobileSearchInput"
                       @input.debounce.400ms="searchConversations()"
                       @keydown.escape="showSearchInput = false; conversationSearchQuery = ''; conversationSearchResults = []"
                       placeholder="Describe what you're looking for..."
                       class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-sm text-white placeholder-gray-400 focus:outline-none focus:border-blue-500">
                <div x-show="conversationSearchLoading" class="absolute right-3 top-1/2 -translate-y-1/2">
                    <svg class="w-4 h-4 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-1">Search by meaning, not keywords</p>
        </div>
    </div>

    {{-- Conversations List (normal mode) --}}
    <div x-show="!conversationSearchQuery" class="flex-1 overflow-y-auto p-2" @scroll="handleConversationsScroll($event)">
        <template x-if="conversations.length === 0">
            <div class="text-center text-gray-500 text-xs mt-4">No conversations yet</div>
        </template>
        <template x-for="conv in conversations" :key="conv.uuid">
            <div @click="loadConversation(conv.uuid); showMobileDrawer = false"
                 :class="{'bg-gray-700': currentConversationUuid === conv.uuid}"
                 class="p-2 mb-1 rounded hover:bg-gray-700 cursor-pointer transition-colors">
                <div class="flex items-center gap-1.5">
                    <span class="inline-flex items-center justify-center w-3.5 h-3.5 rounded-sm shrink-0"
                          :class="getStatusColorClass(conv.status)">
                        <i class="text-white text-[8px]" :class="getStatusIconClass(conv.status)"></i>
                    </span>
                    <span class="text-xs text-gray-300 truncate" x-text="conv.title || 'New Conversation'"></span>
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
                    <span x-text="formatDate(conv.last_activity_at || conv.created_at)"></span>
                    <span class="w-1 h-1 rounded-full shrink-0" :class="getProviderColorClass(conv.provider_type)"></span>
                    <span class="truncate" x-text="conv.agent?.name || getProviderDisplayName(conv.provider_type)"></span>
                </div>
            </div>
        </template>
        {{-- Loading indicator --}}
        <div x-show="loadingMoreConversations" class="text-center py-2">
            <span class="text-xs text-gray-500">Loading...</span>
        </div>
    </div>

    {{-- Search Results (search mode) --}}
    <div x-show="conversationSearchQuery" x-cloak class="flex-1 overflow-y-auto p-2">
        {{-- No Results --}}
        <template x-if="!conversationSearchLoading && conversationSearchResults.length === 0 && conversationSearchQuery">
            <div class="text-center text-gray-500 text-xs mt-4">
                <p>No matching conversations</p>
                <p class="mt-1 text-gray-600">Try rephrasing your search</p>
            </div>
        </template>

        {{-- Results List --}}
        <template x-for="result in conversationSearchResults" :key="result.conversation_uuid + '-' + result.turn_number">
            <div @click="loadSearchResult(result); showMobileDrawer = false"
                 class="p-2 mb-1 rounded hover:bg-gray-700 cursor-pointer transition-colors">
                {{-- Score + Title --}}
                <div class="flex items-center gap-1.5 text-xs">
                    <span class="shrink-0 px-1 py-0.5 bg-blue-600/30 text-blue-300 rounded text-[10px]" x-text="result.similarity"></span>
                    <span class="text-gray-300 truncate" x-text="result.conversation_title || 'Untitled'"></span>
                </div>
                {{-- User Question --}}
                <div class="text-xs text-gray-500 mt-0.5 line-clamp-2" x-text="result.user_question || result.content_preview"></div>
                {{-- Date + Turn --}}
                <div class="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
                    <span x-text="formatDate(result.conversation_updated_at)"></span>
                    <span class="text-gray-600">Turn <span x-text="result.turn_number"></span></span>
                </div>
            </div>
        </template>
    </div>

    {{-- Footer --}}
    <div class="p-4 border-t border-gray-700 text-xs text-gray-400">
        <div class="mb-2">Cost: <span class="text-green-400 font-mono" x-text="'$' + sessionCost.toFixed(4)">$0.00</span></div>
        <div class="mb-2"><span x-text="totalTokens.toLocaleString() + ' tokens'">0 tokens</span></div>
        <div class="flex flex-wrap gap-3">
            <x-button @click="copyConversationToClipboard(); showMobileDrawer = false"
                      variant="ghost"
                      size="sm"
                      ::disabled="messages.length === 0">
                <span x-text="copyingConversation ? 'Copied!' : 'Copy Chat'"></span>
            </x-button>
@if(config('app.debug'))
            <x-button @click="$store.debug.toggle(); showMobileDrawer = false" variant="ghost" size="sm">
                Debug Log
            </x-button>
@endif
        </div>
    </div>
</div>
