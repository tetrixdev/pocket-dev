@extends('layouts.config')

@section('title', 'CLAUDE.md')

@section('content')
<form method="POST" action="{{ route('config.claude.save') }}">
    @csrf
    <div class="mb-4">
        <label for="content" class="block text-sm font-medium mb-2">CLAUDE.md Content</label>
        <textarea
            id="content"
            name="content"
            class="config-editor w-full"
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
