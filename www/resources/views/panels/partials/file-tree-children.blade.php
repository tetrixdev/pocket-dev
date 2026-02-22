{{-- Dynamically loaded children for file tree --}}
{{-- Used by panel action to lazy-load directory contents --}}
@forelse($children as $item)
    @include('panels.partials.file-tree-item', ['item' => $item, 'depth' => $depth])
@empty
    <div class="text-xs text-gray-600 italic py-1" style="padding-left: {{ ($depth * 16) + 28 }}px">
        Empty directory
    </div>
@endforelse
