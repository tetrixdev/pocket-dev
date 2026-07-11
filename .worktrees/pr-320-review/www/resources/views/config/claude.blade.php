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
    <x-button type="submit" variant="primary">
        Save
    </x-button>
</form>
@endsection
