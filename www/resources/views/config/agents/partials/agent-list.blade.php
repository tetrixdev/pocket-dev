{{--
    Agent List Partial

    Displays a list of agents with provider color dot, model name, and action buttons.

    @param \Illuminate\Support\Collection $agents - Collection of agents to display
    @param \App\Models\Workspace|null $workspace - Workspace for create button (optional)
    @param bool $showCreateButton - Whether to show create agent button (default: true)
--}}

@php
    $providerColors = [
        'anthropic' => 'bg-orange-500',
        'openai' => 'bg-green-500',
        'claude_code' => 'bg-purple-500',
        'codex' => 'bg-teal-500',
        'openai_compatible' => 'bg-blue-500',
    ];

    // Build a flat model_id => display_name lookup from config
    $modelDisplayNames = collect(config('ai.models', []))
        ->flatMap(fn ($models) => collect($models)->mapWithKeys(fn ($m) => [$m['model_id'] => $m['display_name']]))
        ->toArray();
@endphp

@if(($showCreateButton ?? true) && isset($workspace))
    <div class="mb-3 md:mb-4 flex justify-end" x-data="{ open: false }">
        <div class="relative">
            <x-button variant="primary" size="sm" @click="open = !open" @click.outside="open = false">
                <span class="flex items-center gap-1">
                    <span class="hidden sm:inline">Create Agent</span>
                    <span class="sm:hidden">+ Agent</span>
                    <svg class="w-3.5 h-3.5 md:w-4 md:h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </span>
            </x-button>

            <div
                x-show="open"
                x-cloak
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="absolute right-0 mt-2 w-56 md:w-64 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 max-h-72 md:max-h-80 overflow-y-auto"
            >
                <a
                    href="{{ route('config.agents.create', ['workspace_id' => $workspace->id]) }}"
                    class="block px-3 py-2 md:px-4 text-sm md:text-base text-white hover:bg-gray-700 rounded-t-lg"
                >
                    New Agent
                </a>

                @php
                    $allAgents = \App\Models\Agent::with('workspace')
                        ->orderBy('workspace_id')
                        ->orderBy('name')
                        ->get()
                        ->groupBy(fn($a) => $a->workspace?->name ?? 'No Workspace');
                @endphp

                @if($allAgents->flatten()->isNotEmpty())
                    <div class="border-t border-gray-700 my-1"></div>
                    <div class="px-3 py-1 md:py-1.5 text-[10px] md:text-xs text-gray-500 uppercase tracking-wide">Copy from...</div>

                    @foreach($allAgents as $workspaceName => $workspaceAgents)
                        <div class="px-3 py-1 text-[10px] md:text-xs text-gray-400 bg-gray-750 truncate">{{ $workspaceName }}</div>
                        @foreach($workspaceAgents as $existingAgent)
                            <a
                                href="{{ route('config.agents.create', ['workspace_id' => $workspace->id, 'from' => $existingAgent->id]) }}"
                                class="flex px-3 py-1.5 md:px-4 md:py-2 text-xs md:text-sm text-gray-300 hover:bg-gray-700 hover:text-white items-center gap-2"
                            >
                                <span class="inline-block w-2 h-2 rounded-full shrink-0 {{ $providerColors[$existingAgent->provider] ?? 'bg-gray-500' }}"></span>
                                <span class="truncate">{{ $existingAgent->name }}</span>
                            </a>
                        @endforeach
                    @endforeach
                @endif
            </div>
        </div>
    </div>
@endif

@if($agents->isEmpty())
    <div class="p-6 bg-gray-800 rounded border border-gray-700 text-center">
        <p class="text-gray-400">No agents in this workspace.</p>
    </div>
@else
    <div class="space-y-2 md:space-y-3">
        @foreach($agents as $agent)
            <div class="p-3 md:p-4 bg-gray-800 rounded border border-gray-700 {{ !$agent->enabled ? 'opacity-60' : '' }}">
                {{-- Mobile: Stacked layout --}}
                <div class="md:hidden">
                    {{-- Row 1: Provider dot + Name + Status badges --}}
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0 {{ $providerColors[$agent->provider] ?? 'bg-gray-500' }}" title="{{ ucfirst(str_replace('_', ' ', $agent->provider)) }}"></span>
                        <h3 class="font-semibold text-white truncate">{{ $agent->name }}</h3>
                        @if($agent->is_default)
                            <span class="px-1.5 py-0.5 text-[10px] font-medium bg-blue-600 text-white rounded shrink-0">Default</span>
                        @endif
                        @if(!$agent->enabled)
                            <span class="px-1.5 py-0.5 text-[10px] font-medium bg-gray-600 text-gray-300 rounded shrink-0">Off</span>
                        @endif
                    </div>

                    {{-- Row 2: Model + metadata --}}
                    <div class="mt-1.5 flex items-center gap-2 text-xs text-gray-400">
                        @if(isset($modelDisplayNames[$agent->model]) && $modelDisplayNames[$agent->model] !== $agent->model)
                            <span class="bg-gray-700 px-1.5 py-0.5 rounded text-gray-300 truncate max-w-[200px]">{{ $modelDisplayNames[$agent->model] }} <span class="font-mono text-gray-500">[{{ $agent->model }}]</span></span>
                        @else
                            <span class="font-mono bg-gray-700 px-1.5 py-0.5 rounded text-gray-300 truncate max-w-[140px]">{{ $agent->model }}</span>
                        @endif
                        <span class="text-gray-500">{{ $agent->allowed_tools === null ? 'All tools' : count($agent->allowed_tools) . ' tools' }}</span>
                        @php $reasoning = $agent->getReasoningValue(); @endphp
                        @if($reasoning && $reasoning !== 'none' && $reasoning !== 0)
                            <span class="text-gray-500 truncate">{{ is_numeric($reasoning) ? number_format($reasoning) . ' tok' : ucfirst($reasoning) }}</span>
                        @endif
                    </div>

                    {{-- Row 3: Actions --}}
                    <div class="mt-2 flex items-center gap-1 border-t border-gray-700 pt-2 -mx-3 px-3">
                        <form method="POST" action="{{ route('config.agents.toggle-default', $agent) }}" class="inline">
                            @csrf
                            <button type="submit" class="p-1.5 rounded hover:bg-gray-700 {{ $agent->is_default ? 'text-blue-400' : 'text-gray-500' }}" title="{{ $agent->is_default ? 'Remove default' : 'Set default' }}">
                                <svg class="w-4 h-4" fill="{{ $agent->is_default ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                </svg>
                            </button>
                        </form>
                        <form method="POST" action="{{ route('config.agents.toggle-enabled', $agent) }}" class="inline">
                            @csrf
                            <button type="submit" class="p-1.5 rounded hover:bg-gray-700 {{ $agent->enabled ? 'text-green-400' : 'text-gray-500' }}" title="{{ $agent->enabled ? 'Disable' : 'Enable' }}">
                                @if($agent->enabled)
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                @else
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                                @endif
                            </button>
                        </form>
                        <a href="{{ route('config.agents.edit', $agent) }}" class="p-1.5 rounded hover:bg-gray-700 text-gray-400 hover:text-white" title="Edit">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        </a>
                        <div class="flex-1"></div>
                        <form method="POST" action="{{ route('config.agents.delete', $agent) }}" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="p-1.5 rounded hover:bg-gray-700 text-gray-400 hover:text-red-400" title="Delete" onclick="return confirm('Delete this agent?')">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </form>
                    </div>
                </div>

                {{-- Desktop: Original horizontal layout --}}
                <div class="hidden md:block">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="inline-block w-3 h-3 rounded-full {{ $providerColors[$agent->provider] ?? 'bg-gray-500' }}" title="{{ ucfirst(str_replace('_', ' ', $agent->provider)) }}"></span>
                                <h3 class="text-lg font-semibold text-white">{{ $agent->name }}</h3>
                                @if(isset($modelDisplayNames[$agent->model]) && $modelDisplayNames[$agent->model] !== $agent->model)
                                    <span class="text-xs bg-gray-700 px-2 py-0.5 rounded text-gray-300">{{ $modelDisplayNames[$agent->model] }} <span class="font-mono text-gray-500">[{{ $agent->model }}]</span></span>
                                @else
                                    <span class="font-mono text-xs bg-gray-700 px-2 py-0.5 rounded text-gray-300">{{ $agent->model }}</span>
                                @endif
                                @if($agent->is_default)
                                    <span class="px-2 py-0.5 text-xs font-medium bg-blue-600 text-white rounded">Default</span>
                                @endif
                                @if(!$agent->enabled)
                                    <span class="px-2 py-0.5 text-xs font-medium bg-gray-600 text-gray-300 rounded">Disabled</span>
                                @endif
                            </div>

                            <div class="mt-1 flex items-center gap-3 text-sm text-gray-400">
                                @if($agent->allowed_tools === null)
                                    <span class="text-gray-500">All tools</span>
                                @else
                                    <span class="text-gray-500">{{ count($agent->allowed_tools) }} {{ Str::plural('tool', count($agent->allowed_tools)) }}</span>
                                @endif

                                @php $reasoning = $agent->getReasoningValue(); @endphp
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
                            <form method="POST" action="{{ route('config.agents.toggle-default', $agent) }}" class="inline">
                                @csrf
                                <button type="submit" class="p-2 rounded hover:bg-gray-700 transition-colors {{ $agent->is_default ? 'text-blue-400' : 'text-gray-500 hover:text-gray-300' }}" title="{{ $agent->is_default ? 'Remove as default' : 'Set as default' }}">
                                    <svg class="w-5 h-5" fill="{{ $agent->is_default ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                    </svg>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('config.agents.toggle-enabled', $agent) }}" class="inline">
                                @csrf
                                <button type="submit" class="p-2 rounded hover:bg-gray-700 transition-colors {{ $agent->enabled ? 'text-green-400' : 'text-gray-500 hover:text-gray-300' }}" title="{{ $agent->enabled ? 'Disable agent' : 'Enable agent' }}">
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
                            <a href="{{ route('config.agents.edit', $agent) }}" class="p-2 rounded hover:bg-gray-700 transition-colors text-gray-400 hover:text-white" title="Edit agent">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </a>
                            <form method="POST" action="{{ route('config.agents.delete', $agent) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="p-2 rounded hover:bg-gray-700 transition-colors text-gray-400 hover:text-red-400" title="Delete agent" onclick="return confirm('Are you sure you want to delete this agent?')">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
