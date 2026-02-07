@extends('layouts.config')

@section('title', $tool->name)

@section('content')
<div x-data="{ showCode: false }" class="space-y-6">

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
            <a href="{{ route('config.tools') }}" class="text-gray-400 hover:text-white flex-shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <div class="min-w-0">
                <h1 class="text-xl sm:text-2xl font-bold truncate">{{ $tool->name }}</h1>
                <p class="text-gray-400 text-sm truncate">{{ $tool->slug }}</p>
            </div>
        </div>
        @if($tool->isUserTool())
            <div class="flex gap-3 pl-10 sm:pl-0">
                <a href="{{ route('config.tools.edit', $tool->slug) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium text-sm">
                    Edit
                </a>
                <form method="POST" action="{{ route('config.tools.delete', $tool->slug) }}" onsubmit="return confirm('Delete this tool?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium text-sm">
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
            <div class="px-4 py-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <span class="text-gray-400 text-sm">Source</span>
                <span class="px-2 py-1 rounded text-sm font-medium w-fit
                    {{ $tool->isPocketdev() ? 'bg-purple-900 text-purple-300' : 'bg-green-900 text-green-300' }}">
                    {{ $tool->isPocketdev() ? 'PocketDev' : 'Custom' }}
                </span>
            </div>

            {{-- Category --}}
            <div class="px-4 py-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <span class="text-gray-400 text-sm">Category</span>
                <span class="text-white">{{ ucfirst(str_replace('_', ' ', $tool->category ?? 'None')) }}</span>
            </div>

            {{-- Description --}}
            <div class="px-4 py-3">
                <span class="text-gray-400 text-sm block mb-2">Description</span>
                <p class="text-white text-sm sm:text-base">{{ $tool->description }}</p>
            </div>

            {{-- Artisan Command --}}
            @if($tool->getArtisanCommand())
                <div class="px-4 py-3">
                    <span class="text-gray-400 text-sm block mb-2 sm:mb-0 sm:inline">Artisan Command</span>
                    <div class="sm:float-right">
                        <code class="text-green-400 bg-gray-900 px-2 py-1 rounded text-xs sm:text-sm block sm:inline-block overflow-x-auto">pd {{ $tool->getArtisanCommand() }}</code>
                    </div>
                    <div class="clear-both"></div>
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
            <div class="p-3 sm:p-4">
                <pre class="text-xs sm:text-sm text-gray-300 whitespace-pre-wrap font-mono bg-gray-900 p-3 sm:p-4 rounded overflow-x-auto max-h-96">{{ $tool->system_prompt }}</pre>
            </div>
        </div>
    @endif

    {{-- Input Schema --}}
    @if($tool->input_schema)
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <div class="px-4 py-3 bg-gray-750 border-b border-gray-700">
                <span class="font-semibold">Input Schema</span>
            </div>
            <div class="p-3 sm:p-4">
                <pre class="text-xs sm:text-sm text-gray-300 whitespace-pre-wrap font-mono bg-gray-900 p-3 sm:p-4 rounded overflow-x-auto max-h-96">{{ json_encode($tool->input_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>
    @endif

    {{-- Script (expandable) --}}
    @if($tool->script)
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <button
                @click="showCode = !showCode"
                class="w-full px-4 py-3 bg-gray-750 border-b border-gray-700 flex items-center justify-between hover:bg-gray-700"
            >
                <span class="font-semibold">Script</span>
                <div class="flex items-center gap-2">
                    <span class="text-xs sm:text-sm text-gray-400 hidden sm:inline">{{ $tool->isUserTool() ? 'Edit via AI chat' : 'Read-only' }}</span>
                    <svg
                        :class="{ 'rotate-180': showCode }"
                        class="w-5 h-5 text-gray-400 transition-transform"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>
            </button>
            <div x-show="showCode" x-collapse>
                <div class="p-3 sm:p-4">
                    <p class="text-xs text-gray-400 mb-2 sm:hidden">{{ $tool->isUserTool() ? 'Edit via AI chat' : 'Read-only' }}</p>
                    <pre class="text-xs sm:text-sm text-gray-300 whitespace-pre-wrap font-mono bg-gray-900 p-3 sm:p-4 rounded overflow-x-auto max-h-96">{{ $tool->script }}</pre>
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
