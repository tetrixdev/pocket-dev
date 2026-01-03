@extends('layouts.config')

@section('title', 'Tools: ' . $workspace->name)

@section('content')
<div x-data="workspaceTools()">
    <div class="mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('config.workspaces') }}" class="text-gray-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <h2 class="text-xl font-semibold text-white">{{ $workspace->name }}</h2>
        </div>
        <p class="text-gray-400 text-sm mt-1">Manage which tools are available in this workspace</p>
    </div>

    <div class="bg-gray-800 rounded border border-gray-700 p-4 mb-6">
        <p class="text-sm text-gray-400">
            Tools enabled here will be available to all agents in this workspace.
            Individual agents can further restrict their allowed tools.
            <strong class="text-gray-300">Tools are enabled by default</strong> - only disable tools you want to restrict.
        </p>
    </div>

    @php
        $groupedTools = $tools->groupBy('category');
        $categoryTitles = [
            'memory' => 'Memory System',
            'tools' => 'Tool Management',
            'file_ops' => 'File Operations',
            'custom' => 'Custom Tools',
        ];
        $categoryOrder = ['memory', 'tools', 'file_ops', 'custom'];
    @endphp

    <div class="space-y-6">
        @foreach($categoryOrder as $category)
            @if($groupedTools->has($category))
                <div>
                    <h3 class="text-lg font-semibold text-white mb-3">
                        {{ $categoryTitles[$category] ?? ucfirst($category) }}
                        <span class="text-sm text-gray-400 font-normal">({{ $groupedTools[$category]->count() }})</span>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($groupedTools[$category] as $tool)
                            @php
                                $isDisabled = in_array($tool->getSlug(), $disabledSlugs);
                                $isEnabled = !$isDisabled;
                            @endphp
                            <div
                                class="p-3 bg-gray-800 border rounded transition-colors"
                                :class="isToolEnabled('{{ $tool->getSlug() }}') ? 'border-gray-700' : 'border-gray-700 opacity-60'"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-white text-sm">{{ $tool->name }}</span>
                                            <span class="text-xs text-gray-500 font-mono">{{ $tool->getSlug() }}</span>
                                        </div>
                                        <p class="text-xs text-gray-400 mt-1">{{ Str::limit($tool->description, 100) }}</p>
                                    </div>

                                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                        <input
                                            type="checkbox"
                                            class="sr-only peer"
                                            {{ $isEnabled ? 'checked' : '' }}
                                            @change="toggleTool('{{ $tool->getSlug() }}', $event.target.checked)"
                                        >
                                        <div class="w-9 h-5 bg-gray-700 peer-focus:ring-2 peer-focus:ring-blue-500 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-500"></div>
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach

        @foreach($groupedTools as $category => $categoryTools)
            @if(!in_array($category, $categoryOrder))
                <div>
                    <h3 class="text-lg font-semibold text-white mb-3">
                        {{ ucfirst(str_replace('_', ' ', $category)) }}
                        <span class="text-sm text-gray-400 font-normal">({{ $categoryTools->count() }})</span>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($categoryTools as $tool)
                            @php
                                $isDisabled = in_array($tool->getSlug(), $disabledSlugs);
                                $isEnabled = !$isDisabled;
                            @endphp
                            <div
                                class="p-3 bg-gray-800 border rounded transition-colors"
                                :class="isToolEnabled('{{ $tool->getSlug() }}') ? 'border-gray-700' : 'border-gray-700 opacity-60'"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-white text-sm">{{ $tool->name }}</span>
                                            <span class="text-xs text-gray-500 font-mono">{{ $tool->getSlug() }}</span>
                                        </div>
                                        <p class="text-xs text-gray-400 mt-1">{{ Str::limit($tool->description, 100) }}</p>
                                    </div>

                                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                        <input
                                            type="checkbox"
                                            class="sr-only peer"
                                            {{ $isEnabled ? 'checked' : '' }}
                                            @change="toggleTool('{{ $tool->getSlug() }}', $event.target.checked)"
                                        >
                                        <div class="w-9 h-5 bg-gray-700 peer-focus:ring-2 peer-focus:ring-blue-500 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-500"></div>
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <!-- Status message -->
    <div
        x-show="message"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 transform translate-y-2"
        x-transition:enter-end="opacity-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed bottom-4 right-4 px-4 py-2 rounded shadow-lg text-sm"
        :class="messageType === 'success' ? 'bg-green-800 text-green-200' : 'bg-red-800 text-red-200'"
        x-text="message"
    ></div>
</div>
@endsection

@push('scripts')
<script>
    function workspaceTools() {
        return {
            disabledSlugs: @js($disabledSlugs),
            message: '',
            messageType: 'success',

            isToolEnabled(slug) {
                return !this.disabledSlugs.includes(slug);
            },

            async toggleTool(slug, enabled) {
                try {
                    const csrfToken = document.querySelector('meta[name=csrf-token]');
                    const response = await fetch('{{ route("config.workspaces.tools.toggle", $workspace) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken ? csrfToken.content : '',
                        },
                        body: JSON.stringify({
                            tool_slug: slug,
                            enabled: enabled,
                        }),
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Update local state
                        if (enabled) {
                            this.disabledSlugs = this.disabledSlugs.filter(s => s !== slug);
                        } else {
                            if (!this.disabledSlugs.includes(slug)) {
                                this.disabledSlugs.push(slug);
                            }
                        }
                        this.showMessage(data.message, 'success');
                    } else {
                        this.showMessage(data.message || 'Failed to toggle tool', 'error');
                    }
                } catch (error) {
                    console.error('Failed to toggle tool:', error);
                    this.showMessage('Failed to toggle tool', 'error');
                }
            },

            showMessage(text, type = 'success') {
                this.message = text;
                this.messageType = type;
                setTimeout(() => {
                    this.message = '';
                }, 2000);
            }
        };
    }
</script>
@endpush
