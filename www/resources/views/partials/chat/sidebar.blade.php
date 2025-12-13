{{-- Desktop Sidebar --}}
<div class="w-64 bg-gray-800 border-r border-gray-700 flex flex-col">
    <div class="p-4 border-b border-gray-700">
        <h2 class="text-lg font-semibold">PocketDev</h2>
        <button @click="newConversation()" class="mt-2 w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm">New Conversation</button>
    </div>

    {{-- Conversations List --}}
    <div class="flex-1 overflow-y-auto p-2" id="conversations-list">
        <template x-if="conversations.length === 0">
            <div class="text-center text-gray-500 text-xs mt-4">No conversations yet</div>
        </template>
        <template x-for="conv in conversations" :key="conv.uuid">
            <div @click="loadConversation(conv.uuid)"
                 :class="{'bg-gray-700': currentConversationUuid === conv.uuid}"
                 class="p-2 mb-1 rounded hover:bg-gray-700 cursor-pointer transition-colors">
                <div class="text-xs text-gray-300 truncate" x-text="conv.title || 'New Conversation'"></div>
                <div class="text-xs text-gray-500 mt-1" x-text="formatDate(conv.last_activity_at || conv.created_at)"></div>
            </div>
        </template>
    </div>

    {{-- Footer Info --}}
    <div class="p-4 border-t border-gray-700 text-xs text-gray-400">
        <div class="mb-3 pb-3 border-b border-gray-700">
            <div class="flex items-center justify-between mb-1">
                <div class="flex items-center gap-1">
                    <span class="text-gray-300 font-semibold">Session Cost</span>
                    <button @click="showPricingSettings = true" class="text-gray-400 hover:text-gray-200 transition-colors" title="Configure pricing">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </button>
                </div>
                <span class="text-green-400 font-mono" x-text="'$' + sessionCost.toFixed(4)">$0.00</span>
            </div>
            <div class="text-gray-500" style="font-size: 10px;">
                <span x-text="totalTokens.toLocaleString() + ' tokens'">0 tokens</span>
            </div>
        </div>
        {{-- Current Model Display --}}
        <div class="mb-2 text-gray-300">
            <span class="text-gray-500">Model:</span> <span x-text="availableModels[model]?.name || model" class="font-medium"></span>
        </div>
        <div>Working Dir: /var/www</div>
        <div class="flex flex-wrap gap-2 mt-2">
            <button @click="showQuickSettings = true" class="text-blue-400 hover:text-blue-300">Quick Settings</button>
            <a href="/config" class="text-blue-400 hover:text-blue-300">Config</a>
            <button @click="showShortcutsModal = true" class="text-blue-400 hover:text-blue-300">Shortcuts</button>
        </div>
    </div>
</div>
