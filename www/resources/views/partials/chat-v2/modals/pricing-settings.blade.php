{{-- Pricing Settings Modal --}}
<div x-show="showPricingSettings"
     @click.self="showPricingSettings = false"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 backdrop-blur-sm"
     style="display: none;">
    <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-lg w-full mx-4 shadow-2xl max-h-[90vh] overflow-y-auto">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Pricing Settings</h2>

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

        {{-- Pricing Inputs for Selected Model --}}
        <template x-if="currentPricing">
            <div class="space-y-3 border-t border-gray-700 pt-4">
                <div class="text-sm text-gray-300 font-medium mb-2">
                    Pricing for: <span class="text-blue-400" x-text="currentPricing.name || pricingModel"></span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Input ($/MTok):</label>
                        <input type="number"
                               :value="currentPricing.input"
                               @change="updateModelPricing(pricingModel, 'input', $event.target.value)"
                               step="0.01"
                               class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-gray-200 focus:outline-none focus:border-blue-500 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Output ($/MTok):</label>
                        <input type="number"
                               :value="currentPricing.output"
                               @change="updateModelPricing(pricingModel, 'output', $event.target.value)"
                               step="0.01"
                               class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-gray-200 focus:outline-none focus:border-blue-500 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Cache Write ($/MTok):</label>
                        <input type="number"
                               :value="currentPricing.cacheWrite"
                               @change="updateModelPricing(pricingModel, 'cacheWrite', $event.target.value)"
                               step="0.01"
                               class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-gray-200 focus:outline-none focus:border-blue-500 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Cache Read ($/MTok):</label>
                        <input type="number"
                               :value="currentPricing.cacheRead"
                               @change="updateModelPricing(pricingModel, 'cacheRead', $event.target.value)"
                               step="0.01"
                               class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-gray-200 focus:outline-none focus:border-blue-500 text-sm">
                    </div>
                </div>

                <div class="text-xs text-gray-500 mt-2">
                    Prices are per million tokens. Changes are saved automatically.
                </div>
            </div>
        </template>

        <div class="flex gap-2 mt-6">
            <button @click="showPricingSettings = false"
                    class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-white font-medium">
                Done
            </button>
        </div>
    </div>
</div>
