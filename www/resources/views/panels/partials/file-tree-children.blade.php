{{-- Dynamically loaded children for file tree --}}
{{-- Used by panel action to lazy-load directory contents --}}
@foreach($children as $item)
    @include('panels.partials.file-tree-item', ['item' => $item, 'depth' => $depth])
@endforeach
