@extends('layouts.config')

@section('title', isset($agent) ? 'Edit Agent: ' . $agent['name'] : 'Create Agent')

@section('content')
<form method="POST" action="{{ isset($agent) ? route('config.agents.update', $agent['filename']) : route('config.agents.store') }}">
    @csrf
    @if(isset($agent))
        @method('PUT')
    @endif

    <div class="mb-4">
        <label for="name" class="block text-sm font-medium mb-2">Name <span class="text-red-400 font-bold">*</span></label>
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
        <label for="description" class="block text-sm font-medium mb-2">Description <span class="text-red-400 font-bold">*</span></label>
        <textarea
            id="description"
            name="description"
            rows="3"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            required
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
            <option value="claude-haiku-4-5-20251001" {{ old('model', $agent['model'] ?? '') == 'claude-haiku-4-5-20251001' ? 'selected' : '' }}>Claude Haiku 4.5</option>
            <option value="claude-sonnet-4-5-20250929" {{ old('model', $agent['model'] ?? '') == 'claude-sonnet-4-5-20250929' ? 'selected' : '' }}>Claude Sonnet 4.5</option>
            <option value="claude-opus-4-5-20251101" {{ old('model', $agent['model'] ?? '') == 'claude-opus-4-5-20251101' ? 'selected' : '' }}>Claude Opus 4.5</option>
        </select>
    </div>

    @if(isset($agent))
        <div class="mb-6">
            <label for="systemPrompt" class="block text-sm font-medium mb-2">System Prompt <span class="text-red-400 font-bold">*</span></label>
            <textarea
                id="systemPrompt"
                name="systemPrompt"
                class="config-editor w-full"
                required
            >{{ old('systemPrompt', $agent['systemPrompt'] ?? '') }}</textarea>
        </div>
    @endif

    <div class="flex gap-3">
        <x-button type="submit" variant="primary">
            {{ isset($agent) ? 'Update Agent' : 'Create Agent' }}
        </x-button>
        <a href="{{ route('config.agents') }}">
            <x-button type="button" variant="secondary">
                Cancel
            </x-button>
        </a>
    </div>
</form>

@if(isset($agent))
    <!-- Separate delete form -->
    <form method="POST" action="{{ route('config.agents.delete', $agent['filename']) }}" class="mt-4">
        @csrf
        @method('DELETE')
        <x-button type="submit" variant="danger" onclick="return confirm('Are you sure you want to delete this agent?')">
            Delete Agent
        </x-button>
    </form>
@endif
@endsection
