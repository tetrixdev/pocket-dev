@props([
    'title',
    'subtitle' => null,
    'badge' => null,
    'expanded' => true,
    'id' => null,
])

@php
    $sectionId = $id ?? Str::slug($title);
@endphp

<div
    x-data="{ expanded: {{ $expanded ? 'true' : 'false' }} }"
    class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden"
>
    {{-- Header --}}
    <button
        @click="expanded = !expanded"
        class="w-full px-4 py-3 bg-gray-750 border-b border-gray-700 flex items-center justify-between hover:bg-gray-700 transition-colors"
    >
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-lg font-semibold">{{ $title }}</span>
            @if($badge)
                <span class="text-xs text-gray-500 bg-gray-700 px-2 py-0.5 rounded">{{ $badge }}</span>
            @endif
            @if($subtitle)
                <span class="text-xs text-gray-500">{{ $subtitle }}</span>
            @endif
        </div>
        <svg
            :class="{ 'rotate-180': expanded }"
            class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
        >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    {{-- Content --}}
    <div x-show="expanded" x-collapse>
        {{ $slot }}
    </div>
</div>

<style>
    .bg-gray-750 {
        background-color: rgb(42, 48, 60);
    }
</style>
