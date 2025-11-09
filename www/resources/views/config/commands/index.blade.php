@extends('layouts.config')

@section('title', 'Commands')

@section('content')
<div class="mb-6">
    <a href="{{ route('config.commands.create') }}" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium inline-block">
        Create New Command
    </a>
</div>

<div class="space-y-4">
    @forelse($commands as $command)
        <div class="p-4 bg-gray-800 rounded border border-gray-700">
            <h3 class="text-xl font-semibold mb-2">{{ $command['name'] }}</h3>
            @if(!empty($command['description']))
                <p class="text-gray-400 mb-3">{{ $command['description'] }}</p>
            @endif
            <div class="flex gap-3">
                <a href="{{ route('config.commands.edit', $command['filename']) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm">
                    Edit
                </a>
                <form method="POST" action="{{ route('config.commands.delete', $command['filename']) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded text-sm" onclick="return confirm('Are you sure you want to delete this command?')">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    @empty
        <p class="text-gray-400">No commands found.</p>
    @endforelse
</div>
@endsection
