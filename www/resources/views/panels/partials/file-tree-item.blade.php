@php
    $isDir = $item['type'] === 'directory';
    $paddingLeft = ($depth * 16) + 8;
    $isLoaded = $item['loaded'] ?? false;
@endphp

<div class="group" data-path="{{ $item['path'] }}">
    <div class="flex items-center gap-2 py-1 px-2 rounded cursor-pointer hover:bg-gray-800 transition-colors"
         style="padding-left: {{ $paddingLeft }}px"
         @if($isDir)
         @click="toggle('{{ $item['path'] }}', {{ $depth + 1 }})"
         @else
         @click="select('{{ $item['path'] }}')"
         @endif
         :class="{ 'bg-gray-800': selected === '{{ $item['path'] }}' }">

        @if($isDir)
            {{-- Directory --}}
            <i class="fa-solid text-xs w-3 transition-transform"
               :class="isExpanded('{{ $item['path'] }}') ? 'fa-chevron-down' : 'fa-chevron-right text-gray-500'"
               x-show="!isLoading('{{ $item['path'] }}')"></i>
            <i class="fa-solid fa-spinner fa-spin text-xs w-3 text-gray-500"
               x-show="isLoading('{{ $item['path'] }}')" x-cloak></i>
            <i class="fa-solid fa-folder text-yellow-500"></i>
            <span class="text-sm truncate">{{ $item['name'] }}</span>
        @else
            {{-- File --}}
            <span class="w-3"></span>
            @php
                $ext = $item['extension'] ?? '';
                $iconClass = match($ext) {
                    'php' => 'fa-brands fa-php text-purple-400',
                    'js', 'ts' => 'fa-brands fa-js text-yellow-400',
                    'json' => 'fa-solid fa-brackets-curly text-yellow-300',
                    'md' => 'fa-solid fa-file-lines text-blue-300',
                    'blade.php' => 'fa-solid fa-code text-orange-400',
                    'css', 'scss' => 'fa-brands fa-css3 text-blue-400',
                    'html' => 'fa-brands fa-html5 text-orange-500',
                    'vue' => 'fa-brands fa-vuejs text-green-400',
                    'py' => 'fa-brands fa-python text-blue-300',
                    'sh', 'bash' => 'fa-solid fa-terminal text-green-300',
                    'sql' => 'fa-solid fa-database text-blue-400',
                    'env' => 'fa-solid fa-gear text-gray-400',
                    'yml', 'yaml' => 'fa-solid fa-file-code text-pink-400',
                    'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp' => 'fa-solid fa-image text-pink-300',
                    default => 'fa-solid fa-file text-gray-400',
                };
            @endphp
            <i class="{{ $iconClass }}"></i>
            <span class="text-sm truncate">{{ $item['name'] }}</span>
            <span class="text-xs text-gray-500 ml-auto">{{ \App\Panels\FileExplorerPanel::formatSizeStatic($item['size'] ?? 0) }}</span>
        @endif
    </div>

    @if($isDir)
        {{-- Children container - may be empty if not loaded yet --}}
        <div x-show="isExpanded('{{ $item['path'] }}')" x-collapse
             class="children-container" data-children-for="{{ $item['path'] }}">
            @if($isLoaded && !empty($item['children']))
                @foreach($item['children'] as $child)
                    @include('panels.partials.file-tree-item', ['item' => $child, 'depth' => $depth + 1])
                @endforeach
            @endif
        </div>
    @endif
</div>
