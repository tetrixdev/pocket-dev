{{-- System info block - for /context, /usage, etc. --}}
<template x-if="msg.role === 'system'">
    <div class="max-w-[calc(100%-1rem)] md:max-w-3xl w-full">
        <div class="border border-gray-600/30 rounded-lg bg-gray-800/50 overflow-hidden">
            {{-- Header --}}
            <div class="flex items-center gap-2 px-3 md:px-4 py-2 bg-gray-700/30 border-b border-gray-600/20">
                <x-icon.info class="w-4 h-4 text-gray-400" />
                <span class="text-xs md:text-sm font-semibold text-gray-300" x-text="msg.command || 'System Info'"></span>
            </div>
            {{-- Content - render markdown --}}
            <div class="px-3 md:px-4 py-2 md:py-3 text-xs md:text-sm text-gray-200 prose prose-invert prose-sm max-w-none">
                <div x-html="DOMPurify.sanitize(marked.parse(msg.content || ''))"></div>
            </div>
        </div>
    </div>
</template>
