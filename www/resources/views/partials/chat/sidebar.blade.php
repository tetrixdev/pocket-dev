{{-- Desktop Sidebar --}}
<div class="w-64 h-full bg-gray-800 border-r border-gray-700 flex flex-col">
    <div class="p-4 border-b border-gray-700">
        <h2 class="text-lg font-semibold mb-3">PocketDev</h2>
        <div class="flex items-center gap-2">
            {{-- New session button (green) --}}
            <button @click="newSession()"
                    class="relative flex-1 py-2 rounded-lg text-sm flex items-center justify-center gap-2 transition-colors bg-emerald-600/90 hover:bg-emerald-500 text-white cursor-pointer"
                    :title="workspaceHasDefaultTemplate ? 'New session (using default template)' : 'New session'">
                <i class="fa-solid fa-plus"></i>
                {{-- Default template indicator badge --}}
                <span x-show="workspaceHasDefaultTemplate"
                      x-cloak
                      class="absolute -top-1 -right-1 w-3 h-3 bg-amber-400 rounded-full border border-gray-800"
                      title="Default template set"></span>
            </button>
            {{-- Filter/Search button (blue) --}}
            <button @click="showSearchInput = !showSearchInput; if(showSearchInput) $nextTick(() => $refs.sidebarSearchInput?.focus())"
                    :class="showSearchInput ? 'bg-blue-500' : 'bg-blue-600/90 hover:bg-blue-500'"
                    class="flex-1 py-2 rounded-lg text-sm flex items-center justify-center gap-2 transition-colors text-white cursor-pointer"
                    title="Filter sessions">
                <i class="fa-solid fa-filter"></i>
            </button>
            {{-- Clear filters button (red, only visible when filters active) --}}
            <button x-show="sessionSearchQuery || showArchivedSessions || conversationSearchQuery"
                    x-cloak
                    @click="clearAllFilters()"
                    class="flex-1 py-2 rounded-lg text-sm flex items-center justify-center gap-2 transition-colors bg-rose-600/90 hover:bg-rose-500 text-white cursor-pointer"
                    title="Clear all filters">
                <i class="fa-solid fa-filter-circle-xmark"></i>
            </button>
        </div>
    </div>

    {{-- Filter Panel (shown when filter button clicked) --}}
    <div x-show="showSearchInput" x-cloak class="px-4 pt-3 pb-3 border-b border-gray-700">
        {{-- Search Mode Tabs --}}
        <div class="flex gap-1 mb-3">
            <button @click="sidebarSearchMode = 'sessions'; $nextTick(() => $refs.sidebarSearchInput?.focus())"
                    :class="sidebarSearchMode === 'sessions' ? 'bg-gray-600 text-white' : 'bg-gray-700 text-gray-400 hover:text-gray-200'"
                    class="flex-1 py-1.5 px-2 rounded text-xs font-medium transition-colors cursor-pointer">
                Sessions
            </button>
            <button @click="sidebarSearchMode = 'conversations'; $nextTick(() => $refs.conversationSearchInput?.focus())"
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
                           x-ref="sidebarSearchInput"
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
                       x-ref="conversationSearchInput"
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
    </div>

    {{-- Conversation Search Results (shown when in search mode with query) --}}
    <div x-show="sidebarSearchMode === 'conversations' && conversationSearchQuery" x-cloak class="flex-1 overflow-y-auto p-2">
        {{-- No Results --}}
        <template x-if="!conversationSearchLoading && conversationSearchResults.length === 0 && conversationSearchQuery">
            <div class="text-center text-gray-500 text-xs mt-4">
                <p>No matching conversations</p>
                <p class="mt-1 text-gray-600">Try rephrasing your search</p>
            </div>
        </template>

        {{-- Results List --}}
        <template x-for="result in conversationSearchResults" :key="result.conversation_uuid + '-' + result.turn_number">
            <div @click="loadSearchResult(result)"
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

    {{-- Sessions List (hidden when showing conversation search results) --}}
    <div x-show="!(sidebarSearchMode === 'conversations' && conversationSearchQuery)" class="flex-1 overflow-y-auto p-2" id="sessions-list" @scroll="handleSessionsScroll($event)">
        <template x-if="filteredSessions.length === 0 && !loadingMoreSessions">
            <div class="text-center text-gray-500 text-xs mt-4">No sessions yet</div>
        </template>
        <template x-for="session in filteredSessions" :key="session.id">
            <div @click="loadSession(session.id)"
                 :class="{'bg-gray-700': currentSession?.id === session.id}"
                 class="group relative p-2 mb-1 rounded hover:bg-gray-700 cursor-pointer transition-colors">
                <div class="flex items-center gap-1.5">
                    <span class="inline-flex items-center justify-center w-3.5 h-3.5 rounded-sm shrink-0"
                          :class="session.is_archived ? 'bg-gray-600' : 'bg-green-600'">
                        <i class="text-white text-[8px]" :class="session.is_archived ? 'fa-solid fa-box-archive' : 'fa-solid fa-check'"></i>
                    </span>
                    <span class="text-xs text-gray-300 truncate flex-1" x-text="session.name || 'New Session'"></span>
                    {{-- Screen count badge --}}
                    <span class="text-[10px] text-gray-500" x-text="(session.screens?.length || 0) + ' tab' + ((session.screens?.length || 0) === 1 ? '' : 's')"></span>
                    {{-- Session menu button (always visible) --}}
                    <button @click.stop="openSessionMenu($event, session)"
                            class="w-5 h-5 flex items-center justify-center text-gray-400 hover:text-white hover:bg-gray-600 rounded cursor-pointer shrink-0"
                            title="Session options">
                        <i class="fa-solid fa-ellipsis-vertical text-[10px]"></i>
                    </button>
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
                    <span x-text="formatDate(session.updated_at)"></span>
                </div>
            </div>
        </template>

        {{-- Loading indicator for infinite scroll --}}
        <div x-show="loadingMoreSessions" class="text-center py-2">
            <span class="text-xs text-gray-500">Loading...</span>
        </div>

        {{-- Session Context Menu (fixed position to escape overflow) --}}
        <div x-show="sessionMenuId"
             x-cloak
             @click.outside="closeSessionMenu()"
             @keydown.escape.window="closeSessionMenu()"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="fixed w-48 bg-gray-700 rounded-lg shadow-lg border border-gray-600 py-1 z-50"
             :style="{ top: sessionMenuPos.top + 'px', left: sessionMenuPos.left + 'px' }">
            {{-- Save as Default --}}
            <button @click="saveSessionAsDefault(filteredSessions.find(s => s.id === sessionMenuId))"
                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600 w-full text-left cursor-pointer">
                <i class="fa-solid fa-bookmark text-amber-400 w-4 text-center"></i>
                Save as default
            </button>
            {{-- Clear Default (only if workspace has a default) --}}
            <button x-show="workspaceHasDefaultTemplate"
                    @click="clearDefaultTemplate()"
                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600 w-full text-left cursor-pointer">
                <i class="fa-solid fa-bookmark text-gray-400 w-4 text-center"></i>
                Clear default
            </button>
            {{-- Divider --}}
            <div class="border-t border-gray-600 my-1"></div>
            {{-- Archive/Restore Session --}}
            <button @click="filteredSessions.find(s => s.id === sessionMenuId)?.is_archived ? restoreSession(sessionMenuId) : archiveSession(sessionMenuId)"
                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600 w-full text-left cursor-pointer">
                <i class="fa-solid fa-box-archive w-4 text-center"></i>
                <span x-text="filteredSessions.find(s => s.id === sessionMenuId)?.is_archived ? 'Restore session' : 'Archive session'"></span>
            </button>
            {{-- Delete Session --}}
            <button @click="deleteSession(sessionMenuId)"
                    class="flex items-center gap-2 px-4 py-2 text-sm text-red-400 hover:bg-gray-600 w-full text-left cursor-pointer">
                <i class="fa-solid fa-trash w-4 text-center"></i>
                Delete session
            </button>
        </div>
    </div>

    {{-- Footer Info --}}
    <div class="p-4 border-t border-gray-700 text-xs text-gray-400">
        <div class="mb-3 pb-3 border-b border-gray-700">
            <div class="flex items-center justify-between mb-1">
                <div class="flex items-center gap-1">
                    <span class="text-gray-300 font-semibold">Session Cost</span>
                    <button @click="showPricingSettings = true" class="text-gray-400 hover:text-gray-200 transition-colors cursor-pointer" title="Configure pricing">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </button>
                </div>
                <span class="text-green-400 font-mono" x-text="'$' + sessionCost.toFixed(4)">$0.00</span>
            </div>
            <div class="text-gray-500" style="font-size: 10px;">
                <span x-text="totalTokens.toLocaleString() + ' tokens'">0 tokens</span>
            </div>
        </div>
        <div>Working Dir: <span x-text="currentWorkspace?.working_directory_path || '/workspace'"></span></div>
        <div class="flex flex-wrap gap-2 mt-2">
            <a href="{{ route('config.index') }}" class="text-blue-400 hover:text-blue-300">Settings</a>
            <button @click="showShortcutsModal = true" class="text-blue-400 hover:text-blue-300 cursor-pointer">Shortcuts</button>
            <button @click="copyConversationToClipboard()"
                    :disabled="messages.length === 0"
                    :class="messages.length === 0 ? 'text-gray-600 cursor-not-allowed' : 'text-blue-400 hover:text-blue-300 cursor-pointer'"
                    x-text="copyingConversation ? 'Copied!' : 'Copy Chat'">
            </button>
        </div>
    </div>
</div>
