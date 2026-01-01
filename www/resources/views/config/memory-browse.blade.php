@extends('layouts.config')

@section('title', 'Browse: ' . $tableName)

@section('content')
<div class="space-y-6" x-data="tableBrowser()">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <a href="{{ route('config.memory') }}" class="text-gray-400 hover:text-white transition-colors">
                    <x-icon.chevron-left class="w-5 h-5" />
                </a>
                <h1 class="text-2xl font-bold text-white">memory.{{ $tableName }}</h1>
                <span class="text-sm text-gray-500 bg-gray-800 px-2 py-1 rounded">
                    {{ number_format($totalRows) }} rows
                </span>
            </div>
            @if($tableInfo['description'])
                <p class="text-gray-400 ml-8">{{ Str::limit($tableInfo['description'], 200) }}</p>
            @endif
        </div>
    </div>

    {{-- Controls --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            {{-- Per page selector --}}
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-400">Show:</label>
                <select
                    class="bg-gray-800 border border-gray-700 text-white text-sm rounded px-2 py-1 focus:border-blue-500 focus:outline-none"
                    onchange="window.location.href = updateQueryParam('per_page', this.value)"
                >
                    @foreach([10, 25, 50, 100] as $option)
                        <option value="{{ $option }}" {{ $perPage == $option ? 'selected' : '' }}>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Pagination info --}}
        <div class="text-sm text-gray-400">
            @if($totalRows > 0)
                Showing {{ ($currentPage - 1) * $perPage + 1 }} - {{ min($currentPage * $perPage, $totalRows) }} of {{ number_format($totalRows) }}
            @else
                No data
            @endif
        </div>
    </div>

    {{-- Table --}}
    @if(count($rows) > 0)
        <div class="bg-gray-800 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 text-gray-300">
                        <tr>
                            <th class="px-3 py-3 text-left font-medium w-16">
                                <span class="text-gray-500 text-xs">Actions</span>
                            </th>
                            @foreach($columns as $col)
                                @php
                                    $isSorted = $sortColumn === $col['name'];
                                    $nextDir = $isSorted && $sortDirection === 'asc' ? 'desc' : 'asc';
                                    $sortUrl = request()->fullUrlWithQuery(['sort' => $col['name'], 'dir' => $nextDir, 'page' => 1]);
                                @endphp
                                <th class="px-4 py-3 text-left font-medium whitespace-nowrap">
                                    <a href="{{ $sortUrl }}" class="flex items-center gap-1 hover:text-white transition-colors">
                                        <span>{{ $col['name'] }}</span>
                                        @if($isSorted)
                                            <span class="text-blue-400">
                                                @if($sortDirection === 'asc')
                                                    &#9650;
                                                @else
                                                    &#9660;
                                                @endif
                                            </span>
                                        @endif
                                    </a>
                                    <span class="text-xs text-gray-500 font-normal block">{{ $col['type'] }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        @foreach($rows as $rowIndex => $row)
                            @php $rowId = $row['id']['full'] ?? null; @endphp
                            <tr class="hover:bg-gray-750 transition-colors">
                                <td class="px-3 py-3 align-top">
                                    @if($rowId)
                                        <a href="{{ route('config.memory.show', [$tableName, $rowId]) }}"
                                           class="text-blue-400 hover:text-blue-300 text-xs font-medium">
                                            View
                                        </a>
                                    @endif
                                </td>
                                @foreach($columns as $col)
                                    @php $cell = $row[$col['name']] ?? ['display' => '', 'full' => '', 'type' => 'text']; @endphp
                                    <td class="px-4 py-3 align-top">
                                        @if($cell['type'] === 'null')
                                            <span class="text-gray-500 italic">NULL</span>
                                        @elseif($cell['type'] === 'uuid')
                                            <button
                                                type="button"
                                                class="text-blue-400 hover:text-blue-300 font-mono text-xs cursor-pointer"
                                                @click="copyToClipboard({{ Illuminate\Support\Js::from($cell['full']) }})"
                                                title="Click to copy: {{ $cell['full'] }}"
                                            >
                                                {{ $cell['display'] }}
                                            </button>
                                        @elseif($cell['type'] === 'boolean')
                                            <span class="{{ $cell['display'] === 'true' ? 'text-green-400' : 'text-red-400' }}">
                                                {{ $cell['display'] }}
                                            </span>
                                        @elseif($cell['type'] === 'json' && $cell['display'] !== $cell['full'])
                                            <button
                                                type="button"
                                                class="text-left text-purple-400 hover:text-purple-300 font-mono text-xs max-w-xs truncate block"
                                                @click="showModal('{{ $col['name'] }}', {{ Illuminate\Support\Js::from($cell['full']) }}, 'json')"
                                            >
                                                {{ $cell['display'] }}
                                            </button>
                                        @elseif($cell['type'] === 'array')
                                            <button
                                                type="button"
                                                class="text-orange-400 hover:text-orange-300 text-xs"
                                                @click="showModal('{{ $col['name'] }}', {{ Illuminate\Support\Js::from($cell['full']) }}, 'array')"
                                            >
                                                [{{ $cell['count'] ?? '?' }} items]
                                            </button>
                                        @elseif($cell['type'] === 'timestamp')
                                            <span class="text-gray-300 text-xs whitespace-nowrap">{{ $cell['display'] }}</span>
                                        @elseif($cell['type'] === 'geo')
                                            <span class="text-cyan-400 text-xs">[geo]</span>
                                        @elseif($cell['display'] !== $cell['full'])
                                            <button
                                                type="button"
                                                class="text-left text-gray-300 hover:text-white max-w-md"
                                                @click="showModal('{{ $col['name'] }}', {{ Illuminate\Support\Js::from($cell['full']) }}, 'text')"
                                            >
                                                {{ $cell['display'] }}
                                            </button>
                                        @else
                                            <span class="text-gray-300 break-words max-w-md block">{{ $cell['display'] }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Pagination --}}
        @if($totalPages > 1)
            <div class="flex items-center justify-center gap-2">
                {{-- Previous --}}
                @if($currentPage > 1)
                    <a href="{{ request()->fullUrlWithQuery(['page' => $currentPage - 1]) }}"
                       class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-white text-sm rounded transition-colors">
                        Previous
                    </a>
                @endif

                {{-- Page numbers --}}
                @php
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                @endphp

                @if($startPage > 1)
                    <a href="{{ request()->fullUrlWithQuery(['page' => 1]) }}"
                       class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-white text-sm rounded transition-colors">1</a>
                    @if($startPage > 2)
                        <span class="text-gray-500">...</span>
                    @endif
                @endif

                @for($p = $startPage; $p <= $endPage; $p++)
                    <a href="{{ request()->fullUrlWithQuery(['page' => $p]) }}"
                       class="px-3 py-1.5 text-sm rounded transition-colors {{ $p === $currentPage ? 'bg-blue-600 text-white' : 'bg-gray-800 hover:bg-gray-700 text-white' }}">
                        {{ $p }}
                    </a>
                @endfor

                @if($endPage < $totalPages)
                    @if($endPage < $totalPages - 1)
                        <span class="text-gray-500">...</span>
                    @endif
                    <a href="{{ request()->fullUrlWithQuery(['page' => $totalPages]) }}"
                       class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-white text-sm rounded transition-colors">{{ $totalPages }}</a>
                @endif

                {{-- Next --}}
                @if($currentPage < $totalPages)
                    <a href="{{ request()->fullUrlWithQuery(['page' => $currentPage + 1]) }}"
                       class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-white text-sm rounded transition-colors">
                        Next
                    </a>
                @endif
            </div>
        @endif
    @else
        <div class="bg-gray-800 rounded-lg p-8 text-center">
            <p class="text-gray-400">This table is empty.</p>
        </div>
    @endif

    {{-- Value Modal --}}
    <div
        x-show="modalOpen"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        @keydown.escape.window="modalOpen = false"
    >
        <div class="fixed inset-0 bg-black/70" @click="modalOpen = false"></div>
        <div class="relative bg-gray-800 rounded-lg shadow-xl max-w-3xl w-full max-h-[80vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-700">
                <h3 class="text-lg font-medium text-white" x-text="modalTitle"></h3>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="text-gray-400 hover:text-white text-sm px-2 py-1 rounded hover:bg-gray-700"
                        @click="copyModalContent()"
                    >
                        Copy
                    </button>
                    <button
                        type="button"
                        class="text-gray-400 hover:text-white"
                        @click="modalOpen = false"
                    >
                        <x-icon.x class="w-5 h-5" />
                    </button>
                </div>
            </div>
            <div class="p-4 overflow-auto flex-1">
                <pre
                    class="text-sm whitespace-pre-wrap break-words"
                    :class="modalType === 'json' ? 'text-purple-300 font-mono' : (modalType === 'array' ? 'text-orange-300' : 'text-gray-300')"
                    x-text="modalContent"
                ></pre>
            </div>
        </div>
    </div>

    {{-- Toast notification --}}
    <div
        x-show="toastVisible"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2"
        class="fixed bottom-4 right-4 bg-green-600 text-white px-4 py-2 rounded shadow-lg text-sm"
    >
        Copied to clipboard!
    </div>
</div>
@endsection

@push('scripts')
<script>
function updateQueryParam(key, value) {
    const url = new URL(window.location.href);
    url.searchParams.set(key, value);
    url.searchParams.set('page', '1'); // Reset to page 1 when changing per_page
    return url.toString();
}

function tableBrowser() {
    return {
        modalOpen: false,
        modalTitle: '',
        modalContent: '',
        modalType: 'text',
        toastVisible: false,

        showModal(title, content, type) {
            this.modalTitle = title;
            this.modalContent = content;
            this.modalType = type;
            this.modalOpen = true;
        },

        copyToClipboard(text) {
            navigator.clipboard.writeText(text);
            this.showToast();
        },

        copyModalContent() {
            navigator.clipboard.writeText(this.modalContent);
            this.showToast();
        },

        showToast() {
            this.toastVisible = true;
            setTimeout(() => {
                this.toastVisible = false;
            }, 2000);
        }
    };
}
</script>

<style>
[x-cloak] { display: none !important; }
.bg-gray-750 { background-color: #2d3748; }
</style>
@endpush
