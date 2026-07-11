@extends('layouts.config')

@section('title', 'Skills')

@section('content')
<div class="mb-6">
    <a href="{{ route('config.skills.create') }}" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium inline-block">
        Create New Skill
    </a>
</div>

<div class="space-y-4">
    @forelse($skills as $skill)
        <div class="p-4 bg-gray-800 rounded border border-gray-700">
            <h3 class="text-xl font-semibold mb-2">{{ $skill['name'] }}</h3>
            @if(!empty($skill['description']))
                <p class="text-gray-400 mb-3">{{ $skill['description'] }}</p>
            @endif
            <div class="flex gap-3">
                <a href="{{ route('config.skills.edit', $skill['filename']) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm">
                    Edit
                </a>
                <form method="POST" action="{{ route('config.skills.delete', $skill['filename']) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded text-sm" onclick="return confirm('Are you sure you want to delete this skill?')">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    @empty
        <p class="text-gray-400">No skills found.</p>
    @endforelse
</div>
@endsection
