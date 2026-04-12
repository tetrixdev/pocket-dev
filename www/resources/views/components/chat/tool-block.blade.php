{{-- Tool block - responsive design using Tailwind breakpoints --}}
<template x-if="msg.role === 'tool'">
    <div class="max-w-[calc(100%-1rem)] md:max-w-3xl w-full overflow-hidden">
        <div class="border rounded-lg overflow-x-auto"
             :class="msg.toolInterrupted ? 'border-amber-500/40 bg-amber-900/15' : 'border-blue-500/30 bg-blue-900/20'">
            {{-- Header --}}
            <div class="flex items-center flex-wrap md:flex-nowrap gap-2 px-3 md:px-4 py-2 cursor-pointer"
                 :class="msg.toolInterrupted ? 'bg-amber-900/25 md:border-b md:border-amber-500/20' : 'bg-blue-900/30 md:border-b md:border-blue-500/20'"
                 @click="msg.collapsed = !msg.collapsed">
                {{-- Icon (desktop only) --}}
                <template x-if="!msg.toolInterrupted">
                    <x-icon.cog class="hidden md:block w-4 h-4 text-blue-400" />
                </template>
                <template x-if="msg.toolInterrupted">
                    <i class="hidden md:block fa-solid fa-triangle-exclamation text-amber-400 text-sm"></i>
                </template>
                <span class="text-xs md:text-sm font-semibold"
                      :class="msg.toolInterrupted ? 'text-amber-300' : 'text-blue-300'"
                      x-text="msg.toolName || 'Tool'"></span>
                <template x-if="msg.toolInterrupted">
                    <span class="text-xs text-amber-400/80 italic">(interrupted)</span>
                </template>
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
                <svg class="w-3 md:w-4 h-3 md:h-4 ml-auto transition-transform"
                     :class="[msg.collapsed ? '-rotate-90' : '', msg.toolInterrupted ? 'text-amber-400' : 'text-blue-400']"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            {{-- Content --}}
            <div x-show="!msg.collapsed" class="px-3 md:px-4 py-2 md:py-3 text-xs md:space-y-2"
                 :class="msg.toolInterrupted ? 'text-amber-200/80' : 'text-blue-200'">
                {{-- Case 1: Interrupted with no input at all --}}
                <template x-if="msg.toolInterrupted && !msg.toolPartialInput && (!msg.toolInput || (typeof msg.toolInput === 'object' && Object.keys(msg.toolInput).length === 0))">
                    <div class="text-amber-400/70 italic text-xs">Tool call was interrupted before input was received.</div>
                </template>
                {{-- Case 2: Interrupted with partial raw JSON (incomplete but informative) --}}
                <template x-if="msg.toolInterrupted && msg.toolPartialInput">
                    <div>
                        <pre class="text-amber-200/70 whitespace-pre-wrap break-all text-xs" x-text="msg.toolPartialInput"></pre>
                    </div>
                </template>
                {{-- Case 3: Has valid parsed input (interrupted or not) --}}
                <template x-if="!msg.toolInterrupted || (msg.toolInput && !msg.toolPartialInput && !(typeof msg.toolInput === 'object' && Object.keys(msg.toolInput).length === 0))">
                    <div x-html="formatToolContent(msg)"></div>
                </template>
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
