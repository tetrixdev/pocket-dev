@extends('layouts.config')

@section('title', $tool->name)

@section('content')
<div x-data="{ showCode: false }" class="space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="{{ route('config.tools') }}" class="text-gray-400 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <div>
                <h1 class="text-2xl font-bold">{{ $tool->name }}</h1>
                <p class="text-gray-400 text-sm">{{ $tool->slug }}</p>
            </div>
        </div>
        @if($tool->isUserTool())
            <div class="flex gap-3">
                <a href="{{ route('config.tools.edit', $tool->slug) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium">
                    Edit
                </a>
                <form method="POST" action="{{ route('config.tools.delete', $tool->slug) }}" onsubmit="return confirm('Delete this tool?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium">
                        Delete
                    </button>
                </form>
            </div>
        @endif
    </div>

    {{-- Tool Info --}}
    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
        <div class="divide-y divide-gray-700">
            {{-- Source Badge --}}
            <div class="px-4 py-3 flex items-center justify-between">
                <span class="text-gray-400">Source</span>
                <span class="px-2 py-1 rounded text-sm font-medium
                    {{ $tool->isPocketdev() ? 'bg-purple-900 text-purple-300' : 'bg-green-900 text-green-300' }}">
                    {{ $tool->isPocketdev() ? 'PocketDev' : 'Custom' }}
                </span>
            </div>

            {{-- Category --}}
            <div class="px-4 py-3 flex items-center justify-between">
                <span class="text-gray-400">Category</span>
                <span class="text-white">{{ ucfirst(str_replace('_', ' ', $tool->category ?? 'None')) }}</span>
            </div>

            {{-- Capability --}}
            @if($tool->capability)
                <div class="px-4 py-3 flex items-center justify-between">
                    <span class="text-gray-400">Capability</span>
                    <span class="text-white">{{ $tool->capability }}</span>
                </div>
            @endif

            {{-- Description --}}
            <div class="px-4 py-3">
                <span class="text-gray-400 block mb-2">Description</span>
                <p class="text-white">{{ $tool->description }}</p>
            </div>

            {{-- Provider Compatibility --}}
            <div class="px-4 py-3">
                <span class="text-gray-400 block mb-2">Provider Compatibility</span>
                <div class="flex gap-3">
                    @php
                        $providers = [
                            'claude_code' => 'Claude Code',
                            'anthropic' => 'Anthropic',
                            'openai' => 'OpenAI',
                            'codex' => 'Codex',
                        ];
                    @endphp
                    @foreach($providers as $key => $label)
                        @if($tool->isAvailableFor($key))
                            <span class="flex items-center gap-1 text-green-400">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                                {{ $label }}
                            </span>
                        @else
                            <span class="flex items-center gap-1 text-red-400">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                                {{ $label }}
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- Native Equivalent --}}
            @if($tool->native_equivalent)
                <div class="px-4 py-3 flex items-center justify-between">
                    <span class="text-gray-400">Native Equivalent</span>
                    <span class="text-blue-400">{{ $tool->native_equivalent }}</span>
                </div>
            @endif

            {{-- Artisan Command --}}
            @if($tool->getArtisanCommand())
                <div class="px-4 py-3 flex items-center justify-between">
                    <span class="text-gray-400">Artisan Command</span>
                    <code class="text-green-400 bg-gray-900 px-2 py-1 rounded text-sm">php artisan {{ $tool->getArtisanCommand() }}</code>
                </div>
            @endif
        </div>
    </div>

    {{-- System Prompt --}}
    @if($tool->system_prompt)
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <div class="px-4 py-3 bg-gray-750 border-b border-gray-700">
                <span class="font-semibold">System Prompt</span>
            </div>
            <div class="p-4">
                <pre class="text-sm text-gray-300 whitespace-pre-wrap font-mono bg-gray-900 p-4 rounded overflow-x-auto">{{ $tool->system_prompt }}</pre>
            </div>
        </div>
    @endif

    {{-- Input Schema --}}
    @if($tool->input_schema)
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <div class="px-4 py-3 bg-gray-750 border-b border-gray-700">
                <span class="font-semibold">Input Schema</span>
            </div>
            <div class="p-4">
                <pre class="text-sm text-gray-300 whitespace-pre-wrap font-mono bg-gray-900 p-4 rounded overflow-x-auto">{{ json_encode($tool->input_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>
    @endif

    {{-- Script (expandable for custom tools) --}}
    @if($tool->script)
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <button
                @click="showCode = !showCode"
                class="w-full px-4 py-3 bg-gray-750 border-b border-gray-700 flex items-center justify-between hover:bg-gray-700"
            >
                <span class="font-semibold">Script</span>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-400">{{ $tool->isUserTool() ? 'Edit via AI chat' : 'Read-only' }}</span>
                    <svg x-show="!showCode" class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                    <svg x-show="showCode" class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                    </svg>
                </div>
            </button>
            <div x-show="showCode" x-collapse>
                <div class="p-4">
                    <pre class="text-sm text-gray-300 whitespace-pre-wrap font-mono bg-gray-900 p-4 rounded overflow-x-auto">{{ $tool->script }}</pre>
                </div>
            </div>
        </div>
    @endif

</div>

<style>
    .bg-gray-750 {
        background-color: rgb(42, 48, 60);
    }
</style>
@endsection
