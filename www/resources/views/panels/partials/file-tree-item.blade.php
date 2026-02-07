@php
    $isDir = $item['type'] === 'directory';
    $paddingLeft = ($depth * 16) + 8;
    $isLoaded = $item['loaded'] ?? false;
    // Use Js::from() to safely escape path for JS contexts
    $jsPath = \Illuminate\Support\Js::from($item['path']);
@endphp

<div class="group" data-path="{{ $item['path'] }}">
    <div class="flex items-center gap-2 py-1 px-2 rounded cursor-pointer hover:bg-gray-800 transition-colors"
         style="padding-left: {{ $paddingLeft }}px"
         @if($isDir)
         @click="toggle({{ $jsPath }}, {{ $depth + 1 }})"
         @else
         @click="select({{ $jsPath }})"
         @endif
         :class="{ 'bg-gray-800': selected === {{ $jsPath }} }">

        @if($isDir)
            {{-- Directory --}}
            <i class="fa-solid text-xs w-3 transition-transform"
               :class="isExpanded({{ $jsPath }}) ? 'fa-chevron-down' : 'fa-chevron-right text-gray-500'"
               x-show="!isLoading({{ $jsPath }})"></i>
            <i class="fa-solid fa-spinner fa-spin text-xs w-3 text-gray-500"
               x-show="isLoading({{ $jsPath }})" x-cloak></i>
            <i class="fa-solid fa-folder text-yellow-500"></i>
            <span class="text-sm truncate">{{ $item['name'] }}</span>
        @else
            {{-- File --}}
            <span class="w-3"></span>
            @php
                $ext = $item['extension'] ?? '';
                $name = $item['name'] ?? '';
                // Check for .blade.php first (pathinfo returns 'php' for these)
                $iconClass = match(true) {
                    str_ends_with($name, '.blade.php') => 'fa-solid fa-code text-orange-400',
                    $ext === 'php' => 'fa-brands fa-php text-purple-400',
                    in_array($ext, ['js', 'ts']) => 'fa-brands fa-js text-yellow-400',
                    $ext === 'json' => 'fa-solid fa-brackets-curly text-yellow-300',
                    $ext === 'md' => 'fa-solid fa-file-lines text-blue-300',
                    in_array($ext, ['css', 'scss']) => 'fa-brands fa-css3 text-blue-400',
                    $ext === 'html' => 'fa-brands fa-html5 text-orange-500',
                    $ext === 'vue' => 'fa-brands fa-vuejs text-green-400',
                    $ext === 'py' => 'fa-brands fa-python text-blue-300',
                    in_array($ext, ['sh', 'bash']) => 'fa-solid fa-terminal text-green-300',
                    $ext === 'sql' => 'fa-solid fa-database text-blue-400',
                    $ext === 'env' => 'fa-solid fa-gear text-gray-400',
                    in_array($ext, ['yml', 'yaml']) => 'fa-solid fa-file-code text-pink-400',
                    in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp']) => 'fa-solid fa-image text-pink-300',
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
        <div x-show="isExpanded({{ $jsPath }})" x-collapse
             class="children-container" data-children-for="{{ $item['path'] }}">
            @if($isLoaded && !empty($item['children']))
                @foreach($item['children'] as $child)
                    @include('panels.partials.file-tree-item', ['item' => $child, 'depth' => $depth + 1])
                @endforeach
            @endif
        </div>
    @endif
</div>
