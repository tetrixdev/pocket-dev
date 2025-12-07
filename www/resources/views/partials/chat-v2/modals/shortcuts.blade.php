{{-- Shortcuts Help Modal --}}
<div x-show="showShortcutsModal"
     @click.self="showShortcutsModal = false"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 backdrop-blur-sm"
     style="display: none;">
    <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 shadow-2xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Keyboard Shortcuts</h2>

        <div class="space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-gray-300">Send Message</span>
                <kbd class="px-2 py-1 bg-gray-700 rounded text-sm font-mono">Enter</kbd>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-300">Toggle Voice Recording</span>
                <kbd class="px-2 py-1 bg-gray-700 rounded text-sm font-mono">Ctrl+Space</kbd>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-300">Toggle Thinking Mode</span>
                <kbd class="px-2 py-1 bg-gray-700 rounded text-sm font-mono">Ctrl+T</kbd>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-300">Show This Help</span>
                <kbd class="px-2 py-1 bg-gray-700 rounded text-sm font-mono">Ctrl+?</kbd>
            </div>
        </div>

        <button @click="showShortcutsModal = false"
                class="w-full mt-6 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-white font-medium">
            Close
        </button>
    </div>
</div>
