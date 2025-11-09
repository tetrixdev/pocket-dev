@extends('layouts.config')

@section('title', 'Create Command')

@section('content')
<form method="POST" action="{{ route('config.commands.store') }}">
    @csrf

    <div class="mb-6">
        <label for="name" class="block text-sm font-medium mb-2">Command Name</label>
        <input
            type="text"
            id="name"
            name="name"
            value="{{ old('name') }}"
            class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded"
            placeholder="my-command"
            required
        >
        <p class="text-sm text-gray-400 mt-1">Will be accessible as /my-command</p>
    </div>

    <div class="flex gap-3">
        <button
            type="submit"
            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
        >
            Create Command
        </button>
        <a
            href="{{ route('config.commands') }}"
            class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium inline-block"
        >
            Cancel
        </a>
    </div>
</form>
@endsection
