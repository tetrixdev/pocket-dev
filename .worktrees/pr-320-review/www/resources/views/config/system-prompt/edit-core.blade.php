@extends('layouts.config')

@section('title', 'Edit Core System Prompt')

@section('content')
<div x-data="{
    showSaveWarning: false,
    content: {{ Js::from($content) }},
    originalContent: {{ Js::from($content) }},
    hasChanges() {
        return this.content !== this.originalContent;
    }
}">
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold mb-2">Edit Core System Prompt</h2>
                <p class="text-gray-400 text-sm">
                    Modify the fundamental AI instructions. Use with caution.
                </p>
            </div>
            <a href="{{ route('config.system-prompt') }}" class="text-gray-400 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </a>
        </div>
    </div>

    {{-- Warning banner --}}
    <div class="flex items-start gap-3 p-4 bg-yellow-900/30 border border-yellow-700 rounded-lg mb-6">
        <svg class="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <div>
            <p class="text-yellow-200 text-sm">
                <strong>Warning:</strong> Changes to the core prompt affect all future conversations.
                For project-specific customizations, use the "Additional Instructions" section instead.
            </p>
        </div>
    </div>

    {{-- Editor --}}
    <div class="mb-6">
        <label for="content" class="block text-sm font-medium mb-2">Core System Prompt</label>
        <textarea
            id="content"
            x-model="content"
            class="config-editor w-full"
            rows="20"
            placeholder="Enter the core system prompt..."
        ></textarea>
    </div>

    {{-- Action buttons --}}
    <div class="flex gap-3">
        <x-button
            variant="primary"
            @click="showSaveWarning = true"
            x-bind:disabled="!hasChanges()"
            x-bind:class="{ 'opacity-50 cursor-not-allowed': !hasChanges() }"
        >
            Save Changes
        </x-button>

        <x-button variant="secondary" onclick="window.location.href='{{ route('config.system-prompt') }}'">
            Cancel
        </x-button>
    </div>

    {{-- Unsaved changes indicator --}}
    <p x-show="hasChanges()" class="mt-3 text-yellow-400 text-sm">
        You have unsaved changes.
    </p>

    {{-- Save Warning Modal --}}
    <x-modal show="showSaveWarning" title="Save Core System Prompt?" max-width="lg">
        <div class="space-y-4">
            <div class="flex items-start gap-3 p-4 bg-yellow-900/30 border border-yellow-700 rounded-lg">
                <svg class="w-6 h-6 text-yellow-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div>
                    <h3 class="font-semibold text-yellow-400 mb-2">Confirm your changes</h3>
                    <p class="text-gray-300 text-sm">
                        You are about to save a custom core system prompt. This will override the default
                        prompt for all new conversations.
                    </p>
                </div>
            </div>

            <p class="text-gray-400 text-sm">
                Make sure you've tested your changes and understand how they will affect the AI's behavior.
            </p>
        </div>

        <div class="flex justify-end gap-3 mt-6">
            <x-button variant="secondary" @click="showSaveWarning = false">
                Cancel
            </x-button>
            <form action="{{ route('config.system-prompt.core.save') }}" method="POST" class="inline">
                @csrf
                <input type="hidden" name="content" x-bind:value="content">
                <x-button type="submit" variant="primary">
                    Save Core Prompt
                </x-button>
            </form>
        </div>
    </x-modal>
</div>
@endsection
