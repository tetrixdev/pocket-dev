@extends('layouts.config')

@section('title', 'Edit Skill: ' . $skill['name'])

@section('content')
<form method="POST" action="{{ route('config.skills.update', $skill['filename']) }}">
    @csrf
    @method('PUT')

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left sidebar: Skill metadata -->
        <div class="lg:col-span-1">
            <div class="bg-gray-800 p-4 rounded border border-gray-700 mb-4">
                <h2 class="text-xl font-semibold mb-4">Skill Metadata</h2>

                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium mb-2">Name</label>
                    <input
                        type="text"
                        id="name"
                        value="{{ $skill['name'] }}"
                        class="w-full px-3 py-2 bg-gray-600 text-gray-300 border border-gray-600 rounded cursor-not-allowed"
                        disabled
                    >
                    <p class="text-xs text-gray-500 mt-1">Skill name cannot be changed (it's the directory name)</p>
                </div>

                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium mb-2">Description <span class="text-red-500 font-bold">*</span></label>
                    <textarea
                        id="description"
                        name="description"
                        rows="3"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                        required
                    >{{ old('description', $skill['description'] ?? '') }}</textarea>
                </div>

                <div class="mb-4">
                    <label for="allowedTools" class="block text-sm font-medium mb-2">Allowed Tools</label>
                    <input
                        type="text"
                        id="allowedTools"
                        name="allowedTools"
                        value="{{ old('allowedTools', $skill['allowedTools'] ?? '') }}"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                        placeholder="bash, read, write"
                    >
                </div>

                <a
                    href="{{ route('config.skills') }}"
                    class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium text-center mb-2"
                >
                    Back to Skills
                </a>

                <button
                    type="button"
                    onclick="if(confirm('Are you sure you want to delete this skill?')) { document.getElementById('delete-form').submit(); }"
                    class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium"
                >
                    Delete Skill
                </button>
            </div>
        </div>

        <!-- Right side: SKILL.md editor -->
        <div class="lg:col-span-2">
            <div class="bg-gray-800 p-4 rounded border border-gray-700">
                <h2 class="text-xl font-semibold mb-4">SKILL.md Content</h2>

                <div class="mb-4">
                    <textarea
                        id="content"
                        name="content"
                        class="config-editor w-full"
                    >{{ old('content', $skill['content'] ?? '') }}</textarea>
                </div>

                <button
                    type="submit"
                    class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
                >
                    Save Skill
                </button>
            </div>
        </div>
    </div>
</form>

<!-- Hidden delete form -->
<form id="delete-form" method="POST" action="{{ route('config.skills.delete', $skill['filename']) }}" class="hidden">
    @csrf
    @method('DELETE')
</form>
@endsection
