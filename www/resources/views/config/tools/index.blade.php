@extends('layouts.config')

@section('title', 'Tools')

@section('content')
<div x-data="toolsManager()" class="space-y-8">

    {{-- Header --}}
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold">Tools</h1>
            <p class="text-gray-400 text-sm mt-1">Manage native provider tools, PocketDev tools, and custom tools</p>
        </div>
        <a href="{{ route('config.tools.create') }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium">
            + New Tool
        </a>
    </div>

    {{-- Native Tools Section --}}
    @foreach($nativeConfig as $provider => $providerConfig)
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <div class="px-4 py-3 bg-gray-750 border-b border-gray-700 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-lg font-semibold">
                        @if($provider === 'claude_code')
                            Native: Claude Code
                        @elseif($provider === 'codex')
                            Native: Codex
                        @else
                            Native: {{ ucfirst($provider) }}
                        @endif
                    </span>
                    <span class="text-xs text-gray-500">v{{ $providerConfig['version'] ?? 'unknown' }}</span>
                </div>
                <button
                    @click="toggleSection('native-{{ $provider }}')"
                    class="text-gray-400 hover:text-white"
                >
                    <svg x-show="!expandedSections['native-{{ $provider }}']" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                    <svg x-show="expandedSections['native-{{ $provider }}']" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                    </svg>
                </button>
            </div>

            <div x-show="expandedSections['native-{{ $provider }}']" x-collapse>
                <div class="divide-y divide-gray-700">
                    @foreach($providerConfig['tools'] ?? [] as $tool)
                        <div class="px-4 py-3 flex items-center justify-between hover:bg-gray-750">
                            <div class="flex items-center gap-3">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input
                                        type="checkbox"
                                        class="sr-only peer"
                                        {{ $tool['enabled'] ? 'checked' : '' }}
                                        @change="toggleNativeTool('{{ $provider }}', '{{ $tool['name'] }}', $event.target.checked)"
                                    >
                                    <div class="w-9 h-5 bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                                <div>
                                    <span class="font-medium {{ !$tool['enabled'] ? 'text-gray-500' : '' }}">{{ $tool['name'] }}</span>
                                </div>
                            </div>
                            <div class="text-sm text-gray-400 max-w-md truncate">
                                {{ $tool['description'] ?? '' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

    {{-- Native PocketDev Tools (file ops with native equivalents) --}}
    @if($nativePocketdevTools->isNotEmpty())
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <div class="px-4 py-3 bg-gray-750 border-b border-gray-700 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-lg font-semibold">Native: PocketDev</span>
                    <span class="text-xs text-gray-500 bg-gray-700 px-2 py-0.5 rounded">Fallback for Anthropic/OpenAI API</span>
                </div>
                <button
                    @click="toggleSection('native-pocketdev')"
                    class="text-gray-400 hover:text-white"
                >
                    <svg x-show="!expandedSections['native-pocketdev']" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                    <svg x-show="expandedSections['native-pocketdev']" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                    </svg>
                </button>
            </div>

            <div x-show="expandedSections['native-pocketdev']" x-collapse>
                <div class="divide-y divide-gray-700">
                    @foreach($nativePocketdevTools as $tool)
                        <div class="px-4 py-3 flex items-center justify-between hover:bg-gray-750">
                            <div class="flex items-center gap-3">
                                <span class="font-medium">{{ $tool->name }}</span>
                                <span class="text-xs text-gray-500">{{ $tool->slug }}</span>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="text-sm text-gray-400 max-w-md truncate">{{ $tool->description }}</span>
                                <a href="{{ route('config.tools.show', $tool->slug) }}" class="text-blue-400 hover:text-blue-300 text-sm">
                                    View
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- PocketDev Tools (unique - no native equivalents) --}}
    @if($pocketdevByCategory->isNotEmpty())
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <div class="px-4 py-3 bg-gray-750 border-b border-gray-700 flex items-center justify-between">
                <span class="text-lg font-semibold">PocketDev Tools</span>
                <button
                    @click="toggleSection('pocketdev')"
                    class="text-gray-400 hover:text-white"
                >
                    <svg x-show="!expandedSections['pocketdev']" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                    <svg x-show="expandedSections['pocketdev']" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                    </svg>
                </button>
            </div>

            <div x-show="expandedSections['pocketdev']" x-collapse>
                @foreach($pocketdevByCategory as $category => $tools)
                    <div class="border-b border-gray-700 last:border-b-0">
                        <div class="px-4 py-2 bg-gray-900 text-sm font-medium text-gray-400 uppercase tracking-wide">
                            {{ ucfirst(str_replace('_', ' ', $category)) }}
                        </div>
                        <div class="divide-y divide-gray-700">
                            @foreach($tools as $tool)
                                <div class="px-4 py-3 flex items-center justify-between hover:bg-gray-750">
                                    <div class="flex items-center gap-3">
                                        <span class="font-medium">{{ $tool->name }}</span>
                                        <span class="text-xs text-gray-500">{{ $tool->slug }}</span>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <span class="text-sm text-gray-400 max-w-md truncate">{{ $tool->description }}</span>
                                        <a href="{{ route('config.tools.show', $tool->slug) }}" class="text-blue-400 hover:text-blue-300 text-sm">
                                            View
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Custom Tools --}}
    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
        <div class="px-4 py-3 bg-gray-750 border-b border-gray-700 flex items-center justify-between">
            <span class="text-lg font-semibold">Custom Tools</span>
            <div class="flex items-center gap-3">
                <a href="{{ route('config.tools.create') }}" class="text-blue-400 hover:text-blue-300 text-sm">
                    + New Tool
                </a>
                <button
                    @click="toggleSection('custom')"
                    class="text-gray-400 hover:text-white"
                >
                    <svg x-show="!expandedSections['custom']" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                    <svg x-show="expandedSections['custom']" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                    </svg>
                </button>
            </div>
        </div>

        <div x-show="expandedSections['custom']" x-collapse>
            @if($customByCategory->isEmpty())
                <div class="px-4 py-8 text-center text-gray-400">
                    <p>No custom tools yet.</p>
                    <p class="text-sm mt-1">Create a tool to extend AI capabilities.</p>
                </div>
            @else
                @foreach($customByCategory as $category => $tools)
                    <div class="border-b border-gray-700 last:border-b-0">
                        @if($category)
                            <div class="px-4 py-2 bg-gray-900 text-sm font-medium text-gray-400 uppercase tracking-wide">
                                {{ ucfirst(str_replace('_', ' ', $category)) }}
                            </div>
                        @endif
                        <div class="divide-y divide-gray-700">
                            @foreach($tools as $tool)
                                <div class="px-4 py-3 flex items-center justify-between hover:bg-gray-750">
                                    <div class="flex items-center gap-3">
                                        <span class="font-medium">{{ $tool->name }}</span>
                                        <span class="text-xs text-gray-500">{{ $tool->slug }}</span>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <span class="text-sm text-gray-400 max-w-sm truncate">{{ $tool->description }}</span>
                                        <a href="{{ route('config.tools.show', $tool->slug) }}" class="text-blue-400 hover:text-blue-300 text-sm">
                                            View
                                        </a>
                                        <a href="{{ route('config.tools.edit', $tool->slug) }}" class="text-green-400 hover:text-green-300 text-sm">
                                            Edit
                                        </a>
                                        <form method="POST" action="{{ route('config.tools.delete', $tool->slug) }}" class="inline" onsubmit="return confirm('Delete this tool?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-400 hover:text-red-300 text-sm">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>

</div>

<script>
function toolsManager() {
    return {
        expandedSections: {
            'native-claude_code': true,
            'native-codex': true,
            'native-pocketdev': false,
            'pocketdev': true,
            'custom': true,
        },

        toggleSection(section) {
            this.expandedSections[section] = !this.expandedSections[section];
        },

        async toggleNativeTool(provider, toolName, enabled) {
            try {
                const response = await fetch('{{ route("config.tools.native.toggle") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({
                        provider: provider,
                        tool: toolName,
                        enabled: enabled,
                    }),
                });

                const data = await response.json();

                if (!data.success) {
                    alert('Failed to toggle tool: ' + data.message);
                    // Revert the checkbox
                    location.reload();
                }
            } catch (error) {
                alert('Failed to toggle tool: ' + error.message);
                location.reload();
            }
        }
    };
}
</script>

<style>
    .bg-gray-750 {
        background-color: rgb(42, 48, 60);
    }
</style>
@endsection
