{{--
Debug Panel Component

Usage: Include <x-debug-panel /> on any page to enable debug logging.
The panel is hidden by default and can be opened via $store.debug.toggle()

To log from JavaScript:
  - debugLog('message', optionalData)  // Global helper
  - $store.debug.log('message', data)  // Alpine store method

To open the panel:
  - Button: @click="$store.debug.toggle()"
  - JS: Alpine.store('debug').toggle()
--}}

<div x-data x-show="$store.debug.showPanel"
     x-cloak
     class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black bg-opacity-50"
     @click.self="$store.debug.showPanel = false"
     @keydown.escape.window="$store.debug.showPanel = false">
    <div class="bg-gray-800 rounded-lg w-full max-w-2xl max-h-[80vh] flex flex-col shadow-xl border border-gray-700">
        <div class="flex items-center justify-between p-4 border-b border-gray-700">
            <h3 class="text-lg font-semibold text-white">Debug Log</h3>
            <div class="flex gap-2">
                @if(isset($customCopy))
                    {{ $customCopy }}
                @else
                    <button @click="$store.debug.copy()"
                            class="text-xs text-gray-400 hover:text-white px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded transition-colors"
                            title="Copy to clipboard">
                        <i class="fas fa-copy mr-1"></i>Copy
                    </button>
                @endif
                <button @click="$store.debug.clear()"
                        class="text-xs text-gray-400 hover:text-white px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded transition-colors"
                        title="Clear logs">
                    <i class="fas fa-trash mr-1"></i>Clear
                </button>
                <button @click="$store.debug.showPanel = false" class="text-gray-400 hover:text-white ml-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        {{-- Optional: Page-specific state display --}}
        {{ $stateDisplay ?? '' }}

        <div class="flex-1 overflow-y-auto p-4 font-mono text-xs bg-gray-900">
            <template x-if="$store.debug.logs.length === 0">
                <div class="text-gray-500 text-center py-8">No log entries yet</div>
            </template>
            <template x-for="(log, index) in $store.debug.logs" :key="index">
                <div class="py-1.5 border-b border-gray-800 hover:bg-gray-800/50">
                    <span class="text-gray-500" x-text="log.timestamp"></span>
                    <span class="text-gray-200 ml-2" x-text="log.message"></span>
                    <template x-if="log.data !== null">
                        <span class="text-blue-400 ml-2" x-text="typeof log.data === 'object' ? JSON.stringify(log.data) : log.data"></span>
                    </template>
                </div>
            </template>
        </div>

        <div class="p-3 border-t border-gray-700 text-xs text-gray-500">
            <span x-text="$store.debug.logs.length"></span> entries
        </div>
    </div>
</div>
