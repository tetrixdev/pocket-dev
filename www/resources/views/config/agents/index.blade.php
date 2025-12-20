@extends('layouts.config')

@section('title', 'Agents')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <a href="{{ route('config.agents.create') }}">
        <x-button variant="primary">
            Create New Agent
        </x-button>
    </a>
</div>

@php
    $grouped = $agents->groupBy('provider');
    $providerNames = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'claude_code' => 'Claude Code',
    ];
    $providerOrder = ['anthropic', 'openai', 'claude_code'];
@endphp

@if($agents->isEmpty())
    <div class="p-8 bg-gray-800 rounded border border-gray-700 text-center">
        <p class="text-gray-400 mb-4">No agents configured yet.</p>
        <p class="text-gray-500 text-sm">Create an agent to get started with AI conversations.</p>
    </div>
@else
    <div class="space-y-8">
        @foreach($providerOrder as $providerKey)
            @if($grouped->has($providerKey))
                <div>
                    <h2 class="text-lg font-semibold text-white mb-3 flex items-center gap-2">
                        <span class="inline-block w-3 h-3 rounded-full {{ match($providerKey) {
                            'anthropic' => 'bg-orange-500',
                            'openai' => 'bg-green-500',
                            'claude_code' => 'bg-purple-500',
                            default => 'bg-gray-500'
                        } }}"></span>
                        {{ $providerNames[$providerKey] ?? ucfirst($providerKey) }}
                        <span class="text-sm text-gray-400 font-normal">({{ $grouped[$providerKey]->count() }} {{ Str::plural('agent', $grouped[$providerKey]->count()) }})</span>
                    </h2>

                    <div class="space-y-3">
                        @foreach($grouped[$providerKey] as $agent)
                            <div class="p-4 bg-gray-800 rounded border border-gray-700 {{ !$agent->enabled ? 'opacity-60' : '' }}">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <h3 class="text-lg font-semibold text-white">{{ $agent->name }}</h3>

                                            @if($agent->is_default)
                                                <span class="px-2 py-0.5 text-xs font-medium bg-blue-600 text-white rounded">Default</span>
                                            @endif

                                            @if(!$agent->enabled)
                                                <span class="px-2 py-0.5 text-xs font-medium bg-gray-600 text-gray-300 rounded">Disabled</span>
                                            @endif
                                        </div>

                                        <div class="mt-1 flex items-center gap-3 text-sm text-gray-400">
                                            <span class="font-mono text-xs bg-gray-700 px-2 py-0.5 rounded">{{ $agent->model }}</span>

                                            @if($agent->allowed_tools === null)
                                                <span class="text-gray-500">All tools</span>
                                            @else
                                                <span class="text-gray-500">{{ count($agent->allowed_tools) }} {{ Str::plural('tool', count($agent->allowed_tools)) }}</span>
                                            @endif

                                            @php
                                                $reasoning = $agent->getReasoningValue();
                                            @endphp
                                            @if($reasoning && $reasoning !== 'none' && $reasoning !== 0)
                                                <span class="text-gray-500">
                                                    @if(is_numeric($reasoning))
                                                        {{ number_format($reasoning) }} thinking tokens
                                                    @else
                                                        {{ ucfirst($reasoning) }} reasoning
                                                    @endif
                                                </span>
                                            @endif
                                        </div>

                                        @if($agent->description)
                                            <p class="mt-2 text-sm text-gray-400">{{ Str::limit($agent->description, 150) }}</p>
                                        @endif
                                    </div>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <!-- Toggle Default -->
                                        <form method="POST" action="{{ route('config.agents.toggle-default', $agent) }}" class="inline">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="p-2 rounded hover:bg-gray-700 transition-colors {{ $agent->is_default ? 'text-blue-400' : 'text-gray-500 hover:text-gray-300' }}"
                                                title="{{ $agent->is_default ? 'Remove as default' : 'Set as default' }}"
                                            >
                                                <svg class="w-5 h-5" fill="{{ $agent->is_default ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                                </svg>
                                            </button>
                                        </form>

                                        <!-- Toggle Enabled -->
                                        <form method="POST" action="{{ route('config.agents.toggle-enabled', $agent) }}" class="inline">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="p-2 rounded hover:bg-gray-700 transition-colors {{ $agent->enabled ? 'text-green-400' : 'text-gray-500 hover:text-gray-300' }}"
                                                title="{{ $agent->enabled ? 'Disable agent' : 'Enable agent' }}"
                                            >
                                                @if($agent->enabled)
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                    </svg>
                                                @else
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                                    </svg>
                                                @endif
                                            </button>
                                        </form>

                                        <!-- Edit -->
                                        <a
                                            href="{{ route('config.agents.edit', $agent) }}"
                                            class="p-2 rounded hover:bg-gray-700 transition-colors text-gray-400 hover:text-white"
                                            title="Edit agent"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </a>

                                        <!-- Delete -->
                                        <form method="POST" action="{{ route('config.agents.delete', $agent) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                class="p-2 rounded hover:bg-gray-700 transition-colors text-gray-400 hover:text-red-400"
                                                title="Delete agent"
                                                onclick="return confirm('Are you sure you want to delete this agent?')"
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
                </div>
            @endif
        @endforeach
    </div>
@endif
@endsection
