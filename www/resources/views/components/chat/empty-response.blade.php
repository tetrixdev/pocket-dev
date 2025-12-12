@props(['variant' => 'desktop'])

@php
    $maxWidth = $variant === 'mobile' ? 'max-w-[85%] w-full' : 'max-w-3xl w-full';
    $padding = $variant === 'mobile' ? 'px-3 py-2' : 'px-4 py-2';
@endphp

<template x-if="msg.role === 'empty-response'">
    <div class="{{ $maxWidth }}">
        <div class="{{ $padding }} rounded-lg bg-gray-800/50 border border-gray-700/50">
            <div class="text-xs text-gray-500 flex items-center justify-between gap-2">
                <span class="italic">No response content</span>
                <span class="flex items-center gap-2">
                    <span x-text="formatTimestamp(msg.timestamp)"></span>
                    <template x-if="msg.cost">
                        <x-chat.cost-badge />
                    </template>
                </span>
            </div>
        </div>
    </div>
</template>
