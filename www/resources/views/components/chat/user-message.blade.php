@props(['variant' => 'desktop'])

@php
    $maxWidth = $variant === 'mobile' ? 'max-w-[85%]' : 'max-w-3xl';
    $showTimestamp = $variant === 'desktop';
@endphp

<template x-if="msg.role === 'user'">
    <div class="{{ $maxWidth }}">
        <div class="px-4 py-3 rounded-lg bg-blue-600">
            <div class="text-sm whitespace-pre-wrap" x-text="msg.content"></div>
            @if($showTimestamp)
            <div class="text-xs mt-2 text-gray-300 text-right" x-text="formatTimestamp(msg.timestamp)"></div>
            @endif
        </div>
    </div>
</template>
