@extends('layouts.config')

@section('title', isset($agent) ? 'Edit Agent: ' . $agent->name : 'Create Agent')

@section('content')
<div x-data="agentForm()" x-init="init()">
    {{-- Clone Warnings --}}
    @if(isset($cloneWarnings) && $cloneWarnings)
        <div class="p-4 bg-yellow-900/30 border border-yellow-600 rounded-lg mb-6">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-yellow-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div class="flex-1">
                    <h4 class="font-semibold text-yellow-200">Some resources are not available in this workspace</h4>
                    <p class="text-sm text-yellow-300/80 mt-1">
                        The following items from "{{ $sourceAgent->name }}" ({{ $cloneWarnings['source_workspace'] }}) are not enabled in this workspace and will not be included:
                    </p>
                    <ul class="mt-2 text-sm text-yellow-200 list-disc list-inside space-y-1">
                        @if(!empty($cloneWarnings['missing_tools']))
                            <li>
                                <strong>Tools:</strong> {{ implode(', ', $cloneWarnings['missing_tools']) }}
                            </li>
                        @endif
                        @if(!empty($cloneWarnings['missing_schemas']))
                            <li>
                                <strong>Memory Schemas:</strong>
                                {{ implode(', ', array_column($cloneWarnings['missing_schemas'], 'name')) }}
                            </li>
                        @endif
                    </ul>
                    <p class="text-sm text-yellow-400/70 mt-2">
                        To include these, first add them to the <a href="{{ route('config.workspaces.edit', $selectedWorkspaceId) }}" class="underline hover:text-yellow-300">workspace settings</a>.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Source Agent Info --}}
    @if(isset($sourceAgent) && $sourceAgent)
        <div class="p-3 bg-blue-900/30 border border-blue-600 rounded-lg mb-6">
            <div class="flex items-center gap-2 text-blue-200 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <span>Creating copy of <strong>{{ $sourceAgent->name }}</strong></span>
            </div>
        </div>
    @endif

    <form
        method="POST"
        action="{{ isset($agent) ? route('config.agents.update', $agent) : route('config.agents.store') }}"
    >
        @csrf
        @if(isset($agent))
            @method('PUT')
        @endif

        {{-- Workspace (required for new agents, shown as info for existing) --}}
        @if(!isset($agent))
            <div class="mb-6">
                <label for="workspace_id" class="block text-sm font-medium mb-2">Workspace <span class="text-red-400 font-bold">*</span></label>
                <select
                    id="workspace_id"
                    name="workspace_id"
                    class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    required
                >
                    @foreach($workspaces as $ws)
                        <option value="{{ $ws->id }}" {{ ($selectedWorkspaceId ?? '') === $ws->id ? 'selected' : '' }}>
                            {{ $ws->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @else
            <div class="mb-6 p-3 bg-gray-800 border border-gray-700 rounded">
                <span class="text-sm text-gray-400">Workspace:</span>
                <span class="text-white font-medium ml-2">{{ $agent->workspace?->name ?? 'None' }}</span>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Left Column: Basic Info -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">Basic Information</h3>

                <!-- Name -->
                <div>
                    <label for="name" class="block text-sm font-medium mb-2">Name <span class="text-red-400 font-bold">*</span></label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name', $agent->name ?? (isset($sourceAgent) ? $sourceAgent->name . ' (Copy)' : '')) }}"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        required
                        placeholder="My Custom Agent"
                    >
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium mb-2">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        rows="2"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="Describe what this agent is for..."
                    >{{ old('description', $agent->description ?? ($sourceAgent->description ?? '')) }}</textarea>
                </div>

                <!-- Provider -->
                <div>
                    <label for="provider" class="block text-sm font-medium mb-2">Provider <span class="text-red-400 font-bold">*</span></label>
                    <select
                        id="provider"
                        name="provider"
                        x-model="provider"
                        @change="onProviderChange()"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        required
                    >
                        @foreach($providers as $p)
                            <option value="{{ $p }}">
                                {{ match($p) {
                                    'anthropic' => 'Anthropic',
                                    'openai' => 'OpenAI',
                                    'claude_code' => 'Claude Code',
                                    default => ucfirst($p)
                                } }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Model (dynamic based on provider) -->
                <div>
                    <label for="model" class="block text-sm font-medium mb-2">Model <span class="text-red-400 font-bold">*</span></label>
                    <select
                        id="model"
                        name="model"
                        x-model="model"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        required
                    >
                        <template x-for="m in availableModels" :key="m">
                            <option :value="m" x-text="m" :selected="m === model"></option>
                        </template>
                    </select>
                </div>

                <!-- Enabled & Default -->
                <div class="flex gap-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            name="enabled"
                            value="1"
                            {{ old('enabled', $agent->enabled ?? ($sourceAgent->enabled ?? true)) ? 'checked' : '' }}
                            class="w-4 h-4 rounded border-gray-700 bg-gray-800 text-blue-500 focus:ring-blue-500"
                        >
                        <span class="text-sm">Enabled</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            name="is_default"
                            value="1"
                            {{ old('is_default', $agent->is_default ?? false) ? 'checked' : '' }}
                            class="w-4 h-4 rounded border-gray-700 bg-gray-800 text-blue-500 focus:ring-blue-500"
                        >
                        <span class="text-sm">Default for provider</span>
                    </label>
                </div>
            </div>

            <!-- Right Column: Reasoning Settings -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">Reasoning Settings</h3>

                <!-- Anthropic Thinking Budget -->
                <div x-show="provider === 'anthropic'" x-cloak>
                    <label for="anthropic_thinking_budget" class="block text-sm font-medium mb-2">
                        Thinking Budget (tokens)
                        <span class="text-gray-400 text-xs ml-1">0 = disabled</span>
                    </label>
                    <input
                        type="number"
                        id="anthropic_thinking_budget"
                        name="anthropic_thinking_budget"
                        value="{{ old('anthropic_thinking_budget', $agent->anthropic_thinking_budget ?? ($sourceAgent->anthropic_thinking_budget ?? 0)) }}"
                        min="0"
                        step="1000"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                    <p class="text-xs text-gray-400 mt-1">Extended thinking for complex tasks (recommended: 8000-32000)</p>
                </div>

                <!-- OpenAI Reasoning Effort -->
                <div x-show="provider === 'openai'" x-cloak>
                    <label for="openai_reasoning_effort" class="block text-sm font-medium mb-2">Reasoning Effort</label>
                    <select
                        id="openai_reasoning_effort"
                        name="openai_reasoning_effort"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                        @php $currentEffort = old('openai_reasoning_effort', $agent->openai_reasoning_effort ?? ($sourceAgent->openai_reasoning_effort ?? 'none')); @endphp
                        <option value="none" {{ $currentEffort === 'none' ? 'selected' : '' }}>None</option>
                        <option value="low" {{ $currentEffort === 'low' ? 'selected' : '' }}>Low</option>
                        <option value="medium" {{ $currentEffort === 'medium' ? 'selected' : '' }}>Medium</option>
                        <option value="high" {{ $currentEffort === 'high' ? 'selected' : '' }}>High</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Controls reasoning depth for o1/o3 models</p>
                </div>

                <!-- Claude Code Thinking Tokens -->
                <div x-show="provider === 'claude_code'" x-cloak>
                    <label for="claude_code_thinking_tokens" class="block text-sm font-medium mb-2">
                        Thinking Tokens
                        <span class="text-gray-400 text-xs ml-1">0 = disabled</span>
                    </label>
                    <input
                        type="number"
                        id="claude_code_thinking_tokens"
                        name="claude_code_thinking_tokens"
                        value="{{ old('claude_code_thinking_tokens', $agent->claude_code_thinking_tokens ?? ($sourceAgent->claude_code_thinking_tokens ?? 0)) }}"
                        min="0"
                        step="1000"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                    <p class="text-xs text-gray-400 mt-1">Extended thinking budget for Claude Code CLI</p>
                </div>

                <!-- Response Level -->
                <div>
                    <label for="response_level" class="block text-sm font-medium mb-2">Response Level</label>
                    <select
                        id="response_level"
                        name="response_level"
                        class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                        @php $currentLevel = old('response_level', $agent->response_level ?? ($sourceAgent->response_level ?? 1)); @endphp
                        <option value="1" {{ $currentLevel == 1 ? 'selected' : '' }}>1 - Concise</option>
                        <option value="2" {{ $currentLevel == 2 ? 'selected' : '' }}>2 - Standard</option>
                        <option value="3" {{ $currentLevel == 3 ? 'selected' : '' }}>3 - Detailed</option>
                        <option value="4" {{ $currentLevel == 4 ? 'selected' : '' }}>4 - Comprehensive</option>
                        <option value="5" {{ $currentLevel == 5 ? 'selected' : '' }}>5 - Exhaustive</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Controls verbosity of responses</p>
                </div>
            </div>
        </div>

        <!-- Tool Selection (full width) -->
        <div class="mt-6 space-y-4">
            <div class="border-b border-gray-700 pb-2">
                <h3 class="text-lg font-semibold text-white">Tools</h3>
            </div>

            <!-- Inherit vs Specific Toggle -->
            <div class="bg-gray-800/50 border border-gray-700 rounded-lg p-4 space-y-3">
                <label class="flex items-start gap-3 cursor-pointer group">
                    <input
                        type="radio"
                        name="inherit_workspace_tools"
                        value="1"
                        x-model="inheritWorkspaceTools"
                        class="mt-1 w-4 h-4 border-gray-600 bg-gray-800 text-blue-500 focus:ring-blue-500"
                    >
                    <div>
                        <span class="text-white font-medium group-hover:text-blue-300">Inherit from workspace</span>
                        <p class="text-xs text-gray-400 mt-0.5">
                            Automatically uses all tools enabled in the workspace. If workspace settings change, this agent's tools update automatically.
                        </p>
                    </div>
                </label>

                <label class="flex items-start gap-3 cursor-pointer group">
                    <input
                        type="radio"
                        name="inherit_workspace_tools"
                        value="0"
                        x-model="inheritWorkspaceTools"
                        class="mt-1 w-4 h-4 border-gray-600 bg-gray-800 text-blue-500 focus:ring-blue-500"
                    >
                    <div>
                        <span class="text-white font-medium group-hover:text-blue-300">Select specific tools</span>
                        <p class="text-xs text-gray-400 mt-0.5">
                            Choose exactly which tools this agent can use. Changes to workspace settings won't affect this agent's tools.
                        </p>
                    </div>
                </label>
            </div>

            <!-- Specific Tools Selection (only shown when not inheriting) -->
            <div x-show="inheritWorkspaceTools === '0' || inheritWorkspaceTools === false" x-cloak class="space-y-4">
                <!-- Loading state -->
                <div x-show="toolsLoading" class="text-gray-400 text-sm py-4">
                    Loading tools...
                </div>

                <div x-show="!toolsLoading" class="space-y-4" :class="{ 'opacity-50': allToolsSelected }">
                    <!-- All tools checkbox -->
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            x-model="allToolsSelected"
                            @change="onAllToolsChange()"
                            class="w-4 h-4 rounded border-gray-700 bg-gray-800 text-blue-500 focus:ring-blue-500"
                        >
                        <span class="text-sm text-gray-300">Select all available tools</span>
                    </label>
                <!-- Native Tools (Claude Code only) -->
                <div x-show="provider === 'claude_code' && nativeTools.length > 0">
                    <h4 class="text-sm font-medium text-gray-300 mb-2">Native Tools <span class="text-gray-500 text-xs" x-text="'(' + nativeTools.length + ')'"></span></h4>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="tool in nativeTools" :key="typeof tool === 'string' ? tool : tool.name">
                            <label
                                class="flex items-center gap-1.5 px-2 py-1 bg-gray-800 border rounded"
                                :class="{
                                    'border-yellow-600/50 opacity-60 cursor-not-allowed': typeof tool === 'object' && !tool.enabled,
                                    'border-gray-700 cursor-not-allowed': (typeof tool === 'string' || tool.enabled) && allToolsSelected,
                                    'border-gray-700 cursor-pointer hover:border-gray-600': (typeof tool === 'string' || tool.enabled) && !allToolsSelected
                                }"
                                :title="typeof tool === 'object' && !tool.enabled ? 'Managed by PocketDev - this tool may interfere with PocketDev operation' : ''"
                            >
                                <input
                                    type="checkbox"
                                    :checked="isToolSelected(typeof tool === 'string' ? tool : tool.name)"
                                    @change="toggleTool(typeof tool === 'string' ? tool : tool.name)"
                                    :disabled="(typeof tool === 'object' && !tool.enabled) || allToolsSelected"
                                    class="w-3.5 h-3.5 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500 disabled:opacity-50"
                                >
                                <span class="text-xs" x-text="typeof tool === 'string' ? tool : tool.name"></span>
                                <span x-show="typeof tool === 'object' && !tool.enabled" class="text-yellow-500 text-xs ml-0.5">&#9888;</span>
                            </label>
                        </template>
                    </div>
                </div>

                <!-- PocketDev Tools (grouped by category) -->
                <template x-for="(tools, category) in groupedPocketdevTools" :key="category">
                    <div>
                        <h4 class="text-sm font-medium text-gray-300 mb-2">
                            <span x-text="getCategoryTitle(category)"></span>
                            <span class="text-gray-500 text-xs" x-text="'(' + tools.length + ')'"></span>
                        </h4>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="tool in tools" :key="tool.slug">
                                <label class="flex items-center gap-1.5 px-2 py-1 bg-gray-800 border border-gray-700 rounded" :class="allToolsSelected ? 'cursor-not-allowed' : 'cursor-pointer hover:border-gray-600'" :title="tool.description">
                                    <input
                                        type="checkbox"
                                        :checked="isToolSelected(tool.slug)"
                                        @change="toggleTool(tool.slug)"
                                        :disabled="allToolsSelected"
                                        class="w-3.5 h-3.5 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500 disabled:opacity-50"
                                    >
                                    <span class="text-xs" x-text="tool.name"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- User Tools -->
                <div x-show="userTools.length > 0">
                    <h4 class="text-sm font-medium text-gray-300 mb-2">User Tools <span class="text-gray-500 text-xs" x-text="'(' + userTools.length + ')'"></span></h4>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="tool in userTools" :key="tool.slug">
                            <label class="flex items-center gap-1.5 px-2 py-1 bg-gray-800 border border-gray-700 rounded" :class="allToolsSelected ? 'cursor-not-allowed' : 'cursor-pointer hover:border-gray-600'" :title="tool.description">
                                <input
                                    type="checkbox"
                                    :checked="isToolSelected(tool.slug)"
                                    @change="toggleTool(tool.slug)"
                                    :disabled="allToolsSelected"
                                    class="w-3.5 h-3.5 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500 disabled:opacity-50"
                                >
                                <span class="text-xs" x-text="tool.name"></span>
                            </label>
                        </template>
                    </div>
                </div>

                <!-- No tools message -->
                <div x-show="nativeTools.length === 0 && Object.keys(groupedPocketdevTools).length === 0 && userTools.length === 0" class="text-gray-500 text-sm">
                    No tools available for this provider.
                </div>

                <p class="text-xs text-gray-400" x-text="allToolsSelected ? 'All tools are enabled. Uncheck to select specific tools.' : 'Select specific tools for this agent.'"></p>
                </div>
            </div>

            <!-- Hidden inputs for selected tools (only when not inheriting and not all tools selected) -->
            <template x-if="inheritWorkspaceTools !== '1' && inheritWorkspaceTools !== true">
                <template x-for="tool in selectedTools" :key="tool">
                    <input x-show="!allToolsSelected" type="hidden" name="allowed_tools[]" :value="tool">
                </template>
            </template>
        </div>

        <!-- Memory Schemas Selection (full width) -->
        <div class="mt-6 space-y-4">
            <div class="border-b border-gray-700 pb-2">
                <h3 class="text-lg font-semibold text-white">Memory Schemas</h3>
            </div>

            <!-- Inherit vs Specific Toggle -->
            <div class="bg-gray-800/50 border border-gray-700 rounded-lg p-4 space-y-3">
                <label class="flex items-start gap-3 cursor-pointer group">
                    <input
                        type="radio"
                        name="inherit_workspace_schemas"
                        value="1"
                        x-model="inheritWorkspaceSchemas"
                        class="mt-1 w-4 h-4 border-gray-600 bg-gray-800 text-blue-500 focus:ring-blue-500"
                    >
                    <div>
                        <span class="text-white font-medium group-hover:text-blue-300">Inherit from workspace</span>
                        <p class="text-xs text-gray-400 mt-0.5">
                            Automatically uses all memory schemas enabled in the workspace. If workspace settings change, this agent's access updates automatically.
                        </p>
                    </div>
                </label>

                <label class="flex items-start gap-3 cursor-pointer group">
                    <input
                        type="radio"
                        name="inherit_workspace_schemas"
                        value="0"
                        x-model="inheritWorkspaceSchemas"
                        class="mt-1 w-4 h-4 border-gray-600 bg-gray-800 text-blue-500 focus:ring-blue-500"
                    >
                    <div>
                        <span class="text-white font-medium group-hover:text-blue-300">Select specific schemas</span>
                        <p class="text-xs text-gray-400 mt-0.5">
                            Choose exactly which memory schemas this agent can access. Changes to workspace settings won't affect this agent's schema access.
                        </p>
                    </div>
                </label>
            </div>

            <!-- Specific Schemas Selection (only shown when not inheriting) -->
            <div x-show="inheritWorkspaceSchemas === '0' || inheritWorkspaceSchemas === false" x-cloak>
                <div x-show="schemasLoading" class="text-gray-400 text-sm py-4">
                    Loading schemas...
                </div>

                <div x-show="!schemasLoading">
                    <div x-show="availableSchemas.length === 0" class="text-gray-500 text-sm py-4">
                        No memory schemas available. Enable schemas in the workspace settings first.
                    </div>

                    <div x-show="availableSchemas.length > 0" class="space-y-2">
                        <template x-for="schema in availableSchemas" :key="schema.id">
                            <label class="flex items-center gap-3 p-3 bg-gray-800 border border-gray-700 rounded cursor-pointer hover:bg-gray-750">
                                <input
                                    type="checkbox"
                                    :checked="isSchemaSelected(schema.id)"
                                    @change="toggleSchema(schema.id)"
                                    class="w-4 h-4 rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500"
                                >
                                <div class="flex-1">
                                    <span class="text-white font-medium" x-text="schema.name"></span>
                                    <code class="text-gray-500 text-xs ml-2 bg-gray-700 px-1.5 py-0.5 rounded" x-text="schema.schema_name"></code>
                                    <p x-show="schema.description" class="text-xs text-gray-400 mt-0.5" x-text="schema.description"></p>
                                </div>
                            </label>
                        </template>

                        <p class="text-xs text-gray-400 mt-2">
                            All memory tools will require <code class="bg-gray-700 px-1 rounded">--schema</code> parameter specifying the short schema name.
                        </p>
                    </div>
                </div>

                <!-- Hidden inputs for selected schemas (only when not inheriting) -->
                <template x-if="inheritWorkspaceSchemas !== '1' && inheritWorkspaceSchemas !== true">
                    <template x-for="schemaId in selectedSchemas" :key="schemaId">
                        <input type="hidden" name="memory_schemas[]" :value="schemaId">
                    </template>
                </template>
            </div>
        </div>

        <!-- System Prompt (full width) -->
        <div class="mt-6">
            <label for="system_prompt" class="block text-sm font-medium mb-2">Additional System Prompt</label>
            <textarea
                id="system_prompt"
                name="system_prompt"
                x-model="agentSystemPrompt"
                rows="6"
                class="w-full px-3 py-2 bg-gray-800 text-white border border-gray-700 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent font-mono text-sm"
                placeholder="Add custom instructions that will be appended to the system prompt..."
            ></textarea>
            <p class="text-xs text-gray-400 mt-1">These instructions will be added to the base system prompt for this agent.</p>
        </div>

        <!-- Actions -->
        <div class="flex gap-3 mt-6 pt-4 border-t border-gray-700">
            <x-button type="submit" variant="primary">
                {{ isset($agent) ? 'Update Agent' : 'Create Agent' }}
            </x-button>
            <a href="{{ route('config.agents') }}">
                <x-button type="button" variant="secondary">
                    Cancel
                </x-button>
            </a>
        </div>
    </form>

    @if(isset($agent))
        <!-- Separate delete form -->
        <form method="POST" action="{{ route('config.agents.delete', $agent) }}" class="mt-4">
            @csrf
            @method('DELETE')
            <x-button type="submit" variant="danger" onclick="return confirm('Are you sure you want to delete this agent? This action cannot be undone.')">
                Delete Agent
            </x-button>
        </form>
    @endif

    <!-- System Prompt Preview Section -->
    <div class="mt-8 border-t border-gray-700 pt-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-semibold text-white">System Prompt Preview</h3>
                <p class="text-xs text-gray-500 mt-1">Does not reflect unsaved changes. Click "Refresh Preview" after making changes.</p>
            </div>
            <button
                type="button"
                @click="togglePromptPreview()"
                class="text-sm text-blue-400 hover:text-blue-300"
            >
                <span x-text="showPromptPreview ? 'Hide Preview' : 'Show Preview'"></span>
            </button>
        </div>

        <div x-show="showPromptPreview" x-cloak>
            <!-- Loading state -->
            <div x-show="promptLoading" class="text-gray-400 text-sm py-4">
                Loading preview...
            </div>

            <div x-show="!promptLoading">
                <!-- Stats and controls -->
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-4 text-sm">
                    <div class="flex items-center gap-4 text-gray-400">
                        <span>Sections: <span class="text-white" x-text="promptSections.length"></span></span>
                        <span class="flex items-center gap-1">
                            Total: <span class="text-blue-400 font-medium" x-text="'~' + estimatedTokens.toLocaleString() + ' tokens'"></span>
                            <span class="relative group">
                                <i class="fa-solid fa-circle-info text-gray-500 text-xs cursor-help"></i>
                                <span class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 text-xs text-gray-300 bg-gray-900 border border-gray-700 rounded shadow-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none z-10">
                                    Estimated at ~4 chars/token.<br>
                                    <a href="https://claude-tokenizer.vercel.app/" target="_blank" class="text-blue-400 hover:underline pointer-events-auto">Claude Tokenizer</a> for exact counts.
                                </span>
                            </span>
                        </span>
                    </div>
                    <div class="flex gap-2 text-xs">
                        <button
                            type="button"
                            @click="copyFullPrompt()"
                            class="text-blue-400 hover:text-blue-300"
                        >
                            <span x-show="!copiedFullPrompt">Copy</span>
                            <span x-show="copiedFullPrompt" class="text-green-400">Copied!</span>
                        </button>
                        <span class="text-gray-600">|</span>
                        <button
                            type="button"
                            @click="expandAllSections()"
                            class="text-blue-400 hover:text-blue-300"
                        >Expand all</button>
                        <span class="text-gray-600">|</span>
                        <button
                            type="button"
                            @click="collapseAllSections()"
                            class="text-blue-400 hover:text-blue-300"
                        >Collapse all</button>
                    </div>
                </div>

                <!-- Sections - using shared partial -->
                <div x-data="{
                    // Alias promptSections to sections for the partial
                    get sections() { return promptSections }
                }">
                    @include('partials.system-prompt-sections')
                </div>

                <!-- Refresh button -->
                <div class="mt-4">
                    <button
                        type="button"
                        @click="fetchPromptPreview()"
                        class="text-sm text-blue-400 hover:text-blue-300"
                    >
                        Refresh Preview
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/marked@15.0.7/marked.min.js"
        integrity="sha384-H+hy9ULve6xfxRkWIh/YOtvDdpXgV2fmAGQkIDTxIgZwNoaoBal14Di2YTMR6MzR"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.3.1/dist/purify.min.js"
        integrity="sha384-80VlBZnyAwkkqtSfg5NhPyZff6nU4K/qniLBL8Jnm4KDv6jZhLiYtJbhglg/i9ww"
        crossorigin="anonymous"></script>
<script>
    // Configure marked for safe rendering
    marked.setOptions({
        breaks: true,
        gfm: true,
    });

    function agentForm() {
        @php
            // Determine the source for pre-filling: existing agent > source agent for cloning > defaults
            $formProvider = old('provider', $agent->provider ?? ($sourceAgent->provider ?? 'claude_code'));
            $formModel = old('model', $agent->model ?? ($sourceAgent->model ?? ''));
            $formAllowedTools = old('allowed_tools', isset($agent) ? $agent->allowed_tools : ($sourceAgent->allowed_tools ?? null));
            $formSystemPrompt = old('system_prompt', $agent->system_prompt ?? ($sourceAgent->system_prompt ?? ''));

            // Inherit settings: default to true for new agents, use existing values for edits
            $formInheritTools = old('inherit_workspace_tools', $agent->inherit_workspace_tools ?? ($sourceAgent->inherit_workspace_tools ?? true));
            $formInheritSchemas = old('inherit_workspace_schemas', $agent->inherit_workspace_schemas ?? ($sourceAgent->inherit_workspace_schemas ?? false));

            // For memory schemas, we need to filter to only include those available in the target workspace
            if (isset($agent)) {
                $formSchemas = $agent->memoryDatabases->pluck('id')->toArray();
            } elseif (isset($sourceAgent) && isset($selectedWorkspaceId)) {
                // When cloning, only include schemas that are enabled in the target workspace
                $targetWorkspace = \App\Models\Workspace::find($selectedWorkspaceId);
                $enabledSchemaIds = $targetWorkspace?->enabledMemoryDatabases()->pluck('memory_databases.id')->toArray() ?? [];
                $formSchemas = $sourceAgent->memoryDatabases->pluck('id')->filter(fn($id) => in_array($id, $enabledSchemaIds))->toArray();
            } else {
                $formSchemas = [];
            }
            $formSchemas = old('memory_schemas', $formSchemas) ?? [];
        @endphp

        return {
            provider: @js($formProvider),
            model: @js($formModel),
            modelsPerProvider: @js($modelsPerProvider),
            selectedTools: @js($formAllowedTools ?? []),
            allToolsSelected: @js($formAllowedTools === null),
            agentSystemPrompt: @js($formSystemPrompt),

            // Inherit from workspace settings
            inheritWorkspaceTools: @js($formInheritTools ? '1' : '0'),
            inheritWorkspaceSchemas: @js($formInheritSchemas ? '1' : '0'),

            // Dynamic tools from API
            nativeTools: [],
            pocketdevTools: [],
            userTools: [],
            toolsLoading: false,

            // Memory schemas
            availableSchemas: [],
            selectedSchemas: @js($formSchemas),
            schemasLoading: false,

            // System prompt preview
            promptSections: [],
            promptLoading: false,
            showPromptPreview: false,
            estimatedTokens: 0,
            copiedSectionIdx: null,
            copiedFullPrompt: false,
            expandedSections: {}, // Track which sections are expanded by index
            rawViewSections: {}, // Track which sections show raw markdown vs rendered

            get availableModels() {
                return this.modelsPerProvider[this.provider] || [];
            },

            async init() {
                // Set initial model if not set
                if (!this.model && this.availableModels.length > 0) {
                    this.model = this.availableModels[0];
                }
                // Fetch tools and schemas
                await Promise.all([
                    this.fetchTools(),
                    this.fetchSchemas(),
                ]);
            },

            async onProviderChange() {
                // Reset model to first available for new provider
                const models = this.modelsPerProvider[this.provider] || [];
                if (models.length > 0 && !models.includes(this.model)) {
                    this.model = models[0];
                }
                // Fetch tools for new provider
                await this.fetchTools();
            },

            async fetchTools() {
                this.toolsLoading = true;
                try {
                    const response = await fetch(`/api/tools/for-provider/${this.provider}`);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const data = await response.json();
                    this.nativeTools = data.native || [];
                    this.pocketdevTools = data.pocketdev || [];
                    this.userTools = data.user || [];
                } catch (error) {
                    console.error('Failed to fetch tools:', error);
                    this.nativeTools = [];
                    this.pocketdevTools = [];
                    this.userTools = [];
                } finally {
                    this.toolsLoading = false;
                }
            },

            async fetchSchemas() {
                this.schemasLoading = true;
                try {
                    const agentId = @js(isset($agent) ? $agent->id : null);
                    const url = agentId
                        ? `/api/agents/${agentId}/available-schemas`
                        : '/api/agents/available-schemas';
                    const response = await fetch(url);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const data = await response.json();
                    this.availableSchemas = data.schemas || [];
                } catch (error) {
                    console.error('Failed to fetch schemas:', error);
                    this.availableSchemas = [];
                } finally {
                    this.schemasLoading = false;
                }
            },

            isSchemaSelected(schemaId) {
                return this.selectedSchemas.includes(schemaId);
            },

            toggleSchema(schemaId) {
                const idx = this.selectedSchemas.indexOf(schemaId);
                if (idx > -1) {
                    this.selectedSchemas.splice(idx, 1);
                } else {
                    this.selectedSchemas.push(schemaId);
                }
            },

            async fetchPromptPreview() {
                this.promptLoading = true;
                try {
                    const csrfToken = document.querySelector('meta[name=csrf-token]');
                    // Get workspace ID from form (for new agents) or from existing agent
                    const workspaceId = document.getElementById('workspace_id')?.value || @js(isset($agent) ? $agent->workspace_id : null);
                    const response = await fetch('/api/agents/preview-system-prompt', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken ? csrfToken.content : '',
                        },
                        body: JSON.stringify({
                            provider: this.provider,
                            agent_system_prompt: this.agentSystemPrompt,
                            allowed_tools: this.allToolsSelected ? null : this.selectedTools,
                            memory_schemas: this.selectedSchemas,
                            workspace_id: workspaceId,
                        }),
                    });
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const data = await response.json();
                    this.promptSections = data.sections || [];
                    this.estimatedTokens = data.estimated_tokens || 0;
                    // Initialize expanded state from API (all collapsed by default)
                    this.expandedSections = {};
                } catch (error) {
                    console.error('Failed to fetch prompt preview:', error);
                    this.promptSections = [];
                    this.estimatedTokens = 0;
                } finally {
                    this.promptLoading = false;
                }
            },

            toggleTool(tool) {
                if (this.allToolsSelected) {
                    this.allToolsSelected = false;
                    this.selectedTools = [];
                }
                const idx = this.selectedTools.indexOf(tool);
                if (idx > -1) {
                    this.selectedTools.splice(idx, 1);
                } else {
                    this.selectedTools.push(tool);
                }
            },

            onAllToolsChange() {
                // x-model already toggled the value, just handle side effects
                if (this.allToolsSelected) {
                    this.selectedTools = [];
                }
            },

            isToolSelected(tool) {
                return this.allToolsSelected || this.selectedTools.includes(tool);
            },

            async togglePromptPreview() {
                this.showPromptPreview = !this.showPromptPreview;
                if (this.showPromptPreview) {
                    await this.fetchPromptPreview();
                }
            },

            // Group pocketdev tools by category
            get groupedPocketdevTools() {
                const groups = {};
                for (const tool of this.pocketdevTools) {
                    const category = tool.category || 'other';
                    if (!groups[category]) {
                        groups[category] = [];
                    }
                    groups[category].push(tool);
                }
                return groups;
            },

            getCategoryTitle(category) {
                const titles = {
                    'memory': 'Memory System',
                    'tools': 'Tool Management',
                    'file_ops': 'File Operations',
                    'custom': 'Custom Tools',
                };
                return titles[category] || category.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
            },

            parseMarkdown(content) {
                if (!content) return '';
                try {
                    const html = marked.parse(content);
                    return DOMPurify.sanitize(html);
                } catch (e) {
                    console.error('Markdown parse error:', e);
                    return content.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }
            },

            toggleSection(path) {
                this.expandedSections[path] = !this.expandedSections[path];
            },

            toggleRawView(path) {
                this.rawViewSections[path] = !this.rawViewSections[path];
            },

            // Recursively collect all paths for expand/collapse all
            collectAllPaths(sections, prefix = '') {
                const paths = [];
                sections.forEach((section, idx) => {
                    const path = prefix ? `${prefix}.${idx}` : String(idx);
                    paths.push(path);
                    if (section.children && section.children.length) {
                        paths.push(...this.collectAllPaths(section.children, path));
                    }
                });
                return paths;
            },

            expandAllSections() {
                const allPaths = this.collectAllPaths(this.promptSections);
                allPaths.forEach(path => {
                    this.expandedSections[path] = true;
                });
            },

            collapseAllSections() {
                const allPaths = this.collectAllPaths(this.promptSections);
                allPaths.forEach(path => {
                    this.expandedSections[path] = false;
                });
            },

            copySectionContent(section, path) {
                if (!section.content) return;
                navigator.clipboard.writeText(section.content)
                    .then(() => {
                        this.copiedSectionIdx = path;
                        setTimeout(() => {
                            if (this.copiedSectionIdx === path) {
                                this.copiedSectionIdx = null;
                            }
                        }, 1500);
                    })
                    .catch(err => {
                        console.error('Failed to copy:', err);
                    });
            },

            // Flatten all sections recursively to get full prompt text
            flattenSections(sections) {
                let parts = [];
                for (const section of sections) {
                    if (section.content) {
                        parts.push(section.content);
                    }
                    if (section.children && section.children.length) {
                        parts.push(this.flattenSections(section.children));
                    }
                }
                return parts.filter(p => p).join('\n\n');
            },

            copyFullPrompt() {
                const fullPrompt = this.flattenSections(this.promptSections);
                navigator.clipboard.writeText(fullPrompt)
                    .then(() => {
                        this.copiedFullPrompt = true;
                        setTimeout(() => {
                            this.copiedFullPrompt = false;
                        }, 1500);
                    })
                    .catch(err => {
                        console.error('Failed to copy full prompt:', err);
                    });
            }
        };
    }
</script>
@endpush
