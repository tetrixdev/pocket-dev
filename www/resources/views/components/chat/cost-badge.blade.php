{{-- Cost badge with breakdown button - used inside Alpine x-for loops --}}
<span class="flex items-center gap-1">
    <span class="text-blue-400" x-text="getModelDisplayName(msg.model)"></span>
    <span class="text-gray-600">&middot;</span>
    <span class="text-green-400" x-text="'$' + msg.cost.toFixed(4)"></span>
    <button @click.stop="showMessageBreakdown(msg)" class="text-gray-500 hover:text-gray-300 transition-colors" title="View cost breakdown">
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
    </button>
</span>
