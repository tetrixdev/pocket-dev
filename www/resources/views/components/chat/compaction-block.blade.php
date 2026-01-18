{{-- Compaction block - shows context compaction summary --}}
<template x-if="msg.role === 'compaction'">
    <div class="max-w-[calc(100%-1rem)] md:max-w-3xl w-full">
        <div class="border border-amber-500/30 rounded-lg bg-amber-900/20 overflow-hidden">
            {{-- Header --}}
            <div class="flex items-center flex-wrap md:flex-nowrap gap-2 px-3 md:px-4 py-2 bg-amber-900/30 md:border-b md:border-amber-500/20 cursor-pointer"
                 @click="msg.collapsed = !msg.collapsed">
                {{-- Compress icon --}}
                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                </svg>
                <span class="text-xs md:text-sm font-semibold text-amber-300">Context Compacted</span>
                <span class="text-xs text-amber-400/70" x-text="msg.preTokensDisplay + ' tokens'"></span>
                <span class="text-xs text-gray-500" x-text="'(' + msg.trigger + ')'"></span>
                <span class="text-xs text-gray-500" x-text="formatTimestamp(msg.timestamp)"></span>
                {{-- Chevron --}}
                <svg class="w-3 md:w-4 h-3 md:h-4 text-amber-400 ml-auto transition-transform"
                     :class="msg.collapsed ? '-rotate-90' : ''"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            {{-- Content --}}
            <div x-show="!msg.collapsed" class="px-3 md:px-4 py-2 md:py-3">
                <div class="text-xs text-amber-300/70 mb-2">Summary Claude continues with:</div>
                <div class="prose prose-invert prose-sm max-w-none prose-p:my-2 prose-headings:text-amber-200 prose-strong:text-amber-100"
                     x-html="DOMPurify.sanitize(marked.parse(msg.content || ''))">
                </div>
            </div>
        </div>
    </div>
</template>
