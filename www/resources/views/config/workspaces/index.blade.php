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
            <div class="bg-gray-800 rounded border border-gray-700" x-data="{ showAgents: false }">
                <div class="p-4">
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
                            <!-- Agents toggle -->
                            <button
                                type="button"
                                @click="showAgents = !showAgents"
                                class="p-2 rounded hover:bg-gray-700 transition-colors"
                                :class="showAgents ? 'text-blue-400' : 'text-gray-400 hover:text-white'"
                                title="Manage agents"
                            >
                                <!-- Robot icon -->
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </button>

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

                            <!-- Delete (soft delete - moves to trash) -->
                            <form method="POST" action="{{ route('config.workspaces.delete', $workspace) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button
                                    type="submit"
                                    class="p-2 rounded hover:bg-gray-700 transition-colors text-gray-400 hover:text-red-400"
                                    title="Move to trash"
                                    onclick="return confirm('Move this workspace to trash? You can restore it later.')"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Expandable Agents Section -->
                <div
                    x-show="showAgents"
                    x-collapse
                    x-cloak
                    class="border-t border-gray-700"
                >
                    <div class="p-4 bg-gray-750">
                        @include('config.agents.partials.agent-list', [
                            'agents' => $workspace->agents()->orderBy('is_default', 'desc')->orderBy('name')->get(),
                            'workspace' => $workspace,
                            'showCreateButton' => true,
                        ])
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif

{{-- Trash Section --}}
@if($trashedWorkspaces->isNotEmpty())
    <div class="mt-10">
        <h2 class="text-lg font-semibold text-gray-400 mb-4 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            Trash
        </h2>
        <div class="space-y-3">
            @foreach($trashedWorkspaces as $workspace)
                <div class="bg-gray-800/50 rounded border border-gray-700/50 p-4 opacity-75">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="text-base font-medium text-gray-300">{{ $workspace->name }}</h3>
                                <span class="px-2 py-0.5 text-xs font-mono bg-gray-700/50 text-gray-400 rounded">/workspace/{{ $workspace->directory }}</span>
                            </div>
                            <div class="mt-1 flex items-center gap-3 text-sm text-gray-500">
                                <span>{{ $workspace->agents_count }} {{ Str::plural('agent', $workspace->agents_count) }}</span>
                                <span class="text-gray-600">|</span>
                                <span>{{ $workspace->conversations_count }} {{ Str::plural('conversation', $workspace->conversations_count) }}</span>
                                <span class="text-gray-600">|</span>
                                <span>Deleted {{ $workspace->deleted_at->diffForHumans() }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            {{-- Restore --}}
                            <form method="POST" action="{{ route('config.workspaces.restore', $workspace->id) }}" class="inline">
                                @csrf
                                <button
                                    type="submit"
                                    class="px-3 py-1.5 text-sm bg-blue-600 hover:bg-blue-500 text-white rounded transition-colors flex items-center gap-1.5"
                                    title="Restore workspace"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Restore
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
@endsection
