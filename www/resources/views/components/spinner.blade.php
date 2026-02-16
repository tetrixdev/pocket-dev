{{--
    Spinner Component - A smooth, wobble-free loading spinner

    Usage:
        <x-spinner />                    - Default size (inherits font-size via 1em)
        <x-spinner class="w-4 h-4" />    - Explicit size (overrides default)
        <x-spinner class="text-blue-400" /> - Custom color

    This replaces Font Awesome's fa-spin which has a known wobble issue with web fonts.
    The SVG rotates pixel-perfectly because it's mathematically defined, not a font glyph.
--}}
<svg
    {{ $attributes->merge(['class' => 'animate-spin w-[1em] h-[1em]', 'aria-hidden' => 'true']) }}
    viewBox="0 0 24 24"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
>
    {{-- Background circle (faded) --}}
    <circle
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        stroke-width="3"
        opacity="0.25"
    />
    {{-- Spinning arc --}}
    <path
        d="M12 2a10 10 0 0 1 10 10"
        stroke="currentColor"
        stroke-width="3"
        stroke-linecap="round"
    />
</svg>
