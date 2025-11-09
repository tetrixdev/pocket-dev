@extends('layouts.config')

@section('title', isset($command) ? 'Edit Command: ' . $command['name'] : 'Create Command')

@section('content')
<form method="POST" action="{{ isset($command) ? route('config.commands.update', $command['filename']) : route('config.commands.store') }}">
    @csrf
    @if(isset($command))
        @method('PUT')
    @endif

    @if(!isset($command))
        <div class="mb-4">
            <label for="name" class="block text-sm font-medium mb-2">Name (lowercase, hyphens only)</label>
            <input
                type="text"
                id="name"
                name="name"
                value="{{ old('name') }}"
                class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
                pattern="[a-z0-9-]+"
                required
            >
        </div>
    @endif

    <div class="mb-4">
        <label for="allowedTools" class="block text-sm font-medium mb-2">Allowed Tools (comma-separated)</label>
        <input
            type="text"
            id="allowedTools"
            name="allowedTools"
            value="{{ old('allowedTools', $command['allowedTools'] ?? '') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            placeholder="bash, read, write"
        >
    </div>

    <div class="mb-4">
        <label for="argumentHints" class="block text-sm font-medium mb-2">Argument Hints (comma-separated)</label>
        <input
            type="text"
            id="argumentHints"
            name="argumentHints"
            value="{{ old('argumentHints', $command['argumentHints'] ?? '') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            placeholder="file, query"
        >
    </div>

    <div class="mb-6">
        <label for="prompt" class="block text-sm font-medium mb-2">Prompt</label>
        <textarea
            id="prompt"
            name="prompt"
            class="config-editor w-full"
        >{{ old('prompt', $command['prompt'] ?? '') }}</textarea>
    </div>

    <div class="flex gap-3">
        <button
            type="submit"
            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
        >
            {{ isset($command) ? 'Update Command' : 'Create Command' }}
        </button>
        <a
            href="{{ route('config.commands') }}"
            class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium inline-block"
        >
            Cancel
        </a>
    </div>
</form>

@if(isset($command))
    <!-- Separate delete form -->
    <form method="POST" action="{{ route('config.commands.delete', $command['filename']) }}" class="mt-4">
        @csrf
        @method('DELETE')
        <button type="submit" class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium" onclick="return confirm('Are you sure you want to delete this command?')">
            Delete Command
        </button>
    </form>
@endif
@endsection
