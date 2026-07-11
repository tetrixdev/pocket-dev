{{--
Conversation Search Results - Shared between desktop sidebar and mobile drawer
--}}

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
