{{-- Assistant message - responsive design using Tailwind breakpoints --}}
<template x-if="msg.role === 'assistant'">
    <div class="max-w-[calc(100%-1rem)] md:max-w-3xl w-full overflow-hidden">
        <div class="px-4 py-3 rounded-lg bg-gray-800 overflow-x-auto">
            <div class="text-sm markdown-content" x-html="renderMarkdown(msg.content)"></div>
            <div class="text-xs mt-2 text-gray-400 flex items-center gap-2">
                <span x-text="formatTimestamp(msg.finishedAt || msg.startedAt || msg.timestamp)"
                      :title="msg.startedAt && msg.finishedAt ? formatTimestamp(msg.startedAt) + ' → ' + formatTimestamp(msg.finishedAt) + ' (' + formatDuration(msg.startedAt, msg.finishedAt) + ')' : ''"></span>
                <template x-if="msg.startedAt && msg.finishedAt">
                    <span x-text="formatDuration(msg.startedAt, msg.finishedAt)" class="text-gray-500"></span>
                </template>
                <button @click="copyMessageContent(msg)" class="text-gray-400 hover:text-white transition-colors relative" title="Copy message">
                    <template x-if="copiedMessageId !== msg.id">
                        <i class="fa-regular fa-copy"></i>
                    </template>
                    <template x-if="copiedMessageId === msg.id">
                        <span class="text-green-400 text-xs font-medium">Copied!</span>
                    </template>
                </button>
                <x-chat.cost-badge />
            </div>
        </div>
    </div>
</template>
