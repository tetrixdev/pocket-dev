{{-- Conversation Search Modal --}}
<x-modal show="showSearchModal" title="Search Conversations" max-width="lg">
    <div class="space-y-4">
        {{-- Search Input --}}
        <div class="relative">
            <input type="text"
                   x-model="conversationSearchQuery"
                   @input.debounce.400ms="searchConversations()"
                   @keydown.enter="searchConversations()"
                   placeholder="Describe what you're looking for..."
                   class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:border-blue-500"
                   autofocus>
            <div class="absolute right-3 top-1/2 -translate-y-1/2">
                <template x-if="conversationSearchLoading">
                    <svg class="w-5 h-5 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </template>
                <template x-if="!conversationSearchLoading && conversationSearchQuery">
                    <button @click="conversationSearchQuery = ''; conversationSearchResults = []" class="text-gray-400 hover:text-white">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </template>
            </div>
        </div>

        {{-- Help Text --}}
        <p class="text-xs text-gray-500">
            Semantic search finds conversations by meaning, not just keywords. Try phrases like "how to fix the header" or "discussion about authentication".
        </p>

        {{-- Results --}}
        <div class="max-h-[50vh] overflow-y-auto space-y-2">
            {{-- No Query State --}}
            <template x-if="!conversationSearchQuery && conversationSearchResults.length === 0">
                <div class="text-center py-8 text-gray-500">
                    <i class="fa-solid fa-magnifying-glass text-3xl mb-3 opacity-50"></i>
                    <p>Enter a search query to find past conversations</p>
                </div>
            </template>

            {{-- No Results --}}
            <template x-if="conversationSearchQuery && !conversationSearchLoading && conversationSearchResults.length === 0">
                <div class="text-center py-8 text-gray-500">
                    <i class="fa-solid fa-inbox text-3xl mb-3 opacity-50"></i>
                    <p>No matching conversations found</p>
                    <p class="text-xs mt-1">Try rephrasing your search</p>
                </div>
            </template>

            {{-- Results List --}}
            <template x-for="result in conversationSearchResults" :key="result.conversation_uuid + '-' + result.turn_number">
                <button @click="loadSearchResult(result)"
                        class="w-full text-left p-3 rounded-lg bg-gray-800 border border-gray-700 hover:border-gray-600 hover:bg-gray-750 transition-all">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1 min-w-0">
                            {{-- Conversation Title --}}
                            <div class="font-medium text-gray-200 truncate text-sm" x-text="result.conversation_title || 'Untitled'"></div>

                            {{-- User Question Preview --}}
                            <p class="text-xs text-gray-400 mt-1 line-clamp-2" x-text="result.user_question || result.content_preview"></p>

                            {{-- Metadata --}}
                            <div class="flex items-center gap-2 mt-2 text-xs text-gray-500">
                                <span class="bg-blue-600/30 text-blue-300 px-1.5 py-0.5 rounded" x-text="result.similarity"></span>
                                <span x-text="'Turn ' + result.turn_number"></span>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-gray-600 mt-1"></i>
                    </div>
                </button>
            </template>
        </div>

        {{-- Footer --}}
        <div class="flex justify-end pt-3 border-t border-gray-700">
            <x-button variant="secondary" @click="showSearchModal = false">
                Close
            </x-button>
        </div>
    </div>
</x-modal>
