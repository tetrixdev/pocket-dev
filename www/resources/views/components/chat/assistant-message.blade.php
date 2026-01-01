{{-- Assistant message - responsive design using Tailwind breakpoints --}}
<template x-if="msg.role === 'assistant'">
    <div class="max-w-[calc(100%-1rem)] md:max-w-3xl w-full">
        <div class="px-4 py-3 rounded-lg bg-gray-800">
            <div class="text-sm markdown-content" x-html="renderMarkdown(msg.content)"></div>
            <div class="text-xs mt-2 text-gray-400 flex items-center gap-2">
                <span x-text="formatTimestamp(msg.timestamp)"></span>
                <button @click="copyMessageContent(msg)" class="text-gray-400 hover:text-white transition-colors relative" title="Copy message">
                    <template x-if="copiedMessageId !== msg.id">
                        <i class="fa-regular fa-copy"></i>
                    </template>
                    <template x-if="copiedMessageId === msg.id">
                        <span class="text-green-400 text-xs font-medium">Copied!</span>
                    </template>
                </button>
                <template x-if="msg.cost">
                    <x-chat.cost-badge />
                </template>
            </div>
        </div>
    </div>
</template>
