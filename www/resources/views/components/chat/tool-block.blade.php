{{-- Tool block - responsive design using Tailwind breakpoints --}}
<template x-if="msg.role === 'tool'">
    <div class="max-w-[calc(100%-1rem)] md:max-w-3xl w-full overflow-hidden">
        <div class="border border-blue-500/30 rounded-lg bg-blue-900/20 overflow-x-auto">
            {{-- Header --}}
            <div class="flex items-center flex-wrap md:flex-nowrap gap-2 px-3 md:px-4 py-2 bg-blue-900/30 md:border-b md:border-blue-500/20 cursor-pointer"
                 @click="msg.collapsed = !msg.collapsed">
                {{-- Icon (desktop only) --}}
                <x-icon.cog class="hidden md:block w-4 h-4 text-blue-400" />
                <span class="text-xs md:text-sm font-semibold text-blue-300" x-text="msg.toolName || 'Tool'"></span>
                <span class="text-xs text-gray-500" x-text="formatTimestamp(msg.timestamp)"></span>
                <button @click.stop="copyMessageContent(msg)" class="text-gray-500 hover:text-blue-300 transition-colors flex items-center" title="Copy tool call">
                    <template x-if="copiedMessageId !== msg.id">
                        <i class="fa-regular fa-copy text-xs"></i>
                    </template>
                    <template x-if="copiedMessageId === msg.id">
                        <span class="text-green-400 text-xs font-medium">Copied!</span>
                    </template>
                </button>
                <template x-if="msg.cost">
                    <span class="flex items-center gap-1 md:ml-2">
                        <span class="text-xs text-blue-400" x-text="getModelDisplayName(msg.model)"></span>
                        <span class="text-gray-600">&middot;</span>
                        <span class="text-xs text-green-400" x-text="'$' + msg.cost.toFixed(4)"></span>
                        <button @click.stop="showMessageBreakdown(msg)" class="text-gray-500 hover:text-gray-300 transition-colors" title="View cost breakdown">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                    </span>
                </template>
                {{-- Chevron --}}
                <svg class="w-3 md:w-4 h-3 md:h-4 text-blue-400 ml-auto transition-transform"
                     :class="msg.collapsed ? '-rotate-90' : ''"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            {{-- Content --}}
            <div x-show="!msg.collapsed" class="px-3 md:px-4 py-2 md:py-3 text-xs text-blue-200 md:space-y-2">
                <div x-html="DOMPurify.sanitize(formatToolContent(msg))"></div>
                {{-- Show full/less toggle --}}
                <template x-if="isToolContentTruncated(msg)">
                    <button @click.stop="msg.showFullContent = !msg.showFullContent"
                            class="text-blue-400 hover:text-blue-300 text-xs mt-2 underline underline-offset-2">
                        <span x-text="msg.showFullContent ? 'Show less' : 'Show full'"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
