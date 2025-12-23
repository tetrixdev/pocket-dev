@extends('layouts.config')

@section('title', 'Tools')

@section('content')
<div x-data="toolsManager()" class="space-y-6">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold">Tools</h1>
            <p class="text-gray-400 text-sm mt-1">Native provider tools, PocketDev tools, and custom tools</p>
        </div>
        <a href="{{ route('config.tools.create') }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium text-center">
            + New Tool
        </a>
    </div>

    {{-- Native Tools: Claude Code --}}
    @if(!empty($nativeConfig['claude_code']['tools']))
        <x-tools.section
            title="Native: Claude Code"
            :subtitle="'v' . ($nativeConfig['claude_code']['version'] ?? 'unknown')"
            :expanded="true"
        >
            @foreach($nativeConfig['claude_code']['tools'] as $tool)
                <x-tools.card
                    :name="$tool['name']"
                    :description="$tool['description'] ?? null"
                    :showToggle="true"
                    :enabled="$tool['enabled'] ?? true"
                    :disabled="true"
                    provider="claude_code"
                />
            @endforeach
        </x-tools.section>
    @endif

    {{-- Native Tools: Codex --}}
    @if(!empty($nativeConfig['codex']['tools']))
        <x-tools.section
            title="Native: Codex"
            :subtitle="'v' . ($nativeConfig['codex']['version'] ?? 'unknown')"
            :expanded="true"
        >
            @foreach($nativeConfig['codex']['tools'] as $tool)
                <x-tools.card
                    :name="$tool['name']"
                    :description="$tool['description'] ?? null"
                    :showToggle="true"
                    :enabled="$tool['enabled'] ?? true"
                    :disabled="true"
                    provider="codex"
                />
            @endforeach
        </x-tools.section>
    @endif

    {{-- Native PocketDev Tools (fallback for Anthropic/OpenAI API) --}}
    @if($nativePocketdevTools->isNotEmpty())
        <x-tools.section
            title="Native: PocketDev"
            badge="Fallback for Anthropic/OpenAI API"
            :expanded="false"
        >
            @foreach($nativePocketdevTools as $tool)
                <x-tools.card
                    :name="$tool['name']"
                    :slug="$tool['slug']"
                    :description="$tool['description'] ?? null"
                    :viewUrl="route('config.tools.show', $tool['slug'])"
                />
            @endforeach
        </x-tools.section>
    @endif

    {{-- PocketDev Tools (unique - memory, tool management) --}}
    @if($pocketdevByCategory->isNotEmpty())
        <x-tools.section
            title="PocketDev Tools"
            :expanded="true"
        >
            @foreach($pocketdevByCategory as $category => $tools)
                {{-- Category header --}}
                <div class="px-4 py-2 bg-gray-900 text-sm font-medium text-gray-400 uppercase tracking-wide border-b border-gray-700">
                    {{ ucfirst(str_replace('_', ' ', $category)) }}
                </div>
                @foreach($tools as $tool)
                    <x-tools.card
                        :name="$tool['name']"
                        :slug="$tool['slug']"
                        :description="$tool['description'] ?? null"
                        :artisanCommand="$tool['artisan_command'] ?? null"
                        :viewUrl="route('config.tools.show', $tool['slug'])"
                    />
                @endforeach
            @endforeach
        </x-tools.section>
    @endif

    {{-- Custom Tools --}}
    <x-tools.section
        title="Custom Tools"
        :expanded="true"
    >
        @if($customByCategory->isEmpty())
            <div class="px-4 py-8 text-center text-gray-400">
                <p>No custom tools yet.</p>
                <p class="text-sm mt-1">
                    <a href="{{ route('config.tools.create') }}" class="text-blue-400 hover:text-blue-300">Create a tool</a>
                    to extend AI capabilities.
                </p>
            </div>
        @else
            @foreach($customByCategory as $category => $tools)
                @if($category)
                    <div class="px-4 py-2 bg-gray-900 text-sm font-medium text-gray-400 uppercase tracking-wide border-b border-gray-700">
                        {{ ucfirst(str_replace('_', ' ', $category)) }}
                    </div>
                @endif
                @foreach($tools as $tool)
                    <x-tools.card
                        :name="$tool->name"
                        :slug="$tool->slug"
                        :description="$tool->description"
                        :viewUrl="route('config.tools.show', $tool->slug)"
                        :editUrl="route('config.tools.edit', $tool->slug)"
                        :deleteUrl="route('config.tools.delete', $tool->slug)"
                    />
                @endforeach
            @endforeach
        @endif
    </x-tools.section>

</div>

<script>
function toolsManager() {
    return {
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
@endsection
