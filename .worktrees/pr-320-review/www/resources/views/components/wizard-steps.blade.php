@props(['current' => 1, 'labels' => []])
@php
    $total = count($labels);
@endphp
<ol class="mb-6 flex items-center justify-center gap-2 text-xs" aria-label="Wizard progress">
    @for($i = 1; $i <= $total; $i++)
        @php
            $isDone = $i < $current;
            $isCurrent = $i === $current;
        @endphp
        <li class="flex items-center gap-2">
            <span @class([
                'flex items-center justify-center w-7 h-7 rounded-full text-xs font-medium shrink-0',
                'bg-blue-600 text-white' => $isCurrent,
                'bg-green-600 text-white' => $isDone,
                'bg-gray-700 text-gray-400' => !$isCurrent && !$isDone,
            ])
            @if($isCurrent) aria-current="step" @endif>
                @if($isDone)
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                    </svg>
                @else
                    {{ $i }}
                @endif
            </span>
            <span @class([
                'text-xs whitespace-nowrap',
                'text-white font-medium' => $isCurrent,
                'text-gray-400' => !$isCurrent,
            ])>{{ $labels[$i - 1] }}</span>
            @if($i < $total)
                <span aria-hidden="true" @class(['h-px w-8 shrink-0', $isDone ? 'bg-green-600' : 'bg-gray-700'])></span>
            @endif
        </li>
    @endfor
</ol>
