{{-- Quick Settings Modal --}}
<x-modal show="showQuickSettings" title="Quick Settings">
    <div class="space-y-4">
        {{-- Provider Selection --}}
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">Provider</label>
            <div class="space-y-2">
                <template x-for="(p, key) in providers" :key="key">
                    <div class="flex items-center text-gray-300" :class="{'opacity-50': !p.available}">
                        <label class="flex items-center cursor-pointer flex-1">
                            <input type="radio" x-model="provider" :value="key" @change="updateModels(); saveDefaultSettings()" :disabled="!p.available" class="mr-2">
                            <span x-text="key.replace('_', ' ')" class="capitalize"></span>
                            <span x-show="!p.available && key !== 'claude_code'" class="ml-2 text-xs text-red-400">(not configured)</span>
                        </label>
                        <button
                            x-show="key === 'claude_code' && !p.available"
                            @click="showQuickSettings = false; showClaudeCodeAuthModal = true"
                            class="text-xs text-blue-400 hover:underline"
                        >Setup</button>
                    </div>
                </template>
            </div>
        </div>

        {{-- Model Selection --}}
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">Model</label>
            <div class="space-y-2 max-h-48 overflow-y-auto">
                <template x-for="(info, modelId) in availableModels" :key="modelId">
                    <label class="flex items-center text-gray-300 cursor-pointer">
                        <input type="radio" x-model="model" :value="modelId" @change="saveDefaultSettings()" class="mr-2">
                        <span x-text="info.name"></span>
                    </label>
                </template>
            </div>
        </div>

        {{-- Anthropic Thinking Budget (shown only for Anthropic) --}}
        <template x-if="provider === 'anthropic'">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Thinking Budget</label>
                <div class="space-y-2">
                    <template x-for="level in anthropicThinkingLevels" :key="level.budget_tokens">
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model.number="anthropicThinkingBudget" :value="level.budget_tokens" @change="saveDefaultSettings()" class="mr-2">
                            <span x-text="level.name"></span>
                            <span x-show="level.budget_tokens > 0" class="ml-2 text-xs text-gray-500" x-text="'(' + level.budget_tokens.toLocaleString() + ' tokens)'"></span>
                        </label>
                    </template>
                </div>
            </div>
        </template>

        {{-- OpenAI Reasoning Effort (shown only for OpenAI) --}}
        <template x-if="provider === 'openai'">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Reasoning Effort</label>
                <div class="space-y-2">
                    <template x-for="level in openaiEffortLevels" :key="level.value">
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="openaiReasoningEffort" :value="level.value" @change="saveDefaultSettings()" class="mr-2">
                            <span x-text="level.name"></span>
                            <span x-show="level.description" class="ml-2 text-xs text-gray-500" x-text="'(' + level.description + ')'"></span>
                        </label>
                    </template>
                </div>
            </div>
        </template>

        {{-- OpenAI Compatible Reasoning Effort (shown only for OpenAI Compatible) --}}
        <template x-if="provider === 'openai_compatible'">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Reasoning Effort</label>
                <p class="text-xs text-gray-500 mb-2">Most local LLMs ignore this setting, but it's available for compatible servers.</p>
                <div class="space-y-2">
                    <template x-for="level in openaiCompatibleEffortLevels" :key="level.value">
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="openaiCompatibleReasoningEffort" :value="level.value" @change="saveDefaultSettings()" class="mr-2">
                            <span x-text="level.name"></span>
                            <span x-show="level.description" class="ml-2 text-xs text-gray-500" x-text="'(' + level.description + ')'"></span>
                        </label>
                    </template>
                </div>
            </div>
        </template>

        {{-- Claude Code Thinking Tokens (shown only for Claude Code) --}}
        <template x-if="provider === 'claude_code'">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Thinking Budget</label>
                <div class="space-y-2">
                    <template x-for="level in claudeCodeThinkingLevels" :key="level.thinking_tokens">
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model.number="claudeCodeThinkingTokens" :value="level.thinking_tokens" @change="saveDefaultSettings()" class="mr-2">
                            <span x-text="level.name"></span>
                            <span x-show="level.thinking_tokens > 0" class="ml-2 text-xs text-gray-500" x-text="'(' + level.thinking_tokens.toLocaleString() + ' tokens)'"></span>
                        </label>
                    </template>
                </div>
            </div>
        </template>

        {{-- Claude Code Tool Selection (shown only for Claude Code) --}}
        <template x-if="provider === 'claude_code'">
            <div>
                <p class="text-xs text-gray-500 mb-2">Select which tools Claude Code can use. Remove tools to restrict access.</p>
                <x-multi-select
                    options="claudeCodeAvailableTools"
                    selected="claudeCodeAllowedTools"
                    label="Allowed Tools"
                    placeholder="No tools selected"
                    all-selected-text="All tools enabled"
                    none-selected-text="No tools (restricted)"
                    on-change="saveDefaultSettings"
                />
            </div>
        </template>

        {{-- Response Level --}}
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">Response Length</label>
            <div class="space-y-2">
                <template x-for="(level, index) in responseLevels" :key="index">
                    <label class="flex items-center text-gray-300 cursor-pointer">
                        <input type="radio" x-model.number="responseLevel" :value="index" @change="saveDefaultSettings()" class="mr-2">
                        <span x-text="level.name"></span>
                        <span class="ml-2 text-xs text-gray-500" x-text="'(' + level.tokens.toLocaleString() + ' tokens)'"></span>
                    </label>
                </template>
            </div>
        </div>

        {{-- Action Buttons --}}
        <x-button variant="primary" full-width class="mt-6" @click="showQuickSettings = false">
            Done
        </x-button>
    </div>
</x-modal>
