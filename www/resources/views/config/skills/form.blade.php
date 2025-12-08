@extends('layouts.config')

@section('title', isset($skill) ? 'Edit Skill: ' . $skill['name'] : 'Create Skill')

@section('content')
<form method="POST" action="{{ isset($skill) ? route('config.skills.update', $skill['filename']) : route('config.skills.store') }}">
    @csrf
    @if(isset($skill))
        @method('PUT')
    @endif

    <div class="mb-4">
        <label for="name" class="block text-sm font-medium mb-2">Name <span class="text-red-400 font-bold">*</span> <span class="text-gray-400 font-normal">(lowercase, hyphens only)</span></label>
        <input
            type="text"
            id="name"
            name="name"
            value="{{ old('name', $skill['name'] ?? '') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            pattern="[a-z0-9-]+"
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
        >{{ old('description', $skill['description'] ?? '') }}</textarea>
    </div>

    <div class="mb-6">
        <label for="allowedTools" class="block text-sm font-medium mb-2">Allowed Tools (comma-separated)</label>
        <input
            type="text"
            id="allowedTools"
            name="allowedTools"
            value="{{ old('allowedTools', $skill['allowedTools'] ?? '') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            placeholder="bash, read, write"
        >
    </div>

    <div class="flex gap-3">
        <x-button type="submit" variant="primary">
            {{ isset($skill) ? 'Update Skill' : 'Create Skill' }}
        </x-button>
        <a href="{{ route('config.skills') }}">
            <x-button type="button" variant="secondary">
                Cancel
            </x-button>
        </a>
    </div>
</form>

@if(isset($skill))
    <!-- Separate delete form -->
    <form method="POST" action="{{ route('config.skills.delete', $skill['filename']) }}" class="mt-4">
        @csrf
        @method('DELETE')
        <x-button type="submit" variant="danger" onclick="return confirm('Are you sure you want to delete this skill?')">
            Delete Skill
        </x-button>
    </form>
@endif
@endsection
