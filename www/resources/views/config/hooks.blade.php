@extends('layouts.config')

@section('title', 'Hooks')

@section('content')
<form method="POST" action="{{ route('config.hooks.save') }}">
    @csrf
    <div class="mb-4">
        <label for="content" class="block text-sm font-medium mb-2">~/.claude/settings.json</label>
        <p class="text-sm text-zinc-400 mb-3">
            Claude Code settings file. See <a href="https://docs.anthropic.com/en/docs/claude-code/settings" target="_blank" class="text-blue-400 hover:underline">docs.anthropic.com/en/docs/claude-code/settings</a> for available options.
        </p>
        <textarea
            id="content"
            name="content"
            class="config-editor w-full"
            rows="20"
            required
        >{{ $content }}</textarea>
    </div>
    <button
        type="submit"
        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
    >
        Save
    </button>
</form>
@endsection
