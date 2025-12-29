{{-- Interrupted block - simple red bar indicating response was stopped --}}
<template x-if="msg.role === 'interrupted'">
    <div class="max-w-[85%] md:max-w-3xl w-full">
        <div class="flex items-center gap-2 px-3 md:px-4 py-2 rounded-lg border border-red-500/30 bg-red-900/30">
            <svg class="w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
            </svg>
            <span class="text-xs md:text-sm text-red-300">Response interrupted</span>
            <span class="text-xs text-gray-500" x-text="formatTimestamp(msg.timestamp)"></span>
        </div>
    </div>
</template>
