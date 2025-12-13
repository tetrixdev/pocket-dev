{{-- User message - responsive design using Tailwind breakpoints --}}
<template x-if="msg.role === 'user'">
    <div class="max-w-[85%] md:max-w-3xl">
        <div class="px-4 py-3 rounded-lg bg-blue-600">
            <div class="text-sm whitespace-pre-wrap" x-text="msg.content"></div>
            <div class="hidden md:block text-xs mt-2 text-gray-300 text-right" x-text="formatTimestamp(msg.timestamp)"></div>
        </div>
    </div>
</template>
