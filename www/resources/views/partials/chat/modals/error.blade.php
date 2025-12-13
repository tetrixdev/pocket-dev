{{-- Error Modal --}}
<x-modal show="showErrorModal">
    <h2 class="text-xl font-semibold text-red-400 mb-4">Error</h2>
    <p class="text-gray-300 mb-4" x-text="errorMessage"></p>
    <x-button variant="secondary" full-width @click="showErrorModal = false">
        Close
    </x-button>
</x-modal>
