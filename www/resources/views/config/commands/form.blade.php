@extends('layouts.config')

@section('title', isset($command) ? 'Edit Command: /' . $command['name'] : 'Create Command')

@section('content')
<form method="POST" action="{{ isset($command) ? route('config.commands.update', $command['filename']) : route('config.commands.store') }}">
    @csrf
    @if(isset($command))
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left column: Command metadata -->
        <div class="lg:col-span-1">
            <div class="bg-gray-800 p-4 rounded border border-gray-700">
                <h2 class="text-xl font-semibold mb-4">Command Metadata</h2>

                <!-- Name field -->
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium mb-2">
                        Name <span class="text-red-500 font-bold">*</span>
                    </label>
                    <div class="flex items-center">
                        <span class="text-gray-400 mr-1">/</span>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="{{ old('name', $command['name'] ?? '') }}"
                            class="flex-1 px-3 py-2 {{ isset($command) ? 'bg-gray-600 text-gray-300 cursor-not-allowed' : 'bg-gray-700 text-white' }} border border-gray-600 rounded"
                            pattern="[a-z0-9-]+"
                            placeholder="my-command"
                            {{ isset($command) ? 'disabled' : 'required' }}
                        >
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Lowercase letters, numbers, and hyphens only.
                        @if(isset($command))
                            <span class="text-gray-400">(Cannot be changed)</span>
                        @endif
                    </p>
                </div>

                <!-- Description field -->
                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium mb-2">
                        Description <span class="text-red-500 font-bold">*</span>
                    </label>
                    <textarea
                        id="description"
                        name="description"
                        rows="2"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                        placeholder="What this command does..."
                        required
                    >{{ old('description', $command['description'] ?? '') }}</textarea>
                    <p class="text-xs text-gray-500 mt-1">Shown in autocomplete. Helps users understand what the command does.</p>
                </div>

                <!-- Argument Hint field -->
                <div class="mb-4">
                    <label for="argumentHint" class="block text-sm font-medium mb-2">Argument Hint</label>
                    <input
                        type="text"
                        id="argumentHint"
                        name="argumentHint"
                        value="{{ old('argumentHint', $command['argumentHint'] ?? '') }}"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                        placeholder="[message]"
                    >
                    <p class="text-xs text-gray-500 mt-1">
                        Pattern shown in autocomplete. Use <code class="text-gray-400">$1</code>, <code class="text-gray-400">$2</code> in prompt to reference arguments.
                    </p>
                </div>

                <!-- Model field -->
                <div class="mb-4">
                    <label for="model" class="block text-sm font-medium mb-2">Model</label>
                    <select
                        id="model"
                        name="model"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                    >
                        <option value="" {{ old('model', $command['model'] ?? '') == '' ? 'selected' : '' }}>Inherit from conversation</option>
                        <option value="claude-sonnet-4-5-20250929" {{ old('model', $command['model'] ?? '') == 'claude-sonnet-4-5-20250929' ? 'selected' : '' }}>Claude Sonnet 4.5</option>
                        <option value="claude-opus-4-20250514" {{ old('model', $command['model'] ?? '') == 'claude-opus-4-20250514' ? 'selected' : '' }}>Claude Opus 4</option>
                        <option value="claude-opus-4-5-20251101" {{ old('model', $command['model'] ?? '') == 'claude-opus-4-5-20251101' ? 'selected' : '' }}>Claude Opus 4.5</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Override model for this command, or inherit from conversation.</p>
                </div>

                <!-- Allowed Tools field -->
                <div class="mb-4">
                    <label for="allowedTools" class="block text-sm font-medium mb-2">Allowed Tools</label>
                    <textarea
                        id="allowedTools"
                        name="allowedTools"
                        rows="2"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded font-mono text-sm"
                        placeholder="Bash(git add:*), Read, Write"
                    >{{ old('allowedTools', $command['allowedTools'] ?? '') }}</textarea>
                    <p class="text-xs text-gray-500 mt-1">Tools this command can use. Leave empty to inherit.</p>
                </div>

                <!-- Disable Model Invocation -->
                <div class="mb-4">
                    <label class="flex items-start">
                        <input
                            type="checkbox"
                            id="disableModelInvocation"
                            name="disableModelInvocation"
                            value="1"
                            class="mt-1 mr-2"
                            {{ old('disableModelInvocation', $command['disableModelInvocation'] ?? false) ? 'checked' : '' }}
                        >
                        <span>
                            <span class="text-sm font-medium">Disable Model Invocation</span>
                            <p class="text-xs text-gray-500">Prevent Claude from auto-executing via SlashCommand tool.</p>
                        </span>
                    </label>
                </div>

                <!-- Action buttons -->
                <div class="space-y-2 pt-2 border-t border-gray-700">
                    <button
                        type="submit"
                        class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
                    >
                        {{ isset($command) ? 'Save Command' : 'Create Command' }}
                    </button>

                    <a
                        href="{{ route('config.commands') }}"
                        class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium text-center"
                    >
                        Cancel
                    </a>

                    @if(isset($command))
                        <button
                            type="button"
                            onclick="if(confirm('Are you sure you want to delete this command?')) { document.getElementById('delete-form').submit(); }"
                            class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium"
                        >
                            Delete Command
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <!-- Right column: Prompt editor -->
        <div class="lg:col-span-2">
            <div class="bg-gray-800 p-4 rounded border border-gray-700">
                <h2 class="text-xl font-semibold mb-2">
                    Prompt <span class="text-red-500 font-bold">*</span>
                </h2>
                <p class="text-sm text-gray-400 mb-4">
                    The prompt sent to Claude when this command is invoked. Use <code class="text-gray-300">$1</code>, <code class="text-gray-300">$2</code>, etc. to reference user arguments.
                </p>

                <textarea
                    id="prompt"
                    name="prompt"
                    class="config-editor w-full"
                    placeholder="Perform the following task:

$1

When completing this task:
- Be thorough and precise
- Follow best practices
- Explain your reasoning"
                    required
                >{{ old('prompt', $command['prompt'] ?? '') }}</textarea>
            </div>
        </div>
    </div>
</form>

@if(isset($command))
    <form id="delete-form" method="POST" action="{{ route('config.commands.delete', $command['filename']) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endif
@endsection
