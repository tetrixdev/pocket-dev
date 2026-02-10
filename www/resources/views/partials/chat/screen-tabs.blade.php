{{-- Screen Tabs - Shows all screens in the current session --}}
<div x-show="currentSession"
     x-cloak
     id="screen-tabs"
     class="flex items-center gap-1 bg-gray-800 border-b border-gray-700 px-2 py-1 overflow-x-auto"
     x-ref="screenTabsContainer"
     @screen-added.window="$nextTick(() => scrollToActiveTab())">

    {{-- Screen Tabs (only visible/non-archived screens) --}}
    <template x-for="screenId in visibleScreenOrder" :key="screenId">
        <div class="group relative flex items-center shrink-0"
             data-screen-tab
             draggable="true"
             @dragstart="handleDragStart($event, screenId)"
             @dragover="handleDragOver($event, screenId)"
             @dragleave="handleDragLeave($event)"
             @drop="handleDrop($event, screenId)"
             @dragend="handleDragEnd($event)"
             :class="{
                 'before:absolute before:left-0 before:top-0 before:bottom-0 before:w-0.5 before:bg-blue-500 before:z-20': dragOverScreenId === screenId && dragDropPosition === 'before',
                 'after:absolute after:right-0 after:top-0 after:bottom-0 after:w-0.5 after:bg-blue-500 after:z-20': dragOverScreenId === screenId && dragDropPosition === 'after'
             }">
            <button @click="activateScreen(screenId)"
                    :class="activeScreenId === screenId
                        ? 'bg-gray-700 text-white border-gray-600'
                        : 'bg-gray-800/50 text-gray-400 hover:text-gray-200 hover:bg-gray-700/50 border-transparent'"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-t border border-b-0 transition-colors cursor-pointer max-w-[160px]"
                    :title="getScreenTitle(screenId)">
                {{-- Screen Type Icon --}}
                <span class="inline-flex items-center justify-center w-4 h-4 rounded-sm shrink-0"
                      :class="getScreenTypeColor(screenId)">
                    <i class="text-white text-[10px]" :class="getScreenIcon(screenId)"></i>
                </span>
                {{-- Screen Tab Label (short form) --}}
                <span class="truncate" x-text="getScreenTabLabel(screenId)"></span>
            </button>
            {{-- Archive/Close Button --}}
            <button @click.stop="toggleArchiveConversation(screenId)"
                    x-show="visibleScreenOrder.length > 1"
                    :class="getScreen(screenId)?.type === 'panel' ? 'hover:bg-red-500' : 'hover:bg-amber-500'"
                    class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-gray-600 text-gray-300 hover:text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer z-10"
                    :title="getScreen(screenId)?.type === 'panel' ? 'Close' : 'Archive'">
                <i :class="getScreen(screenId)?.type === 'panel' ? 'fa-solid fa-xmark' : 'fa-solid fa-box-archive'" class="text-[8px]"></i>
            </button>
        </div>
    </template>

    {{-- Add Screen Button --}}
    <div class="relative shrink-0" x-data="{ showAddMenu: false, menuPos: { top: 0, left: 0 } }" x-ref="addButtonContainer">
        <button @click="
                    const rect = $refs.addButtonContainer.getBoundingClientRect();
                    menuPos = { top: rect.bottom + 4, left: rect.left };
                    showAddMenu = !showAddMenu;
                "
                class="flex items-center justify-center w-7 h-7 text-gray-400 hover:text-white hover:bg-gray-700 rounded transition-colors cursor-pointer"
                title="Add screen">
            <i class="fa-solid fa-plus text-xs"></i>
        </button>
        {{-- Add Screen Dropdown - uses fixed positioning to escape overflow:hidden parent --}}
        <div x-show="showAddMenu"
             x-cloak
             @click.outside="showAddMenu = false"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="fixed w-48 bg-gray-700 rounded-lg shadow-lg border border-gray-600 py-1 z-50"
             :style="{ top: menuPos.top + 'px', left: menuPos.left + 'px' }">
            {{-- New Chat --}}
            <button @click="addChatScreen(); showAddMenu = false"
                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600 w-full text-left cursor-pointer">
                <i class="fa-solid fa-comment text-blue-400 w-4 text-center"></i>
                New Chat
            </button>
            {{-- Divider --}}
            <div class="border-t border-gray-600 my-1" x-show="availablePanels.length > 0"></div>
            {{-- Available Panels --}}
            <template x-for="panel in availablePanels" :key="panel.slug">
                <button @click="addPanelScreen(panel.slug); showAddMenu = false"
                        class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600 w-full text-left cursor-pointer">
                    <i :class="panel.icon || 'fa-solid fa-table-columns'" class="text-purple-400 w-4 text-center"></i>
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
    </div>
</div>
