@props([
    'type' => 'text',
    'label' => null,
    'hint' => null,
])

@php
    $inputClasses = 'w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg focus:outline-none focus:border-blue-500 text-white placeholder-gray-400';
@endphp

<div>
    @if($label)
        <label class="block text-sm font-medium text-gray-300 mb-2">{{ $label }}</label>
    @endif

    <input
        type="{{ $type }}"
        {{ $attributes->merge(['class' => $inputClasses]) }}
    >

    @if($hint)
        <p class="text-gray-500 text-xs mt-2">{{ $hint }}</p>
    @endif
</div>
