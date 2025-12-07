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

<template x-if="msg.role === 'tool'">
    <div class="{{ $maxWidth }}">
        <div class="border border-blue-500/30 rounded-lg bg-blue-900/20 overflow-hidden">
            <div class="flex items-center {{ $flexWrap }} gap-2 {{ $headerPadding }} bg-blue-900/30 @if($hasBorder) border-b border-blue-500/20 @endif cursor-pointer"
                 @click="msg.collapsed = !msg.collapsed">
                @if($variant === 'desktop')
                <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                @endif
                <span class="{{ $labelClass }} font-semibold text-blue-300" x-text="msg.toolName || 'Tool'"></span>
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
                <svg class="{{ $iconSize }} text-blue-400 ml-auto transition-transform"
                     :class="msg.collapsed ? '-rotate-90' : ''"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            <div x-show="!msg.collapsed" class="{{ $contentPadding }} text-xs text-blue-200 @if($variant === 'desktop') space-y-2 @endif">
                <div x-html="formatToolContent(msg)"></div>
            </div>
        </div>
    </div>
</template>
