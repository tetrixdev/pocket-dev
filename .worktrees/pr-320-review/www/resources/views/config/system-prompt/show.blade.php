@extends('layouts.config')

@section('title', 'System Prompt')

@section('content')
<div x-data="{
    showCoreSection: false,
    showCoreEditWarning: false,
    showCoreResetWarning: false,
    showAdditionalResetWarning: false,
    showPreviewSection: false,
    previewTab: 'claude_code'
}">
    <div class="mb-6">
        <h2 class="text-xl font-semibold mb-2">System Prompt</h2>
        <p class="text-gray-400 text-sm">
            The system prompt defines how the AI assistant behaves. It consists of a <strong>core prompt</strong> (base instructions) and an <strong>additional prompt</strong> (project-specific customizations).
        </p>
    </div>

    {{-- Additional System Prompt (Primary - Always Visible) --}}
    <div class="mb-8">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="text-lg font-medium">Additional Instructions</h3>
                <p class="text-gray-400 text-sm">
                    Project-specific instructions appended to the core prompt.
                    @if($isAdditionalOverridden)
                        <span class="text-yellow-400">(Custom override)</span>
                    @elseif($hasAdditionalDefault)
                        <span class="text-blue-400">(Using default from config)</span>
                    @else
                        <span class="text-gray-500">(Empty)</span>
                    @endif
                </p>
            </div>
            <div class="flex gap-2">
                <x-button variant="primary" size="sm" onclick="window.location.href='{{ route('config.system-prompt.additional.edit') }}'">
                    Edit
                </x-button>
                @if($isAdditionalOverridden)
                    <x-button variant="secondary" size="sm" @click="showAdditionalResetWarning = true">
                        Reset
                    </x-button>
                @endif
            </div>
        </div>

        @if(!empty($additionalContent))
            <div class="config-editor w-full" style="white-space: pre-wrap; min-height: 150px; max-height: 400px; overflow-y: auto; cursor: default;">{{ $additionalContent }}</div>
        @else
            <div class="config-editor w-full text-gray-500 italic" style="min-height: 80px; cursor: default;">
                No additional instructions configured. Click "Edit" to add project-specific instructions.
            </div>
        @endif
    </div>

    {{-- Core System Prompt (Collapsible) --}}
    <div class="border-t border-gray-700 pt-6">
        <button
            @click="showCoreSection = !showCoreSection"
            class="flex items-center justify-between w-full text-left mb-3 hover:text-gray-300 transition-colors"
        >
            <div>
                <h3 class="text-lg font-medium">Core System Prompt</h3>
                <p class="text-gray-400 text-sm">
                    Base AI instructions. Rarely needs modification.
                    @if($isCoreOverridden)
                        <span class="text-yellow-400">(Custom override)</span>
                    @else
                        <span class="text-green-400">(Using default)</span>
                    @endif
                </p>
            </div>
            <svg
                class="w-5 h-5 text-gray-400 transition-transform"
                :class="{ 'rotate-180': showCoreSection }"
                fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div x-show="showCoreSection" x-collapse>
            <div class="flex items-start gap-3 p-3 bg-yellow-900/20 border border-yellow-800 rounded-lg mb-4">
                <svg class="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <p class="text-yellow-200 text-sm">
                    The core prompt defines fundamental AI behavior. Only modify if you understand how system prompts work.
                </p>
            </div>

            <div class="config-editor w-full mb-4" style="white-space: pre-wrap; min-height: 200px; max-height: 400px; overflow-y: auto; cursor: default;">{{ $coreContent }}</div>

            <div class="flex gap-2">
                <x-button variant="secondary" size="sm" @click="showCoreEditWarning = true">
                    Edit Core Prompt
                </x-button>
                @if($isCoreOverridden)
                    <x-button variant="danger" size="sm" @click="showCoreResetWarning = true">
                        Reset to Default
                    </x-button>
                @endif
            </div>
        </div>
    </div>

    {{-- Full Prompt Preview (Collapsible) --}}
    <div class="border-t border-gray-700 pt-6 mt-6">
        <button
            @click="showPreviewSection = !showPreviewSection"
            class="flex items-center justify-between w-full text-left mb-3 hover:text-gray-300 transition-colors"
        >
            <div>
                <h3 class="text-lg font-medium">Full System Prompt Preview</h3>
                <p class="text-gray-400 text-sm">
                    See the complete prompt including dynamically generated content.
                </p>
            </div>
            <svg
                class="w-5 h-5 text-gray-400 transition-transform"
                :class="{ 'rotate-180': showPreviewSection }"
                fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div x-show="showPreviewSection" x-collapse>
            {{-- Info bar explaining dynamic content --}}
            <div class="flex items-start gap-3 p-3 bg-blue-900/20 border border-blue-800 rounded-lg mb-4">
                <svg class="w-5 h-5 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div class="text-blue-200 text-sm">
                    <p class="mb-2">
                        <strong>This preview shows the complete prompt</strong> sent to the AI, which includes:
                    </p>
                    <ul class="list-disc ml-4 space-y-1">
                        <li><strong>Core Prompt</strong> - Base AI instructions (editable above)</li>
                        <li><strong>Additional Instructions</strong> - Your project-specific customizations (editable above)</li>
                        <li><strong>PocketDev Tools</strong> - Dynamically generated from enabled tools and memory structures</li>
                    </ul>
                    <p class="mt-2 text-blue-300/80">
                        The "PocketDev Tools" section is auto-generated and updates when you add/remove tools or memory structures.
                    </p>
                </div>
            </div>

            {{-- Provider tabs --}}
            <div class="flex border-b border-gray-700 mb-4">
                <button
                    @click="previewTab = 'claude_code'"
                    :class="previewTab === 'claude_code' ? 'border-blue-500 text-blue-400' : 'border-transparent text-gray-400 hover:text-gray-300'"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px"
                >
                    Claude Code
                </button>
                <button
                    @click="previewTab = 'other'"
                    :class="previewTab === 'other' ? 'border-blue-500 text-blue-400' : 'border-transparent text-gray-400 hover:text-gray-300'"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px"
                >
                    Anthropic / OpenAI
                </button>
            </div>

            {{-- Tab content --}}
            <div x-show="previewTab === 'claude_code'" class="space-y-4">
                <div class="text-xs text-gray-500 mb-2">
                    For Claude Code, tools are injected via <code class="bg-gray-800 px-1 rounded">--append-system-prompt</code> as artisan commands.
                </div>

                {{-- Core + Additional --}}
                <div class="config-editor w-full" style="white-space: pre-wrap; max-height: 300px; overflow-y: auto; cursor: default;">{{ $coreContent }}</div>

                @if(!empty($additionalContent))
                    <div class="config-editor w-full" style="white-space: pre-wrap; max-height: 200px; overflow-y: auto; cursor: default;">{{ $additionalContent }}</div>
                @endif

                {{-- Dynamic PocketDev Tools section --}}
                @if(!empty($claudeCodeDynamicContent))
                    <div class="relative">
                        <div class="absolute -top-2 left-3 px-2 bg-gray-900 text-xs text-blue-400 font-medium">
                            Dynamically Generated
                        </div>
                        <div class="config-editor w-full border-blue-800 border-2" style="white-space: pre-wrap; max-height: 400px; overflow-y: auto; cursor: default;">{{ $claudeCodeDynamicContent }}</div>
                    </div>
                @else
                    <div class="config-editor w-full text-gray-500 italic" style="cursor: default;">
                        No PocketDev tools or memory structures configured.
                    </div>
                @endif
            </div>

            <div x-show="previewTab === 'other'" class="space-y-4">
                <div class="text-xs text-gray-500 mb-2">
                    For Anthropic/OpenAI, tools are registered as native API tools. Tool names are used instead of artisan commands.
                </div>

                {{-- Core + Additional --}}
                <div class="config-editor w-full" style="white-space: pre-wrap; max-height: 300px; overflow-y: auto; cursor: default;">{{ $coreContent }}</div>

                @if(!empty($additionalContent))
                    <div class="config-editor w-full" style="white-space: pre-wrap; max-height: 200px; overflow-y: auto; cursor: default;">{{ $additionalContent }}</div>
                @endif

                {{-- Dynamic PocketDev Tools section --}}
                @if(!empty($otherProviderDynamicContent))
                    <div class="relative">
                        <div class="absolute -top-2 left-3 px-2 bg-gray-900 text-xs text-blue-400 font-medium">
                            Dynamically Generated
                        </div>
                        <div class="config-editor w-full border-blue-800 border-2" style="white-space: pre-wrap; max-height: 400px; overflow-y: auto; cursor: default;">{{ $otherProviderDynamicContent }}</div>
                    </div>
                @else
                    <div class="config-editor w-full text-gray-500 italic" style="cursor: default;">
                        No PocketDev tools or memory structures configured.
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Core Edit Warning Modal --}}
    <x-modal show="showCoreEditWarning" title="Warning: Advanced Setting" max-width="lg">
        <div class="space-y-4">
            <div class="flex items-start gap-3 p-4 bg-yellow-900/30 border border-yellow-700 rounded-lg">
                <svg class="w-6 h-6 text-yellow-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div>
                    <h3 class="font-semibold text-yellow-400 mb-2">This is for experienced developers only</h3>
                    <p class="text-gray-300 text-sm">
                        The core system prompt defines the AI's fundamental behavior and capabilities.
                        Modifying it incorrectly can cause unexpected responses, broken tool usage, or other issues.
                    </p>
                </div>
            </div>
            <p class="text-gray-400 text-sm">
                For most customizations, use the "Additional Instructions" section instead.
            </p>
        </div>
        <div class="flex justify-end gap-3 mt-6">
            <x-button variant="secondary" @click="showCoreEditWarning = false">Cancel</x-button>
            <x-button variant="primary" onclick="window.location.href='{{ route('config.system-prompt.core.edit') }}'">
                I Understand, Continue
            </x-button>
        </div>
    </x-modal>

    {{-- Core Reset Warning Modal --}}
    <x-modal show="showCoreResetWarning" title="Reset Core Prompt?" max-width="lg">
        <div class="space-y-4">
            <div class="flex items-start gap-3 p-4 bg-red-900/30 border border-red-700 rounded-lg">
                <svg class="w-6 h-6 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                <div>
                    <h3 class="font-semibold text-red-400 mb-2">This action cannot be undone</h3>
                    <p class="text-gray-300 text-sm">
                        Your custom core prompt will be permanently deleted and replaced with the default.
                    </p>
                </div>
            </div>
        </div>
        <div class="flex justify-end gap-3 mt-6">
            <x-button variant="secondary" @click="showCoreResetWarning = false">Cancel</x-button>
            <form action="{{ route('config.system-prompt.core.reset') }}" method="POST" class="inline">
                @csrf
                @method('DELETE')
                <x-button type="submit" variant="danger">Reset to Default</x-button>
            </form>
        </div>
    </x-modal>

    {{-- Additional Reset Warning Modal --}}
    <x-modal show="showAdditionalResetWarning" title="Reset Additional Instructions?" max-width="lg">
        <div class="space-y-4">
            <div class="flex items-start gap-3 p-4 bg-red-900/30 border border-red-700 rounded-lg">
                <svg class="w-6 h-6 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                <div>
                    <h3 class="font-semibold text-red-400 mb-2">This action cannot be undone</h3>
                    <p class="text-gray-300 text-sm">
                        Your custom additional instructions will be permanently deleted.
                        @if($hasAdditionalDefault)
                            The default configuration will be restored.
                        @else
                            The additional instructions will be empty.
                        @endif
                    </p>
                </div>
            </div>
        </div>
        <div class="flex justify-end gap-3 mt-6">
            <x-button variant="secondary" @click="showAdditionalResetWarning = false">Cancel</x-button>
            <form action="{{ route('config.system-prompt.additional.reset') }}" method="POST" class="inline">
                @csrf
                @method('DELETE')
                <x-button type="submit" variant="danger">Reset</x-button>
            </form>
        </div>
    </x-modal>
</div>
@endsection
