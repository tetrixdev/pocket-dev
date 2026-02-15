@props([
    'show' => 'showModal',
    'maxWidth' => 'md',
    'title' => null,
    'variant' => 'default', // 'default' or 'fullscreen'
    'closeOnEscape' => true,
    'onClose' => null, // Custom close handler (e.g., 'closeMyModal()')
    'history' => true, // Enable browser back button support (auto-registers with modalHistory store)
])

@php
    $isFullscreen = $variant === 'fullscreen';

    $maxWidthClass = match ($maxWidth) {
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        '4xl' => 'max-w-4xl',
        default => 'max-w-md',
    };

    // Close action - use custom handler if provided, otherwise set show variable to false
    $closeAction = $onClose ?? "{$show} = false";

    // Generate a unique history ID from the show prop
    // e.g., "showAgentSelector" -> "show-agent-selector"
    $historyId = $history ? Str::slug(Str::snake($show)) : null;
@endphp

<div x-show="{{ $show }}"
     x-data="{ mousedownOnBackdrop: false }"
     @if($historyId)
     x-effect="
         if ({{ $show }}) {
             $store.modalHistory?.push('{{ $historyId }}', () => { {{ $closeAction }} });
         } else {
             $store.modalHistory?.remove('{{ $historyId }}');
         }
     "
     @endif
     @if($closeOnEscape)
     @keydown.escape.window="if ({{ $show }}) {{ $closeAction }}"
     @endif
     @mouseup.window="mousedownOnBackdrop = false"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 flex items-center justify-center {{ $isFullscreen ? '' : 'bg-black bg-opacity-60 backdrop-blur-sm' }}"
     @if(!$isFullscreen)
     @mousedown.self="mousedownOnBackdrop = true"
     @mouseup.self="if (mousedownOnBackdrop) {{ $closeAction }}"
     @endif
     style="display: none;">

    @if($isFullscreen)
    {{-- Fullscreen variant: separate backdrop div for better mobile handling --}}
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"
         @mousedown="mousedownOnBackdrop = true"
         @mouseup="if (mousedownOnBackdrop) {{ $closeAction }}"></div>

    {{-- Fullscreen modal content - responsive sizing --}}
    <div @click.stop
         {{ $attributes->merge(['class' => "relative w-full h-full md:h-[85vh] md:max-h-[85vh] md:{$maxWidthClass} md:mx-4 md:rounded-lg bg-gray-900 flex flex-col overflow-hidden shadow-2xl"]) }}>
        @if(isset($header))
            {{ $header }}
        @elseif($title)
            {{-- Default header for fullscreen --}}
            <div class="flex items-center justify-between px-4 py-3 bg-gray-800 border-b border-gray-700 shrink-0">
                <h2 class="text-sm font-medium text-gray-100">{{ $title }}</h2>
                <button @click="{{ $closeAction }}"
                        class="p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                        title="Close (Esc)">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        @endif

        {{-- Content area --}}
        <div class="flex-1 overflow-auto">
            {{ $slot }}
        </div>
    </div>
    @else
    {{-- Default variant: centered modal --}}
    <div @click.stop {{ $attributes->merge(['class' => "bg-gray-800 rounded-lg p-6 {$maxWidthClass} w-full mx-4 shadow-2xl"]) }}>
        @if($title)
            <h2 class="text-xl font-semibold text-gray-100 mb-4">{{ $title }}</h2>
        @endif

        {{ $slot }}
    </div>
    @endif
</div>
