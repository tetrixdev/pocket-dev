@extends('layouts.config')

@section('title', 'View Row: ' . $tableName)

@section('content')
<div class="space-y-6" x-data="{ copiedField: null }">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <a href="{{ route('config.memory.browse', $tableName) }}{{ $selectedDatabase ? '?db=' . $selectedDatabase->id : '' }}" class="text-gray-400 hover:text-white transition-colors">
                    <x-icon.chevron-left class="w-5 h-5" />
                </a>
                <h1 class="text-2xl font-bold text-white">{{ $selectedDatabase ? $selectedDatabase->getFullSchemaName() : 'memory' }}.{{ $tableName }}</h1>
            </div>
            <p class="text-gray-400 ml-8 font-mono text-sm">
                id: {{ $rowId }}
                <button
                    type="button"
                    class="ml-2 text-blue-400 hover:text-blue-300"
                    @click="navigator.clipboard.writeText({{ Illuminate\Support\Js::from($rowId) }}); copiedField = 'rowId'; setTimeout(() => copiedField = null, 2000)"
                >
                    <span x-show="copiedField !== 'rowId'">Copy</span>
                    <span x-show="copiedField === 'rowId'" x-cloak class="text-green-400">Copied!</span>
                </button>
            </p>
        </div>
    </div>

    {{-- Fields --}}
    <div class="space-y-4">
        @foreach($fields as $field)
            <div class="bg-gray-800 rounded-lg overflow-hidden">
                {{-- Field Header --}}
                <div class="bg-gray-900 px-4 py-3 border-b border-gray-700">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-medium text-white">{{ $field['name'] }}</span>
                            <span class="text-gray-500 text-sm ml-2">({{ $field['type'] }})</span>
                        </div>
                        @if($field['value']['content'] !== null)
                            <button
                                type="button"
                                class="text-sm text-gray-400 hover:text-white px-2 py-1 rounded hover:bg-gray-700"
                                @click="
                                    navigator.clipboard.writeText({{ Illuminate\Support\Js::from(
                                        is_array($field['value']['content'])
                                            ? implode(', ', $field['value']['content'])
                                            : $field['value']['content']
                                    ) }});
                                    copiedField = '{{ $field['name'] }}';
                                    setTimeout(() => copiedField = null, 2000)
                                "
                            >
                                <span x-show="copiedField !== '{{ $field['name'] }}'">Copy</span>
                                <span x-show="copiedField === '{{ $field['name'] }}'" x-cloak class="text-green-400">Copied!</span>
                            </button>
                        @endif
                    </div>
                    @if($field['description'])
                        <p class="text-gray-500 text-xs mt-1">{{ $field['description'] }}</p>
                    @endif
                </div>

                {{-- Field Value --}}
                <div class="p-4">
                    @if($field['value']['type'] === 'null')
                        <span class="text-gray-500 italic">NULL</span>

                    @elseif($field['value']['type'] === 'json')
                        <pre class="text-purple-300 font-mono text-sm whitespace-pre-wrap break-words overflow-x-auto">{{ $field['value']['content'] }}</pre>

                    @elseif($field['value']['type'] === 'array')
                        @if(empty($field['value']['content']))
                            <span class="text-gray-500 italic">Empty array</span>
                        @else
                            <ul class="list-disc list-inside space-y-1">
                                @foreach($field['value']['content'] as $item)
                                    <li class="text-orange-300 text-sm">{{ $item }}</li>
                                @endforeach
                            </ul>
                        @endif

                    @elseif($field['value']['type'] === 'boolean')
                        <span class="{{ $field['value']['content'] === 'true' ? 'text-green-400' : 'text-red-400' }} font-medium">
                            {{ $field['value']['content'] }}
                        </span>

                    @elseif($field['value']['type'] === 'uuid')
                        <span class="text-blue-400 font-mono text-sm">{{ $field['value']['content'] }}</span>

                    @elseif(in_array($field['value']['type'], ['timestamp without time zone', 'timestamp with time zone', 'timestamptz', 'timestamp']))
                        <span class="text-gray-300 font-mono text-sm">{{ $field['value']['content'] }}</span>

                    @elseif(in_array($field['value']['type'], ['integer', 'bigint', 'smallint', 'int4', 'int8', 'int2']))
                        <span class="text-cyan-400 font-mono">{{ $field['value']['content'] }}</span>

                    @else
                        {{-- Text content with preserved whitespace --}}
                        <div class="text-gray-200 whitespace-pre-wrap break-words">{{ $field['value']['content'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Navigation --}}
    <div class="flex justify-between items-center pt-4 border-t border-gray-700">
        <a href="{{ route('config.memory.browse', $tableName) }}"
           class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white text-sm rounded transition-colors">
            Back to Table
        </a>
    </div>
</div>

<style>
[x-cloak] { display: none !important; }
</style>
@endsection
