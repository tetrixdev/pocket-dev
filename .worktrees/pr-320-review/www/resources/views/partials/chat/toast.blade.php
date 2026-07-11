{{-- Toast Notification --}}
<div x-show="toastVisible"
     x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 transform translate-y-2"
     x-transition:enter-end="opacity-100 transform translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 transform translate-y-0"
     x-transition:leave-end="opacity-0 transform translate-y-2"
     class="fixed bottom-4 right-4 z-50 bg-gray-800 border border-gray-600 rounded-lg shadow-lg px-4 py-3 flex items-center gap-3">
    <i class="fa-solid fa-check-circle text-green-400"></i>
    <span class="text-sm text-gray-200" x-text="toastMessage"></span>
</div>
