@extends('layouts.config')

@section('title', 'settings.json')

@section('content')
<form method="POST" action="{{ route('config.settings.save') }}">
    @csrf
    <div class="mb-4">
        <label for="content" class="block text-sm font-medium mb-2">settings.json Content</label>
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
