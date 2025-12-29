{{-- Error block - expandable, shows when job failed unexpectedly --}}
<template x-if="msg.role === 'error'">
    <div class="max-w-[85%] md:max-w-3xl w-full">
        <div class="border border-red-500/30 rounded-lg bg-red-900/30 overflow-hidden">
            {{-- Header --}}
            <div class="flex items-center flex-wrap md:flex-nowrap gap-2 px-3 md:px-4 py-2 bg-red-900/30 md:border-b md:border-red-500/20 cursor-pointer"
                 @click="msg.collapsed = !msg.collapsed">
                {{-- Icon (desktop only) --}}
                <svg class="hidden md:block w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span class="text-xs md:text-sm font-semibold text-red-300">Error</span>
                <span class="text-xs text-gray-500" x-text="formatTimestamp(msg.timestamp)"></span>
                {{-- Chevron --}}
                <svg class="w-3 md:w-4 h-3 md:h-4 text-red-400 ml-auto transition-transform"
                     :class="msg.collapsed ? '-rotate-90' : ''"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            {{-- Content --}}
            <div x-show="!msg.collapsed" class="px-3 md:px-4 py-2 md:py-3">
                <div class="text-xs text-red-200 whitespace-pre-wrap font-mono" x-text="msg.content"></div>
            </div>
        </div>
    </div>
</template>
