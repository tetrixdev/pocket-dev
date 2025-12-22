@extends('layouts.config')

@section('title', isset($tool) ? 'Edit ' . $tool->name : 'Create Tool')

@section('content')
<div class="max-w-2xl">

    {{-- Header --}}
    <div class="flex items-center gap-4 mb-6">
        <a href="{{ route('config.tools') }}" class="text-gray-400 hover:text-white">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
        </a>
        <h1 class="text-2xl font-bold">{{ isset($tool) ? 'Edit Tool' : 'Create Tool' }}</h1>
    </div>

    <form
        method="POST"
        action="{{ isset($tool) ? route('config.tools.update', $tool->slug) : route('config.tools.store') }}"
        class="space-y-6"
    >
        @csrf
        @if(isset($tool))
            @method('PUT')
        @endif

        {{-- Slug (only for create) --}}
        @if(!isset($tool))
            <div>
                <label for="slug" class="block text-sm font-medium mb-2">
                    Slug <span class="text-red-400">*</span>
                </label>
                <input
                    type="text"
                    id="slug"
                    name="slug"
                    value="{{ old('slug') }}"
                    class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="my-custom-tool"
                    pattern="[a-z0-9-]+"
                    required
                >
                <p class="mt-1 text-sm text-gray-400">Lowercase letters, numbers, and hyphens only. Cannot be changed after creation.</p>
                @error('slug')
                    <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>
        @else
            <div>
                <label class="block text-sm font-medium mb-2">Slug</label>
                <div class="px-4 py-2 bg-gray-900 border border-gray-700 rounded text-gray-400">
                    {{ $tool->slug }}
                </div>
                <p class="mt-1 text-sm text-gray-500">Slug cannot be changed after creation.</p>
            </div>
        @endif

        {{-- Name --}}
        <div>
            <label for="name" class="block text-sm font-medium mb-2">
                Name <span class="text-red-400">*</span>
            </label>
            <input
                type="text"
                id="name"
                name="name"
                value="{{ old('name', $tool->name ?? '') }}"
                class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="My Custom Tool"
                required
            >
            @error('name')
                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- Description --}}
        <div>
            <label for="description" class="block text-sm font-medium mb-2">
                Description <span class="text-red-400">*</span>
            </label>
            <textarea
                id="description"
                name="description"
                rows="3"
                class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="What does this tool do?"
                required
            >{{ old('description', $tool->description ?? '') }}</textarea>
            @error('description')
                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- Category --}}
        <div>
            <label for="category" class="block text-sm font-medium mb-2">Category</label>
            <input
                type="text"
                id="category"
                name="category"
                value="{{ old('category', $tool->category ?? '') }}"
                list="category-suggestions"
                class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="custom"
            >
            <datalist id="category-suggestions">
                @foreach($categories as $cat)
                    <option value="{{ $cat }}">
                @endforeach
                <option value="custom">
                <option value="deployment">
                <option value="testing">
                <option value="utilities">
            </datalist>
            <p class="mt-1 text-sm text-gray-400">Used for grouping tools. Leave empty for 'custom'.</p>
            @error('category')
                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- Input Schema --}}
        <div>
            <label for="input_schema" class="block text-sm font-medium mb-2">Input Schema (JSON)</label>
            <textarea
                id="input_schema"
                name="input_schema"
                rows="8"
                class="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                placeholder='{
  "type": "object",
  "properties": {
    "param1": {
      "type": "string",
      "description": "First parameter"
    }
  },
  "required": ["param1"]
}'
            >{{ old('input_schema', isset($tool) && $tool->input_schema ? json_encode($tool->input_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
            <p class="mt-1 text-sm text-gray-400">JSON Schema defining the tool's input parameters. Optional.</p>
            @error('input_schema')
                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- Script Info (edit only, read-only) --}}
        @if(isset($tool) && $tool->script)
            <div class="bg-gray-800 rounded-lg border border-gray-700 p-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium">Script</span>
                    <span class="text-sm text-yellow-400">Edit via AI chat</span>
                </div>
                <p class="text-sm text-gray-400">
                    To modify the tool's script, ask the AI in chat to update it.
                    The script is stored at a secure location and can only be modified programmatically.
                </p>
            </div>
        @elseif(!isset($tool))
            <div class="bg-gray-800 rounded-lg border border-gray-700 p-4">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="font-medium">About Tool Scripts</span>
                </div>
                <p class="text-sm text-gray-400">
                    After creating the tool, you can ask the AI to add a script for it.
                    Scripts are bash commands that run when the tool is invoked.
                </p>
            </div>
        @endif

        {{-- Actions --}}
        <div class="flex gap-4">
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium">
                {{ isset($tool) ? 'Save Changes' : 'Create Tool' }}
            </button>
            <a href="{{ route('config.tools') }}" class="px-6 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded font-medium">
                Cancel
            </a>
        </div>

    </form>

</div>
@endsection
