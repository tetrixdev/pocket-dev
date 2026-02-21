@php
    $isDir = $item['type'] === 'directory';
    $paddingLeft = ($depth * 16) + 8;
    $isLoaded = $item['loaded'] ?? false;
    $isHidden = $item['isHidden'] ?? false;
    // Use Js::from() to safely escape path for JS contexts
    $jsPath = \Illuminate\Support\Js::from($item['path']);
    $jsName = \Illuminate\Support\Js::from($item['name']);

    // Pre-compute metadata values as JS literals for the x-text expression
    $jsPerms = \Illuminate\Support\Js::from($item['permissions'] ?? '');
    $jsOwner = \Illuminate\Support\Js::from(($item['owner'] ?? '') . ':' . ($item['group'] ?? ''));
    $jsMtime = \Illuminate\Support\Js::from($item['mtimeFormatted'] ?? '');
    $jsSize = \Illuminate\Support\Js::from(\App\Panels\FileExplorerPanel::formatSizeStatic($item['size'] ?? 0));
@endphp

<div class="group" data-path="{{ $item['path'] }}">
    <div class="flex items-center gap-2 py-1 px-2 rounded cursor-pointer hover:bg-gray-800 transition-colors"
         style="padding-left: {{ $paddingLeft }}px"
         @if($isDir)
         @click="toggle({{ $jsPath }}, {{ $depth + 1 }})"
         @else
         @click="openFile({{ $jsPath }}, {{ $jsName }})"
         @endif
         :class="{ 'bg-gray-800': selected === {{ $jsPath }} }">

        @if($isDir)
            {{-- Directory --}}
            <i class="fa-solid text-xs w-3 transition-transform shrink-0"
               :class="isExpanded({{ $jsPath }}) ? 'fa-chevron-down' : 'fa-chevron-right text-gray-500'"
               x-show="!isLoading({{ $jsPath }})"></i>
            <x-spinner class="!w-3 !h-3 text-gray-500 shrink-0"
               x-show="isLoading({{ $jsPath }})" x-cloak />
            <i class="fa-solid fa-folder shrink-0 {{ $isHidden ? 'text-yellow-500/40' : 'text-yellow-500' }}"></i>
            <span class="text-sm whitespace-nowrap {{ $isHidden ? 'text-gray-500' : '' }}">{{ $item['name'] }}</span>

            {{-- Metadata (pipe-separated, only visible columns) --}}
            <span class="shrink-0 text-[11px] whitespace-nowrap {{ $isHidden ? 'text-gray-700' : 'text-gray-600' }}"
                  x-text="[settings.showPermissions ? {{ $jsPerms }} : '', settings.showOwner ? {{ $jsOwner }} : '', settings.showModified ? {{ $jsMtime }} : '', settings.showSize ? {{ $jsSize }} : ''].filter(Boolean).join(' | ')"></span>
        @else
            {{-- File --}}
            <span class="w-3 shrink-0"></span>
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
                    $ext === 'lock' => 'fa-solid fa-lock text-gray-500',
                    str_starts_with($name, '.') && $ext === '' => 'fa-solid fa-gear text-gray-400',
                    default => 'fa-solid fa-file text-gray-400',
                };

                // Dim icons for hidden files
                if ($isHidden) {
                    $iconClass = preg_replace('/text-(\w+)-(\d+)/', 'text-$1-$2/50', $iconClass);
                }
            @endphp
            <i class="{{ $iconClass }} shrink-0"></i>
            <span class="text-sm whitespace-nowrap {{ $isHidden ? 'text-gray-500' : '' }}">{{ $item['name'] }}</span>

            {{-- Metadata (pipe-separated, only visible columns) --}}
            <span class="shrink-0 text-[11px] whitespace-nowrap {{ $isHidden ? 'text-gray-700' : 'text-gray-600' }}"
                  x-text="[settings.showPermissions ? {{ $jsPerms }} : '', settings.showOwner ? {{ $jsOwner }} : '', settings.showModified ? {{ $jsMtime }} : '', settings.showSize ? {{ $jsSize }} : ''].filter(Boolean).join(' | ')"></span>
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
