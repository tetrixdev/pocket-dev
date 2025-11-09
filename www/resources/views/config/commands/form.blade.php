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
            <label for="name" class="block text-sm font-medium mb-2">Name <span class="text-red-400 font-bold">*</span> <span class="text-gray-400 font-normal">(lowercase, hyphens only)</span></label>
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
        <label for="description" class="block text-sm font-medium mb-2">Description <span class="text-red-400 font-bold">*</span></label>
        <textarea
            id="description"
            name="description"
            rows="2"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            required
        >{{ old('description', $command['description'] ?? '') }}</textarea>
    </div>

    <div class="mb-4">
        <label for="argumentHint" class="block text-sm font-medium mb-2">Argument Hint</label>
        <input
            type="text"
            id="argumentHint"
            name="argumentHint"
            value="{{ old('argumentHint', $command['argumentHint'] ?? '') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            placeholder="[message]"
        >
        <p class="mt-1 text-xs text-gray-400">
            Pattern shown during auto-completion (e.g., <code>[message]</code>, <code>[file] [options]</code>).
            Reference arguments in your prompt using <code>$1</code>, <code>$2</code>, etc.
            <a href="https://code.claude.com/docs/en/slash-commands.md" target="_blank" class="text-blue-400 hover:text-blue-300">Learn more</a>
        </p>
    </div>

    <div class="mb-4">
        <label for="model" class="block text-sm font-medium mb-2">Model</label>
        <select
            id="model"
            name="model"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
        >
            <option value="" {{ old('model', $command['model'] ?? '') == '' ? 'selected' : '' }}>Inherit from conversation</option>
            <option value="claude-sonnet-4-5-20250929" {{ old('model', $command['model'] ?? '') == 'claude-sonnet-4-5-20250929' ? 'selected' : '' }}>Claude Sonnet 4.5</option>
            <option value="claude-opus-4-20250514" {{ old('model', $command['model'] ?? '') == 'claude-opus-4-20250514' ? 'selected' : '' }}>Claude Opus 4</option>
            <option value="claude-3-5-sonnet-20241022" {{ old('model', $command['model'] ?? '') == 'claude-3-5-sonnet-20241022' ? 'selected' : '' }}>Claude 3.5 Sonnet</option>
        </select>
    </div>

    <div class="mb-6">
        <label for="prompt" class="block text-sm font-medium mb-2">Prompt @if(isset($command))<span class="text-red-400 font-bold">*</span>@endif</label>
        <textarea
            id="prompt"
            name="prompt"
            class="config-editor w-full"
            @if(isset($command)) required @endif
        >{{ old('prompt', $command['prompt'] ?? '') }}</textarea>
    </div>

    <div class="mb-4">
        <label class="flex items-center">
            <input
                type="checkbox"
                id="disableModelInvocation"
                name="disableModelInvocation"
                value="1"
                class="mr-2"
                {{ old('disableModelInvocation', $command['disableModelInvocation'] ?? false) ? 'checked' : '' }}
            >
            <span class="text-sm font-medium">Disable Model Invocation</span>
        </label>
        <p class="mt-1 text-xs text-gray-400 ml-6">
            Prevent Claude from automatically executing this command via the SlashCommand tool
        </p>
    </div>

    <div class="mb-6">
        <label for="allowedTools" class="block text-sm font-medium mb-2">Allowed Tools</label>
        <textarea
            id="allowedTools"
            name="allowedTools"
            rows="3"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded font-mono text-sm"
            placeholder="Bash(git add:*), Bash(git status:*), Read, Write"
        >{{ old('allowedTools', $command['allowedTools'] ?? '') }}</textarea>
        <p class="mt-1 text-xs text-gray-400">
            Specific tools/commands this slash command can use. Leave empty to inherit from conversation.
        </p>
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
