{{-- Shortcuts Help Modal --}}
<x-modal show="showShortcutsModal" title="Keyboard Shortcuts">
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

    <x-button variant="primary" full-width class="mt-6" @click="showShortcutsModal = false">
        Close
    </x-button>
</x-modal>
