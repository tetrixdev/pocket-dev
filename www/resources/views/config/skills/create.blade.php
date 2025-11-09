@extends('layouts.config')

@section('title', 'Create Skill')

@section('content')
<form method="POST" action="{{ route('config.skills.store') }}">
    @csrf

    <div class="mb-4">
        <label for="name" class="block text-sm font-medium mb-2">Skill Name</label>
        <input
            type="text"
            id="name"
            name="name"
            value="{{ old('name') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            placeholder="my-skill"
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
        >{{ old('description') }}</textarea>
    </div>

    <div class="mb-6">
        <label for="allowedTools" class="block text-sm font-medium mb-2">Allowed Tools (comma-separated)</label>
        <input
            type="text"
            id="allowedTools"
            name="allowedTools"
            value="{{ old('allowedTools') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            placeholder="bash, read, write"
        >
    </div>

    <div class="flex gap-3">
        <button
            type="submit"
            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
        >
            Create Skill
        </button>
        <a
            href="{{ route('config.skills') }}"
            class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium inline-block"
        >
            Cancel
        </a>
    </div>
</form>
@endsection
