@extends('layouts.config')

@section('title', 'Edit Additional Instructions')

@section('content')
<div x-data="{
    content: {{ Js::from($content) }},
    originalContent: {{ Js::from($content) }},
    hasChanges() {
        return this.content !== this.originalContent;
    }
}">
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold mb-2">Edit Additional Instructions</h2>
                <p class="text-gray-400 text-sm">
                    Add project-specific instructions that will be appended to the core system prompt.
                </p>
            </div>
            <a href="{{ route('config.system-prompt') }}" class="text-gray-400 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </a>
        </div>
    </div>

    {{-- Editor --}}
    <form action="{{ route('config.system-prompt.additional.save') }}" method="POST">
        @csrf
        <div class="mb-6">
            <label for="content" class="block text-sm font-medium mb-2">Additional Instructions</label>
            <textarea
                id="content"
                name="content"
                x-model="content"
                class="config-editor w-full"
                rows="20"
                placeholder="Enter your additional instructions here...

Examples:
- Project-specific coding conventions
- Framework guidelines (Laravel, React, etc.)
- Documentation requirements
- Git workflow preferences"
            ></textarea>
        </div>

        {{-- Action buttons --}}
        <div class="flex gap-3">
            <x-button type="submit" variant="primary">
                Save Changes
            </x-button>
            <x-button type="button" variant="secondary" onclick="window.location.href='{{ route('config.system-prompt') }}'">
                Cancel
            </x-button>
        </div>

        {{-- Unsaved changes indicator --}}
        <p x-show="hasChanges()" class="mt-3 text-yellow-400 text-sm">
            You have unsaved changes.
        </p>
    </form>
</div>
@endsection
