@extends('layouts.config')

@section('title', isset($skill) ? 'Edit Skill: ' . $skill['name'] : 'Create Skill')

@section('content')
<form method="POST" action="{{ isset($skill) ? route('config.skills.update', $skill['filename']) : route('config.skills.store') }}">
    @csrf
    @if(isset($skill))
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left sidebar: Skill metadata -->
        <div class="lg:col-span-1">
            <div class="bg-gray-800 p-4 rounded border border-gray-700 mb-4">
                <h2 class="text-xl font-semibold mb-4">Skill Metadata</h2>

                <!-- Name field -->
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium mb-2">
                        Name <span class="text-red-500 font-bold">*</span>
                    </label>
                    @if(isset($skill))
                        <input
                            type="text"
                            id="name"
                            value="{{ $skill['name'] }}"
                            class="w-full px-3 py-2 bg-gray-600 text-gray-300 border border-gray-600 rounded cursor-not-allowed"
                            disabled
                        >
                        <p class="text-xs text-gray-500 mt-1">Skill name cannot be changed (it's the directory name)</p>
                    @else
                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="{{ old('name', '') }}"
                            class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                            pattern="[a-z0-9-]+"
                            placeholder="my-skill-name"
                            required
                        >
                        <p class="text-xs text-gray-500 mt-1">Lowercase letters, numbers, and hyphens only. This becomes the skill's directory name.</p>
                    @endif
                </div>

                <!-- Description field -->
                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium mb-2">
                        Description <span class="text-red-500 font-bold">*</span>
                    </label>
                    <textarea
                        id="description"
                        name="description"
                        rows="3"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                        placeholder="Describe when this skill should be used..."
                        required
                    >{{ old('description', $skill['description'] ?? '') }}</textarea>
                    <p class="text-xs text-gray-500 mt-1">This description is included in the system prompt. Claude uses it to decide when to invoke this skill.</p>
                </div>

                <!-- Allowed Tools field -->
                <div class="mb-4">
                    <label for="allowedTools" class="block text-sm font-medium mb-2">Allowed Tools</label>
                    <input
                        type="text"
                        id="allowedTools"
                        name="allowedTools"
                        value="{{ old('allowedTools', $skill['allowedTools'] ?? '') }}"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                        placeholder="Read, Edit, Bash"
                    >
                    <p class="text-xs text-gray-500 mt-1">Comma-separated list of tools the skill can use. Leave empty to allow all tools. Common tools: Read, Write, Edit, Bash, Glob, Grep.</p>
                </div>

                <!-- Action buttons -->
                <div class="space-y-2">
                    <button
                        type="submit"
                        class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
                    >
                        {{ isset($skill) ? 'Save Skill' : 'Create Skill' }}
                    </button>

                    <a
                        href="{{ route('config.skills') }}"
                        class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium text-center"
                    >
                        Cancel
                    </a>

                    @if(isset($skill))
                        <button
                            type="button"
                            onclick="if(confirm('Are you sure you want to delete this skill?')) { document.getElementById('delete-form').submit(); }"
                            class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium"
                        >
                            Delete Skill
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <!-- Right side: SKILL.md editor -->
        <div class="lg:col-span-2">
            <div class="bg-gray-800 p-4 rounded border border-gray-700">
                <h2 class="text-xl font-semibold mb-2">SKILL.md Content</h2>
                <p class="text-sm text-gray-400 mb-4">
                    This is the main content of your skill. Write instructions, examples, and any reference material that Claude should use when this skill is invoked. The content is injected into the conversation when the skill is triggered.
                </p>

                <div class="mb-4">
                    <textarea
                        id="content"
                        name="content"
                        class="config-editor w-full"
                        placeholder="Write your skill instructions here...

Example:
## Purpose
This skill helps with...

## Instructions
When using this skill:
1. First, do X
2. Then, do Y
3. Finally, do Z

## Examples
Here's an example of how to..."
                    >{{ old('content', $skill['content'] ?? '') }}</textarea>
                </div>
            </div>
        </div>
    </div>
</form>

@if(isset($skill))
    <!-- Hidden delete form -->
    <form id="delete-form" method="POST" action="{{ route('config.skills.delete', $skill['filename']) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endif
@endsection
