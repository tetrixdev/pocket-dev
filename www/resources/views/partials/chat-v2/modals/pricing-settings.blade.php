{{-- Pricing Settings Modal --}}
<x-modal show="showPricingSettings" title="Pricing Settings" max-width="lg" class="max-h-[90vh] overflow-y-auto">
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

    <x-button variant="primary" full-width class="mt-6" @click="showPricingSettings = false">
        Done
    </x-button>
</x-modal>
