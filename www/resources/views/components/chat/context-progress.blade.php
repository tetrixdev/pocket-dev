{{-- Context Window Progress Bar --}}
{{-- Compact progress bar showing context usage with percentage in center --}}
{{-- Used in header area next to agent selector --}}

@props(['compact' => false])

@if($compact)
{{-- Compact version for mobile --}}
<div class="flex items-center gap-1.5" x-show="contextWindowSize > 0" x-cloak
     role="progressbar" :aria-valuenow="contextPercentage" aria-valuemin="0" aria-valuemax="100"
     :aria-label="'Context window usage: ' + contextPercentage.toFixed(0) + '%'">
    <div class="relative w-16 h-3 bg-gray-700 rounded-full overflow-hidden"
         :title="formatContextUsage()">
        {{-- Progress fill --}}
        <div class="absolute inset-y-0 left-0 rounded-full transition-all duration-300"
             :class="getContextBarColorClass()"
             :style="'width: ' + Math.min(100, contextPercentage) + '%'"></div>
        {{-- Percentage text centered --}}
        <span class="absolute inset-0 flex items-center justify-center text-[10px] font-mono text-white font-medium"
              x-text="contextPercentage.toFixed(0) + '%'"></span>
    </div>
</div>
@else
{{-- Standard version for desktop --}}
<div class="flex items-center gap-2" x-show="contextWindowSize > 0" x-cloak
     role="progressbar" :aria-valuenow="contextPercentage" aria-valuemin="0" aria-valuemax="100"
     :aria-label="'Context window usage: ' + contextPercentage.toFixed(0) + '%'">
    <div class="relative w-20 h-4 bg-gray-700 rounded-full overflow-hidden cursor-help"
         :title="formatContextUsage()">
        {{-- Progress fill --}}
        <div class="absolute inset-y-0 left-0 rounded-full transition-all duration-300"
             :class="getContextBarColorClass()"
             :style="'width: ' + Math.min(100, contextPercentage) + '%'"></div>
        {{-- Percentage text centered --}}
        <span class="absolute inset-0 flex items-center justify-center text-xs font-mono text-white font-medium"
              x-text="contextPercentage.toFixed(0) + '%'"></span>
    </div>
    {{-- Warning icon for danger level --}}
    <svg x-show="contextWarningLevel === 'danger'"
         x-cloak
         class="w-4 h-4 text-red-400 animate-pulse"
         fill="currentColor"
         viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
    </svg>
</div>
@endif
