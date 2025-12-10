{{-- Quick Settings Modal --}}
<x-modal show="showQuickSettings" title="Quick Settings">
    <div class="space-y-4">
        {{-- Provider Selection --}}
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">Provider</label>
            <div class="space-y-2">
                <template x-for="(p, key) in providers" :key="key">
                    <label class="flex items-center text-gray-300 cursor-pointer" :class="{'opacity-50': !p.available}">
                        <input type="radio" x-model="provider" :value="key" @change="updateModels(); saveDefaultSettings()" :disabled="!p.available" class="mr-2">
                        <span x-text="key" class="capitalize"></span>
                        <span x-show="!p.available" class="ml-2 text-xs text-red-400">(not configured)</span>
                    </label>
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
                            <input type="radio" x-model="anthropicThinkingBudget" :value="level.budget_tokens" @change="saveDefaultSettings()" class="mr-2">
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

        {{-- OpenAI Summary Display (shown only for OpenAI when reasoning is enabled) --}}
        <template x-if="provider === 'openai' && openaiReasoningEffort !== 'none'">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Show Thinking</label>
                <div class="space-y-2">
                    <template x-for="opt in openaiSummaryOptions" :key="opt.value">
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="openaiReasoningSummary" :value="opt.value" @change="saveDefaultSettings()" class="mr-2">
                            <span x-text="opt.name"></span>
                            <span x-show="opt.description" class="ml-2 text-xs text-gray-500" x-text="'(' + opt.description + ')'"></span>
                        </label>
                    </template>
                </div>
            </div>
        </template>

        {{-- Response Level --}}
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">Response Length</label>
            <div class="space-y-2">
                <template x-for="(level, index) in responseLevels" :key="index">
                    <label class="flex items-center text-gray-300 cursor-pointer">
                        <input type="radio" x-model="responseLevel" :value="index" @change="saveDefaultSettings()" class="mr-2">
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
