@props(['variant' => 'desktop'])

@php
    $maxWidth = $variant === 'mobile' ? 'max-w-[85%] w-full' : 'max-w-3xl w-full';
    $headerPadding = $variant === 'mobile' ? 'px-3 py-2' : 'px-4 py-2';
    $contentPadding = $variant === 'mobile' ? 'px-3 py-2' : 'px-4 py-3';
    $iconSize = $variant === 'mobile' ? 'w-3 h-3' : 'w-4 h-4';
    $labelClass = $variant === 'mobile' ? 'text-xs' : 'text-sm';
    $flexWrap = $variant === 'mobile' ? 'flex-wrap' : '';
    $hasBorder = $variant === 'desktop';
@endphp

<template x-if="msg.role === 'thinking'">
    <div class="{{ $maxWidth }}">
        <div class="border border-purple-500/30 rounded-lg bg-purple-900/20 overflow-hidden">
            <div class="flex items-center {{ $flexWrap }} gap-2 {{ $headerPadding }} bg-purple-900/30 @if($hasBorder) border-b border-purple-500/20 @endif cursor-pointer"
                 @click="msg.collapsed = !msg.collapsed">
                @if($variant === 'desktop')
                <x-icon.lightbulb class="w-4 h-4 text-purple-400" />
                @endif
                <span class="{{ $labelClass }} font-semibold text-purple-300">Thinking</span>
                <span class="text-xs text-gray-500" x-text="formatTimestamp(msg.timestamp)"></span>
                <template x-if="msg.cost">
                    <span class="flex items-center gap-1 @if($variant === 'desktop') ml-2 @endif">
                        <span class="text-xs text-green-400" x-text="'$' + msg.cost.toFixed(4)"></span>
                        <button @click.stop="showMessageBreakdown(msg)" class="text-gray-500 hover:text-gray-300 transition-colors" title="View cost breakdown">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                    </span>
                </template>
                <svg class="{{ $iconSize }} text-purple-400 ml-auto transition-transform"
                     :class="msg.collapsed ? '-rotate-90' : ''"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            <div x-show="!msg.collapsed" class="{{ $contentPadding }}">
                <div class="text-xs text-purple-200 whitespace-pre-wrap font-mono" x-text="msg.content"></div>
            </div>
        </div>
    </div>
</template>
