@props([
    'type' => 'text',
    'label' => null,
    'hint' => null,
])

@php
    $inputClasses = 'w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg focus:outline-none focus:border-blue-500 text-white placeholder-gray-400';
    $id = $attributes->get('id', 'input-' . uniqid());
    $hintId = $hint ? $id . '-hint' : null;
@endphp

<div>
    @if($label)
        <label for="{{ $id }}" class="block text-sm font-medium text-gray-300 mb-2">{{ $label }}</label>
    @endif

    <input
        type="{{ $type }}"
        id="{{ $id }}"
        @if($hintId) aria-describedby="{{ $hintId }}" @endif
        {{ $attributes->merge(['class' => $inputClasses]) }}
    >

    @if($hint)
        <p id="{{ $hintId }}" class="text-gray-500 text-xs mt-2">{{ $hint }}</p>
    @endif
</div>
