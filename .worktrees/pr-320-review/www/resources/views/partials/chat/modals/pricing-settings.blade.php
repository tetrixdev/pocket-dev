{{-- Pricing Settings Modal (Read-Only) --}}
<x-modal show="showPricingSettings" title="Model Pricing" max-width="lg" class="max-h-[90vh] overflow-y-auto">
    {{-- Provider Selection --}}
    <div class="mb-4">
        <label class="block text-sm text-gray-400 mb-2">Provider:</label>
        <div class="flex gap-2">
            <button @click="pricingProvider = 'anthropic'; pricingModel = Object.keys(pricingModelsForProvider)[0] || pricingModel"
                    :class="pricingProvider === 'anthropic' ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                    class="px-4 py-2 rounded font-medium transition-colors">
                Anthropic
            </button>
            <button @click="pricingProvider = 'openai'; pricingModel = Object.keys(pricingModelsForProvider)[0] || pricingModel"
                    :class="pricingProvider === 'openai' ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                    class="px-4 py-2 rounded font-medium transition-colors">
                OpenAI
            </button>
        </div>
    </div>

    {{-- Model Selection --}}
    <div class="mb-4">
        <label class="block text-sm text-gray-400 mb-2">Model:</label>
        <div class="space-y-1 max-h-32 overflow-y-auto bg-gray-900 rounded p-2">
            <template x-for="(info, modelId) in pricingModelsForProvider" :key="modelId">
                <label class="flex items-center text-gray-300 cursor-pointer hover:bg-gray-800 px-2 py-1 rounded">
                    <input type="radio" x-model="pricingModel" :value="modelId" class="mr-2">
                    <span class="text-sm" x-text="info.name || modelId"></span>
                </label>
            </template>
            <template x-if="Object.keys(pricingModelsForProvider).length === 0">
                <div class="text-gray-500 text-sm italic px-2 py-1">No models configured for this provider</div>
            </template>
        </div>
    </div>

    {{-- Pricing Display for Selected Model --}}
    <template x-if="currentPricing">
        <div class="space-y-3 border-t border-gray-700 pt-4">
            <div class="text-sm text-gray-300 font-medium mb-2">
                Pricing for: <span class="text-blue-400" x-text="currentPricing.name || pricingModel"></span>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="bg-gray-900 rounded p-3">
                    <div class="text-xs text-gray-400 mb-1">Input</div>
                    <div class="text-lg text-gray-200">$<span x-text="currentPricing.input.toFixed(2)"></span><span class="text-xs text-gray-500">/MTok</span></div>
                </div>

                <div class="bg-gray-900 rounded p-3">
                    <div class="text-xs text-gray-400 mb-1">Output</div>
                    <div class="text-lg text-gray-200">$<span x-text="currentPricing.output.toFixed(2)"></span><span class="text-xs text-gray-500">/MTok</span></div>
                </div>

                <div class="bg-gray-900 rounded p-3">
                    <div class="text-xs text-gray-400 mb-1">Cache Write</div>
                    <div class="text-lg text-gray-200">
                        <template x-if="currentPricing.cacheWrite > 0">
                            <span>$<span x-text="currentPricing.cacheWrite.toFixed(2)"></span><span class="text-xs text-gray-500">/MTok</span></span>
                        </template>
                        <template x-if="currentPricing.cacheWrite === 0">
                            <span class="text-gray-500 text-sm">N/A</span>
                        </template>
                    </div>
                </div>

                <div class="bg-gray-900 rounded p-3">
                    <div class="text-xs text-gray-400 mb-1">Cache Read</div>
                    <div class="text-lg text-gray-200">$<span x-text="currentPricing.cacheRead.toFixed(3)"></span><span class="text-xs text-gray-500">/MTok</span></div>
                </div>
            </div>

            <div class="text-xs text-gray-500 mt-2">
                Prices are per million tokens. To modify pricing, edit <code class="bg-gray-900 px-1 rounded">config/ai.php</code>.
            </div>
        </div>
    </template>

    <x-button variant="primary" full-width class="mt-6" @click="showPricingSettings = false">
        Done
    </x-button>
</x-modal>
