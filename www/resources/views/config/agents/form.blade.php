@extends('layouts.config')

@section('title', isset($agent) ? 'Edit Agent: ' . $agent['name'] : 'Create Agent')

@section('content')
<form method="POST" action="{{ isset($agent) ? route('config.agents.update', $agent['filename']) : route('config.agents.store') }}">
    @csrf
    @if(isset($agent))
        @method('PUT')
    @endif

    <div class="mb-4">
        <label for="name" class="block text-sm font-medium mb-2">Name</label>
        <input
            type="text"
            id="name"
            name="name"
            value="{{ old('name', $agent['name'] ?? '') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            required
        >
    </div>

    <div class="mb-4">
        <label for="description" class="block text-sm font-medium mb-2">Description</label>
        <textarea
            id="description"
            name="description"
            rows="3"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
        >{{ old('description', $agent['description'] ?? '') }}</textarea>
    </div>

    <div class="mb-4">
        <label for="tools" class="block text-sm font-medium mb-2">Tools (comma-separated)</label>
        <input
            type="text"
            id="tools"
            name="tools"
            value="{{ old('tools', $agent['tools'] ?? '') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            placeholder="bash, read, write"
        >
    </div>

    <div class="mb-4">
        <label for="model" class="block text-sm font-medium mb-2">Model</label>
        <select
            id="model"
            name="model"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
        >
            <option value="claude-sonnet-4-5-20250929" {{ old('model', $agent['model'] ?? '') == 'claude-sonnet-4-5-20250929' ? 'selected' : '' }}>Claude Sonnet 4.5</option>
            <option value="claude-opus-4-20250514" {{ old('model', $agent['model'] ?? '') == 'claude-opus-4-20250514' ? 'selected' : '' }}>Claude Opus 4</option>
            <option value="claude-3-5-sonnet-20241022" {{ old('model', $agent['model'] ?? '') == 'claude-3-5-sonnet-20241022' ? 'selected' : '' }}>Claude 3.5 Sonnet</option>
        </select>
    </div>

    @if(isset($agent))
        <div class="mb-6">
            <label for="systemPrompt" class="block text-sm font-medium mb-2">System Prompt</label>
            <textarea
                id="systemPrompt"
                name="systemPrompt"
                class="config-editor w-full"
            >{{ old('systemPrompt', $agent['systemPrompt'] ?? '') }}</textarea>
        </div>
    @endif

    <div class="flex gap-3">
        <button
            type="submit"
            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
        >
            {{ isset($agent) ? 'Update Agent' : 'Create Agent' }}
        </button>
        <a
            href="{{ route('config.agents') }}"
            class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium inline-block"
        >
            Cancel
        </a>
    </div>
</form>

@if(isset($agent))
    <!-- Separate delete form -->
    <form method="POST" action="{{ route('config.agents.delete', $agent['filename']) }}" class="mt-4">
        @csrf
        @method('DELETE')
        <button type="submit" class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium" onclick="return confirm('Are you sure you want to delete this agent?')">
            Delete Agent
        </button>
    </form>
@endif
@endsection
