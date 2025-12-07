{{-- Error Modal --}}
<div x-show="showErrorModal"
     @click.self="showErrorModal = false"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 backdrop-blur-sm"
     style="display: none;">
    <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 shadow-2xl">
        <h2 class="text-xl font-semibold text-red-400 mb-4">Error</h2>
        <p class="text-gray-300 mb-4" x-text="errorMessage"></p>
        <button @click="showErrorModal = false"
                class="w-full px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-white font-medium">
            Close
        </button>
    </div>
</div>
