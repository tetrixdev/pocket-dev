@props([
    'show' => 'showModal',
    'maxWidth' => 'md',
    'title' => null,
])

@php
    $maxWidthClass = match ($maxWidth) {
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        default => 'max-w-md',
    };
@endphp

<div x-show="{{ $show }}"
     @click.self="{{ $show }} = false"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 backdrop-blur-sm"
     style="display: none;">
    <div @click.stop {{ $attributes->merge(['class' => "bg-gray-800 rounded-lg p-6 {$maxWidthClass} w-full mx-4 shadow-2xl"]) }}>
        @if($title)
            <h2 class="text-xl font-semibold text-gray-100 mb-4">{{ $title }}</h2>
        @endif

        {{ $slot }}
    </div>
</div>
