{{-- Mobile Header (Fixed) --}}
<div class="fixed top-0 left-0 right-0 z-10 bg-gray-800 border-b border-gray-700 p-2 flex items-center justify-between">
    <button @click="showMobileDrawer = true" class="text-gray-300 hover:text-white p-2">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>
    <div class="flex flex-col items-center">
        <button @click="openSessionEditModal()"
                :disabled="!currentSession"
                class="text-base font-semibold leading-tight hover:text-blue-400 transition-colors max-w-[25ch] truncate disabled:cursor-default disabled:hover:text-white"
                :class="{ 'cursor-pointer': currentSession }"
                :title="currentSession ? 'Click to edit session' : ''"
                x-text="currentSession?.name || 'New Session'">
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
                {{-- Processing: SVG spinner --}}
                <x-spinner x-show="currentConversationStatus === 'processing'" x-cloak class="!w-2 !h-2 text-white" />
                {{-- Other statuses: FA icons --}}
                <i x-show="currentConversationStatus !== 'processing'" class="text-white text-[8px]" :class="getStatusIconClass(currentConversationStatus)"></i>
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
                x-ref="conversationMenuBtn"
                class="text-gray-300 hover:text-white p-2"
                title="Menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
        </button>
        {{-- Dropdown Menu - Teleported to body to escape stacking context --}}
        <template x-teleport="body">
            <div x-show="showConversationMenu"
                 x-cloak
                 @click.outside="showConversationMenu = false"
                 @keydown.escape="showConversationMenu = false"
                 role="menu"
                 aria-orientation="vertical"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="md:hidden fixed w-48 bg-gray-700 rounded-lg shadow-lg border border-gray-600 z-[100] overflow-hidden"
                 :style="{ top: ($refs.conversationMenuBtn?.getBoundingClientRect().bottom + 4) + 'px', right: '8px' }">
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
            {{-- Session Section Header --}}
            <div class="px-4 py-1.5 text-xs text-gray-500 uppercase tracking-wide border-t border-gray-600">Session</div>
            {{-- Archive/Restore Session --}}
            <button @click="currentSession?.is_archived ? restoreSession(currentSession.id) : archiveSession(currentSession.id); showConversationMenu = false"
                    role="menuitem"
                    :disabled="!currentSession"
                    :class="!currentSession ? 'text-gray-500 cursor-not-allowed' : 'text-gray-200 hover:bg-gray-600'"
                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                <i class="fa-solid fa-box-archive w-4 text-center"></i>
                <span x-text="currentSession?.is_archived ? 'Restore session' : 'Archive session'"></span>
            </button>
            {{-- Delete Session --}}
            <button @click="deleteSession(currentSession?.id); showConversationMenu = false"
                    role="menuitem"
                    :disabled="!currentSession"
                    :class="!currentSession ? 'text-gray-500 cursor-not-allowed' : 'text-red-400 hover:bg-gray-600'"
                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                <i class="fa-solid fa-trash w-4 text-center"></i>
                Delete session
            </button>
            {{-- Restore Chat (only show if session has archived conversations) --}}
            <button x-show="hasArchivedConversations"
                    @click="openRestoreChatModal(); showConversationMenu = false"
                    role="menuitem"
                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600 w-full text-left">
                <i class="fa-solid fa-rotate-left w-4 text-center"></i>
                Restore chat...
            </button>
            {{-- Conversation Section Header --}}
            <div class="px-4 py-1.5 text-xs text-gray-500 uppercase tracking-wide border-t border-gray-600">Conversation</div>
            {{-- Archive/Unarchive Conversation --}}
            <button @click="toggleArchiveConversation(); showConversationMenu = false"
                    role="menuitem"
                    :disabled="!currentConversationUuid"
                    :class="!currentConversationUuid ? 'text-gray-500 cursor-not-allowed' : 'text-gray-200 hover:bg-gray-600'"
                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                <i class="fa-solid fa-box-archive w-4 text-center"></i>
                <span x-text="currentConversationStatus === 'archived' ? 'Unarchive chat' : 'Archive chat'"></span>
            </button>
            {{-- Delete Conversation --}}
            <button @click="deleteConversation(); showConversationMenu = false"
                    role="menuitem"
                    :disabled="!currentConversationUuid"
                    :class="!currentConversationUuid ? 'text-gray-500 cursor-not-allowed' : 'text-red-400 hover:bg-gray-600'"
                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                <i class="fa-solid fa-trash w-4 text-center"></i>
                Delete chat
            </button>
            </div>
        </template>
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
            {{-- New session button (green) --}}
            <button @click="newSession(); showMobileDrawer = false"
                    class="relative flex-1 py-2 rounded-lg text-sm flex items-center justify-center gap-2 transition-colors bg-emerald-600/90 hover:bg-emerald-500 text-white"
                    :title="workspaceHasDefaultTemplate ? 'New session (using default template)' : 'New session'">
                <i class="fa-solid fa-plus"></i>
                {{-- Default template indicator badge --}}
                <span x-show="workspaceHasDefaultTemplate"
                      x-cloak
                      class="absolute -top-1 -right-1 w-3 h-3 bg-amber-400 rounded-full border border-gray-800"
                      title="Default template set"></span>
            </button>
            {{-- Filter/Search button (blue) --}}
            <button @click="showSearchInput = !showSearchInput; if(showSearchInput) $nextTick(() => $refs.mobileSearchInput?.focus())"
                    :class="showSearchInput ? 'bg-blue-500' : 'bg-blue-600/90 hover:bg-blue-500'"
                    class="flex-1 py-2 rounded-lg text-sm flex items-center justify-center gap-2 transition-colors text-white"
                    title="Filter sessions">
                <i class="fa-solid fa-filter"></i>
            </button>
            {{-- Clear filters button (red, only visible when filters active) --}}
            <button x-show="sessionSearchQuery || showArchivedSessions || conversationSearchQuery"
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
        @include('partials.chat.conversation-search-panel', [
            'sessionInputRef' => 'mobileSearchInput',
            'conversationInputRef' => 'mobileConversationSearchInput'
        ])
    </div>

    {{-- Conversation Search Results (shown when in search mode with query) --}}
    <div x-show="sidebarSearchMode === 'conversations' && conversationSearchQuery" x-cloak class="flex-1 overflow-y-auto p-2">
        @include('partials.chat.conversation-search-results')
    </div>

    {{-- Sessions List (hidden when showing conversation search results) --}}
    <div x-show="!(sidebarSearchMode === 'conversations' && conversationSearchQuery)" class="flex-1 overflow-y-auto p-2" id="mobile-sessions-list" @scroll="handleSessionsScroll($event)">
        <template x-if="filteredSessions.length === 0 && !loadingMoreSessions">
            <div class="text-center text-gray-500 text-xs mt-4">No sessions yet</div>
        </template>
        <template x-for="session in filteredSessions" :key="session.id">
            <div @click="loadSession(session.id); showMobileDrawer = false"
                 :class="{'bg-gray-700': currentSession?.id === session.id}"
                 class="group relative p-2 mb-1 rounded hover:bg-gray-700 cursor-pointer transition-colors"
                 x-data="{ get _status() { return getSessionStatus(session) }, get _tabCount() { return getActiveTabCount(session) } }">
                <div class="flex items-center gap-1.5">
                    <span class="inline-flex items-center justify-center w-3.5 h-3.5 rounded-sm shrink-0"
                          :class="getStatusColorClass(_status)">
                        {{-- Processing: SVG spinner --}}
                        <x-spinner x-show="_status === 'processing'" x-cloak class="!w-2 !h-2 text-white" />
                        {{-- Other statuses: FA icons --}}
                        <i x-show="_status !== 'processing'" class="text-white text-[8px]" :class="getStatusIconClass(_status)"></i>
                    </span>
                    <span class="text-xs text-gray-300 truncate flex-1" x-text="session.name || 'New Session'"></span>
                    {{-- Screen count badge --}}
                    <span class="text-[10px] text-gray-500" x-show="_tabCount > 0" x-text="_tabCount + ' tab' + (_tabCount === 1 ? '' : 's')"></span>
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
             :style="{ top: sessionMenuPos.top + 'px', left: Math.max(8, sessionMenuPos.right - 192) + 'px' }">
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
