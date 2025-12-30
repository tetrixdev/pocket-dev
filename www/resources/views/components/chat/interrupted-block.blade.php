{{-- Interrupted block - styled like error header (no icon, not expandable) --}}
<template x-if="msg.role === 'interrupted'">
    <div class="max-w-[calc(100%-1rem)] md:max-w-3xl w-full">
        <div class="border border-red-500/30 rounded-lg bg-red-900/30 overflow-hidden">
            <div class="flex items-center gap-2 px-3 md:px-4 py-2">
                <span class="text-xs md:text-sm font-semibold text-red-300">Response interrupted</span>
                <span class="text-xs text-gray-500" x-text="formatTimestamp(msg.timestamp)"></span>
            </div>
        </div>
    </div>
</template>
