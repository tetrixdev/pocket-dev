@props([
    'name',
    'slug' => null,
    'description' => null,
    'showToggle' => false,
    'enabled' => true,
    'provider' => null,
    'viewUrl' => null,
    'editUrl' => null,
    'deleteUrl' => null,
    'artisanCommand' => null,
])

<div class="px-4 py-3 hover:bg-gray-750 transition-colors border-b border-gray-700 last:border-b-0">
    {{-- Mobile: Stack vertically --}}
    <div class="flex flex-col gap-2 sm:hidden">
        {{-- Row 1: Toggle (if applicable) + Name --}}
        <div class="flex items-center gap-3">
            @if($showToggle)
                <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                    <input
                        type="checkbox"
                        class="sr-only peer"
                        {{ $enabled ? 'checked' : '' }}
                        @change="toggleNativeTool('{{ $provider }}', '{{ $name }}', $event.target.checked)"
                    >
                    <div class="w-9 h-5 bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            @endif
            <div>
                <span class="font-medium {{ $showToggle && !$enabled ? 'text-gray-500' : '' }}">{{ $name }}</span>
                @if($slug)
                    <span class="text-xs text-gray-500 ml-2">{{ $slug }}</span>
                @endif
            </div>
        </div>

        {{-- Row 2: Description --}}
        @if($description)
            <p class="text-sm text-gray-400">{{ $description }}</p>
        @endif

        {{-- Row 3: Artisan command --}}
        @if($artisanCommand)
            <code class="text-xs text-green-400 bg-gray-900 px-2 py-1 rounded inline-block">php artisan {{ $artisanCommand }}</code>
        @endif

        {{-- Row 4: Actions --}}
        <div class="flex gap-3 pt-1">
            @if($viewUrl)
                <a href="{{ $viewUrl }}" class="text-blue-400 hover:text-blue-300 text-sm font-medium">View</a>
            @endif
            @if($editUrl)
                <a href="{{ $editUrl }}" class="text-green-400 hover:text-green-300 text-sm font-medium">Edit</a>
            @endif
            @if($deleteUrl)
                <form method="POST" action="{{ $deleteUrl }}" class="inline" onsubmit="return confirm('Delete this tool?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-red-400 hover:text-red-300 text-sm font-medium">Delete</button>
                </form>
            @endif
        </div>
    </div>

    {{-- Desktop: Horizontal layout --}}
    <div class="hidden sm:flex sm:items-center sm:justify-between sm:gap-4">
        {{-- Left: Toggle + Name + Slug --}}
        <div class="flex items-center gap-3 min-w-0 flex-shrink-0">
            @if($showToggle)
                <label class="relative inline-flex items-center cursor-pointer">
                    <input
                        type="checkbox"
                        class="sr-only peer"
                        {{ $enabled ? 'checked' : '' }}
                        @change="toggleNativeTool('{{ $provider }}', '{{ $name }}', $event.target.checked)"
                    >
                    <div class="w-9 h-5 bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            @endif
            <div>
                <span class="font-medium {{ $showToggle && !$enabled ? 'text-gray-500' : '' }}">{{ $name }}</span>
                @if($slug)
                    <span class="text-xs text-gray-500 ml-2">{{ $slug }}</span>
                @endif
            </div>
        </div>

        {{-- Center: Description --}}
        @if($description)
            <p class="text-sm text-gray-400 flex-1 truncate min-w-0">{{ $description }}</p>
        @endif

        {{-- Right: Actions --}}
        <div class="flex items-center gap-3 flex-shrink-0">
            @if($artisanCommand)
                <code class="text-xs text-green-400 bg-gray-900 px-2 py-1 rounded hidden lg:inline-block">{{ $artisanCommand }}</code>
            @endif
            @if($viewUrl)
                <a href="{{ $viewUrl }}" class="text-blue-400 hover:text-blue-300 text-sm">View</a>
            @endif
            @if($editUrl)
                <a href="{{ $editUrl }}" class="text-green-400 hover:text-green-300 text-sm">Edit</a>
            @endif
            @if($deleteUrl)
                <form method="POST" action="{{ $deleteUrl }}" class="inline" onsubmit="return confirm('Delete this tool?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-red-400 hover:text-red-300 text-sm">Delete</button>
                </form>
            @endif
        </div>
    </div>
</div>

<style>
    .bg-gray-750 {
        background-color: rgb(42, 48, 60);
    }
</style>
