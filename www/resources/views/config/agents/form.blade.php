@extends('layouts.config')

@section('title', isset($agent) ? 'Edit Agent: ' . $agent->name : 'Create Agent')

@section('content')
<div x-data="agentForm()" x-init="init()">
    <form
        method="POST"
        action="{{ isset($agent) ? route('config.agents.update', $agent) : route('config.agents.store') }}"
    >
        @csrf
        @if(isset($agent))
            @method('PUT')
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
                        value="{{ old('name', $agent->name ?? '') }}"
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
                    >{{ old('description', $agent->description ?? '') }}</textarea>
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
                            {{ old('enabled', $agent->enabled ?? true) ? 'checked' : '' }}
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
                        value="{{ old('anthropic_thinking_budget', $agent->anthropic_thinking_budget ?? 0) }}"
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
                        @php $currentEffort = old('openai_reasoning_effort', $agent->openai_reasoning_effort ?? 'none'); @endphp
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
                        value="{{ old('claude_code_thinking_tokens', $agent->claude_code_thinking_tokens ?? 0) }}"
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
                        @php $currentLevel = old('response_level', $agent->response_level ?? 1); @endphp
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
            <div class="flex items-center justify-between border-b border-gray-700 pb-2">
                <h3 class="text-lg font-semibold text-white">Allowed Tools</h3>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        x-model="allToolsSelected"
                        @change="onAllToolsChange()"
                        class="w-4 h-4 rounded border-gray-700 bg-gray-800 text-blue-500 focus:ring-blue-500"
                    >
                    <span class="text-sm">Allow all tools</span>
                </label>
            </div>

            <!-- Loading state -->
            <div x-show="toolsLoading" class="text-gray-400 text-sm py-4">
                Loading tools...
            </div>

            <div x-show="!toolsLoading" class="space-y-4" :class="{ 'opacity-50': allToolsSelected }">
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

                <p class="text-xs text-gray-400" x-text="allToolsSelected ? 'All tools are enabled. Uncheck \"Allow all tools\" to select specific tools.' : 'Select specific tools or enable \"Allow all tools\" to grant access to everything.'"></p>
            </div>

            <!-- Hidden inputs for selected tools (only when not all tools selected) -->
            <template x-for="tool in selectedTools" :key="tool">
                <input x-show="!allToolsSelected" type="hidden" name="allowed_tools[]" :value="tool">
            </template>
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
            <h3 class="text-lg font-semibold text-white">System Prompt Preview</h3>
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
                <!-- Stats -->
                <div class="flex gap-4 mb-4 text-sm text-gray-400">
                    <span>Sections: <span class="text-white" x-text="promptSections.length"></span></span>
                    <span>Est. tokens: <span class="text-white" x-text="estimatedTokens.toLocaleString()"></span></span>
                </div>

                <!-- Sections -->
                <div class="space-y-4">
                    <template x-for="(section, idx) in promptSections" :key="idx">
                        <div class="bg-gray-800 border border-gray-700 rounded overflow-hidden">
                            <div class="flex items-center justify-between px-4 py-2 bg-gray-750 border-b border-gray-700">
                                <div>
                                    <span class="font-medium text-white" x-text="section.title"></span>
                                    <span class="text-xs text-gray-500 ml-2" x-text="section.source"></span>
                                </div>
                                <span class="text-xs text-gray-400" x-text="section.content.length + ' chars'"></span>
                            </div>
                            <div class="p-4 max-h-96 overflow-y-auto prose-preview" x-html="parseMarkdown(section.content)"></div>
                        </div>
                    </template>
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

<script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.6/dist/purify.min.js"></script>
<script>
    // Configure marked for safe rendering
    marked.setOptions({
        breaks: true,
        gfm: true,
    });

    function agentForm() {
        return {
            provider: @js(old('provider', $agent->provider ?? 'claude_code')),
            model: @js(old('model', $agent->model ?? '')),
            modelsPerProvider: @js($modelsPerProvider),
            selectedTools: @js(old('allowed_tools', isset($agent) ? $agent->allowed_tools : null) ?? []),
            allToolsSelected: @js(old('allowed_tools', isset($agent) ? $agent->allowed_tools : null) === null),
            agentSystemPrompt: @js(old('system_prompt', $agent->system_prompt ?? '')),

            // Dynamic tools from API
            nativeTools: [],
            pocketdevTools: [],
            userTools: [],
            toolsLoading: false,

            // System prompt preview
            promptSections: [],
            promptLoading: false,
            showPromptPreview: false,
            estimatedTokens: 0,

            get availableModels() {
                return this.modelsPerProvider[this.provider] || [];
            },

            async init() {
                // Set initial model if not set
                if (!this.model && this.availableModels.length > 0) {
                    this.model = this.availableModels[0];
                }
                // Fetch tools for initial provider
                await this.fetchTools();
            },

            async onProviderChange() {
                // Reset model to first available for new provider
                const models = this.modelsPerProvider[this.provider] || [];
                if (models.length > 0 && !models.includes(this.model)) {
                    this.model = models[0];
                }
                // Fetch tools for new provider
                await this.fetchTools();
                // Refresh preview if visible
                if (this.showPromptPreview) {
                    await this.fetchPromptPreview();
                }
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

            async fetchPromptPreview() {
                this.promptLoading = true;
                try {
                    const csrfToken = document.querySelector('meta[name=csrf-token]');
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
                        }),
                    });
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const data = await response.json();
                    this.promptSections = data.sections || [];
                    this.estimatedTokens = data.estimated_tokens || 0;
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
            }
        };
    }
</script>

<style>
    [x-cloak] { display: none !important; }
    .bg-gray-750 { background-color: rgb(55 65 81 / 0.5); }

    /* Prose styles for markdown rendering */
    .prose-preview {
        font-size: 0.8rem;
        line-height: 1.6;
        color: #d1d5db;
    }
    .prose-preview h1 { font-size: 1.25rem; font-weight: 700; margin: 1rem 0 0.5rem; color: #f3f4f6; }
    .prose-preview h2 { font-size: 1.1rem; font-weight: 600; margin: 0.875rem 0 0.5rem; color: #f3f4f6; }
    .prose-preview h3 { font-size: 0.95rem; font-weight: 600; margin: 0.75rem 0 0.375rem; color: #e5e7eb; }
    .prose-preview h4 { font-size: 0.875rem; font-weight: 600; margin: 0.5rem 0 0.25rem; color: #e5e7eb; }
    .prose-preview p { margin: 0.5rem 0; }
    .prose-preview ul, .prose-preview ol { margin: 0.5rem 0; padding-left: 1.5rem; }
    .prose-preview li { margin: 0.25rem 0; }
    .prose-preview ul { list-style-type: disc; }
    .prose-preview ol { list-style-type: decimal; }
    .prose-preview code { background: #1f2937; padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.75rem; }
    .prose-preview pre { background: #1f2937; padding: 0.75rem; border-radius: 0.375rem; overflow-x: auto; margin: 0.5rem 0; }
    .prose-preview pre code { background: transparent; padding: 0; }
    .prose-preview strong { color: #f3f4f6; font-weight: 600; }
    .prose-preview em { font-style: italic; }
    .prose-preview a { color: #60a5fa; text-decoration: underline; }
    .prose-preview blockquote { border-left: 3px solid #4b5563; padding-left: 1rem; margin: 0.5rem 0; color: #9ca3af; }
    .prose-preview hr { border-color: #374151; margin: 1rem 0; }
</style>
@endsection
