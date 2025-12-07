@props(['variant' => 'desktop'])

@php
    $maxWidth = $variant === 'mobile' ? 'max-w-[85%] w-full' : 'max-w-3xl w-full';
@endphp

<template x-if="msg.role === 'assistant'">
    <div class="{{ $maxWidth }}">
        <div class="px-4 py-3 rounded-lg bg-gray-800">
            <div class="text-sm markdown-content" x-html="renderMarkdown(msg.content)"></div>
            <div class="text-xs mt-2 text-gray-400 flex items-center gap-2">
                <span x-text="formatTimestamp(msg.timestamp)"></span>
                <template x-if="msg.cost">
                    <span class="flex items-center gap-1">
                        <span class="text-green-400" x-text="'$' + msg.cost.toFixed(4)"></span>
                        <button @click="showMessageBreakdown(msg)" class="text-gray-500 hover:text-gray-300 transition-colors" title="View cost breakdown">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                    </span>
                </template>
            </div>
        </div>
    </div>
</template>
