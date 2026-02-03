{{-- Mobile Screen Tabs - Compact tabs for switching between screens --}}
<div x-show="currentSession"
     x-cloak
     id="screen-tabs-mobile"
     class="fixed top-[57px] left-0 right-0 z-10 flex items-center gap-1 bg-gray-800 border-b border-gray-700 px-2 py-1.5 overflow-x-auto"
     x-ref="mobileScreenTabsContainer"
     @screen-added.window="$nextTick(() => { if ($refs.mobileScreenTabsContainer) $refs.mobileScreenTabsContainer.scrollLeft = $refs.mobileScreenTabsContainer.scrollWidth; })"
     style="-webkit-overflow-scrolling: touch;">

    {{-- Screen Tabs --}}
    <template x-for="screenId in (currentSession?.screen_order || [])" :key="screenId">
        <div class="group relative flex items-center shrink-0">
            <button @click="activateScreen(screenId)"
                    :class="activeScreenId === screenId
                        ? 'bg-gray-700 text-white border-gray-600'
                        : 'bg-gray-800/50 text-gray-400 border-transparent'"
                    class="flex items-center gap-1.5 px-3 py-2 text-xs rounded border transition-colors cursor-pointer min-h-[44px] min-w-[44px]"
                    :title="getScreenTitle(screenId)">
                {{-- Screen Type Icon --}}
                <span :class="getScreenTypeColor(screenId)">
                    <i :class="getScreenIcon(screenId)"></i>
                </span>
                {{-- Screen Title (abbreviated) --}}
                <span class="truncate max-w-[80px]" x-text="getScreenTitle(screenId).substring(0, 12) + (getScreenTitle(screenId).length > 12 ? '...' : '')"></span>
                {{-- Close Button (inline for mobile, always visible when 2+ screens) --}}
                <span x-show="screens.length > 1"
                      @click.stop="closeScreen(screenId)"
                      class="ml-1 w-5 h-5 rounded-full bg-gray-600 hover:bg-red-500 text-gray-300 hover:text-white flex items-center justify-center cursor-pointer shrink-0"
                      title="Close">
                    <i class="fa-solid fa-xmark text-[10px]"></i>
                </span>
            </button>
        </div>
    </template>

    {{-- Add Screen Button --}}
    <div class="shrink-0" x-data="{ showMobileAddMenu: false }">
        <button @click="showMobileAddMenu = !showMobileAddMenu"
                x-ref="mobileAddBtn"
                class="flex items-center justify-center w-11 h-11 text-gray-400 hover:text-white hover:bg-gray-700 rounded transition-colors cursor-pointer"
                title="Add screen">
            <i class="fa-solid fa-plus text-sm"></i>
        </button>
        {{-- Add Screen Dropdown - Fixed position to escape overflow:auto parent --}}
        <template x-teleport="body">
            <div x-show="showMobileAddMenu"
                 x-cloak
                 @click.outside="showMobileAddMenu = false"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="fixed w-48 bg-gray-700 rounded-lg shadow-lg border border-gray-600 py-1 z-[100]"
                 :style="{ top: ($refs.mobileAddBtn?.getBoundingClientRect().bottom + 4) + 'px', right: '8px' }">
                {{-- New Chat --}}
                <button @click="addChatScreen(); showMobileAddMenu = false"
                        class="flex items-center gap-2 px-4 py-3 text-sm text-gray-200 hover:bg-gray-600 w-full text-left cursor-pointer min-h-[44px]">
                    <i class="fa-solid fa-comment text-blue-400 w-4 text-center"></i>
                    New Chat
                </button>
                {{-- Divider --}}
                <div class="border-t border-gray-600 my-1" x-show="availablePanels.length > 0"></div>
                {{-- Available Panels --}}
                <template x-for="panel in availablePanels" :key="panel.slug">
                    <button @click="addPanelScreen(panel.slug); showMobileAddMenu = false"
                            class="flex items-center gap-2 px-4 py-3 text-sm text-gray-200 hover:bg-gray-600 w-full text-left cursor-pointer min-h-[44px]">
                        <i class="fa-solid fa-table-columns text-purple-400 w-4 text-center"></i>
                        <span x-text="panel.name"></span>
                    </button>
                </template>
                {{-- No panels message --}}
                <template x-if="availablePanels.length === 0">
                    <div class="px-4 py-2 text-xs text-gray-500">
                        No panels available
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>
