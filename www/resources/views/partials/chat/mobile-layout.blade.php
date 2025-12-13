{{-- Mobile Header (Fixed) --}}
<div class="fixed top-0 left-0 right-0 z-10 bg-gray-800 border-b border-gray-700 p-4 flex items-center justify-between">
    <button @click="showMobileDrawer = true" class="text-gray-300 hover:text-white">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>
    <h2 class="text-lg font-semibold">PocketDev</h2>
    <a href="/config" class="text-gray-300 hover:text-white">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
    </a>
</div>

{{-- Mobile Drawer Overlay --}}
<div x-show="showMobileDrawer"
     @click="showMobileDrawer = false"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-300"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 bg-black bg-opacity-50 z-40"
     style="display: none;">
</div>

{{-- Mobile Drawer --}}
<div x-show="showMobileDrawer"
     x-transition:enter="transition ease-out duration-300 transform"
     x-transition:enter-start="-translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-300 transform"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="-translate-x-full"
     class="fixed inset-y-0 left-0 w-5/6 max-w-sm bg-gray-800 z-50 flex flex-col"
     style="display: none;">
    <div class="p-4 border-b border-gray-700">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-semibold">Conversations</h2>
            <button @click="showMobileDrawer = false" class="text-gray-400 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <x-button @click="newConversation(); showMobileDrawer = false" variant="primary" full-width>
            New Conversation
        </x-button>
    </div>

    {{-- Conversations List --}}
    <div class="flex-1 overflow-y-auto p-2">
        <template x-if="conversations.length === 0">
            <div class="text-center text-gray-500 text-xs mt-4">No conversations yet</div>
        </template>
        <template x-for="conv in conversations" :key="conv.uuid">
            <div @click="loadConversation(conv.uuid); showMobileDrawer = false"
                 :class="{'bg-gray-700': currentConversationUuid === conv.uuid}"
                 class="p-2 mb-1 rounded hover:bg-gray-700 cursor-pointer transition-colors">
                <div class="text-xs text-gray-300 truncate" x-text="conv.title || 'New Conversation'"></div>
                <div class="text-xs text-gray-500 mt-1" x-text="formatDate(conv.last_activity_at || conv.created_at)"></div>
            </div>
        </template>
    </div>

    {{-- Footer --}}
    <div class="p-4 border-t border-gray-700 text-xs text-gray-400">
        <div class="mb-2">Cost: <span class="text-green-400 font-mono" x-text="'$' + sessionCost.toFixed(4)">$0.00</span></div>
        <div class="mb-2"><span x-text="totalTokens.toLocaleString() + ' tokens'">0 tokens</span></div>
        <div class="mb-2 text-gray-300">
            <span class="text-gray-500">Model:</span> <span x-text="availableModels[model]?.name || model"></span>
        </div>
        <div class="flex flex-wrap gap-3">
            <x-button @click="showQuickSettings = true; showMobileDrawer = false" variant="ghost" size="sm">
                Quick Settings
            </x-button>
            <x-button @click="showShortcutsModal = true; showMobileDrawer = false" variant="ghost" size="sm">
                Shortcuts
            </x-button>
        </div>
    </div>
</div>
