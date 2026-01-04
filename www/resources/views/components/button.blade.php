@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'disabled' => false,
    'fullWidth' => false,
])

@php
    $baseClasses = 'rounded-lg font-medium transition-all disabled:cursor-not-allowed disabled:opacity-50';

    $variantClasses = match ($variant) {
        'primary' => 'bg-blue-600 hover:bg-blue-700 text-white',
        'secondary' => 'bg-gray-700 hover:bg-gray-600 text-white',
        'success' => 'bg-green-600 hover:bg-green-700 text-white',
        'danger' => 'bg-red-600 hover:bg-red-700 text-white',
        'purple' => 'bg-purple-600 hover:bg-purple-700 text-white',
        'ghost' => 'bg-transparent hover:bg-gray-700 text-gray-300',
        default => 'bg-blue-600 hover:bg-blue-700 text-white',
    };

    $sizeClasses = match ($size) {
        'sm' => 'px-3 py-2 text-sm',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-6 py-3 text-base',
        default => 'px-4 py-2 text-sm',
    };

    $widthClass = $fullWidth ? 'w-full' : '';

    $classes = "{$baseClasses} {$variantClasses} {$sizeClasses} {$widthClass}";
@endphp

<button
    type="{{ $type }}"
    @if($disabled) disabled @endif
    {{ $attributes->merge(['class' => $classes]) }}
>
    {{ $slot }}
</button>
