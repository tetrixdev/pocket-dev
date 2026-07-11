@extends('layouts.config')

@section('title', 'Agents')

@section('content')
@if($workspaces->isEmpty())
    <div class="p-8 bg-gray-800 rounded border border-gray-700 text-center">
        <p class="text-gray-400 mb-4">No workspaces found.</p>
        <p class="text-gray-500 text-sm">Create a workspace first to add agents.</p>
        <a href="{{ route('config.workspaces.create') }}" class="inline-block mt-4">
            <x-button variant="primary">Create Workspace</x-button>
        </a>
    </div>
@else
    <div class="space-y-6 md:space-y-8">
        @foreach($workspaces as $workspace)
            <div class="bg-gray-850 rounded-lg border border-gray-700 overflow-hidden">
                {{-- Workspace Header --}}
                <div class="px-3 py-2 md:px-4 md:py-3 bg-gray-800 border-b border-gray-700 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 md:gap-3 min-w-0">
                        <h2 class="text-base md:text-lg font-semibold text-white truncate">{{ $workspace->name }}</h2>
                        <span class="text-xs md:text-sm text-gray-400 shrink-0">
                            {{ $workspace->agents->count() }}
                        </span>
                    </div>
                    <a href="{{ route('config.workspaces.edit', $workspace) }}" class="text-xs md:text-sm text-gray-400 hover:text-white shrink-0">
                        Edit
                    </a>
                </div>

                {{-- Agents List --}}
                <div class="p-3 md:p-4">
                    @include('config.agents.partials.agent-list', [
                        'agents' => $workspace->agents,
                        'workspace' => $workspace,
                    ])
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
