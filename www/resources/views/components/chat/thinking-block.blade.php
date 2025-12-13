{{-- Thinking block - responsive design using Tailwind breakpoints --}}
<template x-if="msg.role === 'thinking'">
    <div class="max-w-[85%] md:max-w-3xl w-full">
        <div class="border border-purple-500/30 rounded-lg bg-purple-900/20 overflow-hidden">
            {{-- Header --}}
            <div class="flex items-center flex-wrap md:flex-nowrap gap-2 px-3 md:px-4 py-2 bg-purple-900/30 md:border-b md:border-purple-500/20 cursor-pointer"
                 @click="msg.collapsed = !msg.collapsed">
                {{-- Icon (desktop only) --}}
                <x-icon.lightbulb class="hidden md:block w-4 h-4 text-purple-400" />
                <span class="text-xs md:text-sm font-semibold text-purple-300">Thinking</span>
                <span class="text-xs text-gray-500" x-text="formatTimestamp(msg.timestamp)"></span>
                <template x-if="msg.cost">
                    <span class="flex items-center gap-1 md:ml-2">
                        <span class="text-xs text-green-400" x-text="'$' + msg.cost.toFixed(4)"></span>
                        <button @click.stop="showMessageBreakdown(msg)" class="text-gray-500 hover:text-gray-300 transition-colors" title="View cost breakdown">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                    </span>
                </template>
                {{-- Chevron --}}
                <svg class="w-3 md:w-4 h-3 md:h-4 text-purple-400 ml-auto transition-transform"
                     :class="msg.collapsed ? '-rotate-90' : ''"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            {{-- Content --}}
            <div x-show="!msg.collapsed" class="px-3 md:px-4 py-2 md:py-3">
                <div class="text-xs text-purple-200 whitespace-pre-wrap font-mono" x-text="msg.content"></div>
            </div>
        </div>
    </div>
</template>
