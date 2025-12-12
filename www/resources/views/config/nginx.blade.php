@extends('layouts.config')

@section('title', 'nginx.conf')

@section('content')
<form method="POST" action="{{ route('config.nginx.save') }}">
    @csrf
    <div class="mb-4">
        <label for="content" class="block text-sm font-medium mb-2">nginx.conf Content</label>
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
