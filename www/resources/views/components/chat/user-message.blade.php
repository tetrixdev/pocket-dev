{{-- User message - responsive design using Tailwind breakpoints --}}
<template x-if="msg.role === 'user'">
    <div class="max-w-[calc(100%-1rem)] md:max-w-3xl overflow-hidden">
        <div class="px-4 py-3 rounded-lg bg-blue-600 overflow-x-auto">
            <div class="text-sm markdown-content" x-html="renderMarkdown(msg.content)"></div>
            <div class="text-xs mt-2 text-gray-300 flex items-center justify-end gap-2">
                <span x-text="formatTimestamp(msg.timestamp)"></span>
                <button @click="copyMessageContent(msg)" class="text-gray-300 hover:text-white transition-colors relative" title="Copy message">
                    <template x-if="copiedMessageId !== msg.id">
                        <i class="fa-regular fa-copy"></i>
                    </template>
                    <template x-if="copiedMessageId === msg.id">
                        <span class="text-green-300 text-xs font-medium">Copied!</span>
                    </template>
                </button>
            </div>
        </div>
    </div>
</template>
