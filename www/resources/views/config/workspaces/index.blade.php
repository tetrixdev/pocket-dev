@extends('layouts.config')

@section('title', 'Workspaces')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <a href="{{ route('config.workspaces.create') }}">
        <x-button variant="primary">
            Create New Workspace
        </x-button>
    </a>
</div>

@if($workspaces->isEmpty())
    <div class="p-8 bg-gray-800 rounded border border-gray-700 text-center">
        <p class="text-gray-400 mb-4">No workspaces configured yet.</p>
        <p class="text-gray-500 text-sm">Create a workspace to organize your projects and conversations.</p>
    </div>
@else
    <div class="space-y-4">
        @foreach($workspaces as $workspace)
            <div class="p-4 bg-gray-800 rounded border border-gray-700">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="text-lg font-semibold text-white">{{ $workspace->name }}</h3>
                            <span class="px-2 py-0.5 text-xs font-mono bg-gray-700 text-gray-300 rounded">/workspace/{{ $workspace->directory }}</span>
                        </div>

                        <div class="mt-1 flex items-center gap-3 text-sm text-gray-400">
                            <span>{{ $workspace->agents_count }} {{ Str::plural('agent', $workspace->agents_count) }}</span>
                            <span class="text-gray-600">|</span>
                            <span>{{ $workspace->conversations_count }} {{ Str::plural('conversation', $workspace->conversations_count) }}</span>
                            @php
                                $memoryDbCount = $workspace->memoryDatabases()->count();
                            @endphp
                            @if($memoryDbCount > 0)
                                <span class="text-gray-600">|</span>
                                <span>{{ $memoryDbCount }} {{ Str::plural('memory database', $memoryDbCount) }}</span>
                            @endif
                        </div>

                        @if($workspace->description)
                            <p class="mt-2 text-sm text-gray-400">{{ Str::limit($workspace->description, 150) }}</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <!-- Tools -->
                        <a
                            href="{{ route('config.workspaces.tools', $workspace) }}"
                            class="p-2 rounded hover:bg-gray-700 transition-colors text-gray-400 hover:text-white"
                            title="Manage tools"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </a>

                        <!-- Edit -->
                        <a
                            href="{{ route('config.workspaces.edit', $workspace) }}"
                            class="p-2 rounded hover:bg-gray-700 transition-colors text-gray-400 hover:text-white"
                            title="Edit workspace"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </a>

                        <!-- Delete -->
                        <form method="POST" action="{{ route('config.workspaces.delete', $workspace) }}" class="inline">
                            @csrf
                            @method('DELETE')
                            <button
                                type="submit"
                                class="p-2 rounded hover:bg-gray-700 transition-colors text-gray-400 hover:text-red-400"
                                title="Delete workspace"
                                onclick="return confirm('Are you sure you want to delete this workspace?')"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
