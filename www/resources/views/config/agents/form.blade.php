@extends('layouts.config')

@section('title', isset($agent) ? 'Edit Agent: ' . $agent['name'] : 'Create Agent')

@section('content')
<form method="POST" action="{{ isset($agent) ? route('config.agents.update', $agent['filename']) : route('config.agents.store') }}">
    @csrf
    @if(isset($agent))
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left column: Agent metadata -->
        <div class="lg:col-span-1">
            <div class="bg-gray-800 p-4 rounded border border-gray-700">
                <h2 class="text-xl font-semibold mb-4">Agent Metadata</h2>

                <!-- Name field -->
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium mb-2">
                        Name <span class="text-red-500 font-bold">*</span>
                    </label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name', $agent['name'] ?? '') }}"
                        class="w-full px-3 py-2 {{ isset($agent) ? 'bg-gray-600 text-gray-300 cursor-not-allowed' : 'bg-gray-700 text-white' }} border border-gray-600 rounded"
                        pattern="[a-z0-9-]+"
                        placeholder="my-agent-name"
                        {{ isset($agent) ? 'disabled' : 'required' }}
                    >
                    <p class="text-xs text-gray-500 mt-1">
                        Lowercase letters, numbers, and hyphens only. This becomes the agent's filename.
                        @if(isset($agent))
                            <span class="text-gray-400">(Cannot be changed)</span>
                        @endif
                    </p>
                </div>

                <!-- Description field -->
                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium mb-2">
                        Description <span class="text-red-500 font-bold">*</span>
                    </label>
                    <textarea
                        id="description"
                        name="description"
                        rows="3"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                        placeholder="Describe what this agent does..."
                        required
                    >{{ old('description', $agent['description'] ?? '') }}</textarea>
                    <p class="text-xs text-gray-500 mt-1">Shown in the Task tool's agent list. Claude uses it to decide when to spawn this agent.</p>
                </div>

                <!-- Tools field -->
                <div class="mb-4">
                    <label for="tools" class="block text-sm font-medium mb-2">Tools</label>
                    <input
                        type="text"
                        id="tools"
                        name="tools"
                        value="{{ old('tools', $agent['tools'] ?? '') }}"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                        placeholder="Read, Edit, Bash"
                    >
                    <p class="text-xs text-gray-500 mt-1">Comma-separated list of tools. Leave empty to allow all tools.</p>
                </div>

                <!-- Model field -->
                <div class="mb-4">
                    <label for="model" class="block text-sm font-medium mb-2">Model</label>
                    <select
                        id="model"
                        name="model"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded"
                    >
                        <option value="" {{ old('model', $agent['model'] ?? '') == '' ? 'selected' : '' }}>Inherit from conversation</option>
                        <option value="claude-sonnet-4-5-20250929" {{ old('model', $agent['model'] ?? '') == 'claude-sonnet-4-5-20250929' ? 'selected' : '' }}>Claude Sonnet 4.5</option>
                        <option value="claude-opus-4-20250514" {{ old('model', $agent['model'] ?? '') == 'claude-opus-4-20250514' ? 'selected' : '' }}>Claude Opus 4</option>
                        <option value="claude-opus-4-5-20251101" {{ old('model', $agent['model'] ?? '') == 'claude-opus-4-5-20251101' ? 'selected' : '' }}>Claude Opus 4.5</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Model for this agent. Leave as "Inherit" to use the conversation's model.</p>
                </div>

                <!-- Action buttons -->
                <div class="space-y-2 pt-2 border-t border-gray-700">
                    <button
                        type="submit"
                        class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium"
                    >
                        {{ isset($agent) ? 'Save Agent' : 'Create Agent' }}
                    </button>

                    <a
                        href="{{ route('config.agents') }}"
                        class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded font-medium text-center"
                    >
                        Cancel
                    </a>

                    @if(isset($agent))
                        <button
                            type="button"
                            onclick="if(confirm('Are you sure you want to delete this agent?')) { document.getElementById('delete-form').submit(); }"
                            class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-medium"
                        >
                            Delete Agent
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <!-- Right column: System Prompt editor -->
        <div class="lg:col-span-2">
            <div class="bg-gray-800 p-4 rounded border border-gray-700">
                <h2 class="text-xl font-semibold mb-2">
                    System Prompt <span class="text-red-500 font-bold">*</span>
                </h2>
                <p class="text-sm text-gray-400 mb-4">
                    Instructions for the agent. This becomes the agent's system prompt when spawned via the Task tool.
                </p>

                <textarea
                    id="systemPrompt"
                    name="systemPrompt"
                    class="config-editor w-full"
                    placeholder="You are an agent specialized in...

## Your Role
Describe what this agent should do.

## Guidelines
- Be specific about the agent's behavior
- Include any constraints or rules
- Mention what tools to prefer"
                    required
                >{{ old('systemPrompt', $agent['systemPrompt'] ?? '') }}</textarea>
            </div>
        </div>
    </div>
</form>

@if(isset($agent))
    <form id="delete-form" method="POST" action="{{ route('config.agents.delete', $agent['filename']) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endif
@endsection
