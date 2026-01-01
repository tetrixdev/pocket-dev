{{-- Message Details Modal (per-message) --}}
<x-modal show="showCostBreakdown" title="Message Details" max-width="lg">
    <template x-if="breakdownMessage">
        <div x-data="{ msgModel: breakdownMessage.model || model, msgPricing: getPricing(breakdownMessage.model || model) }">
            {{-- Agent row (if exists) --}}
            <template x-if="breakdownMessage.agent">
                <div class="mb-4">
                    <div class="text-sm text-gray-400 mb-2">Agent:</div>
                    <div class="text-gray-200 font-mono text-sm bg-gray-900 px-3 py-2 rounded">
                        <span x-text="breakdownMessage.agent.name"></span>
                    </div>
                </div>
            </template>

            {{-- Model row --}}
            <div class="mb-4">
                <div class="text-sm text-gray-400 mb-2">Model:</div>
                <div class="text-gray-200 font-mono text-sm bg-gray-900 px-3 py-2 rounded flex justify-between items-center">
                    <span x-text="msgPricing.name || msgModel"></span>
                    <template x-if="breakdownMessage.cost">
                        <button @click="openPricingForModel(msgModel); showCostBreakdown = false"
                                class="text-xs text-blue-400 hover:text-blue-300">
                            Edit pricing
                        </button>
                    </template>
                </div>
            </div>

            {{-- Token/Cost breakdown - only if cost exists --}}
            <template x-if="breakdownMessage.cost">
                <div class="space-y-2 mb-4">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-400">
                            Input Tokens
                            <span class="text-gray-600 text-xs">($<span x-text="msgPricing.input"></span>/MTok)</span>:
                        </span>
                        <div class="flex gap-4">
                            <span class="text-gray-200 font-mono" x-text="(breakdownMessage.inputTokens || 0).toLocaleString()"></span>
                            <span class="text-green-400 font-mono min-w-[80px] text-right" x-text="'$' + (((breakdownMessage.inputTokens || 0) * msgPricing.input) / 1000000).toFixed(6)"></span>
                        </div>
                    </div>

                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-400">
                            Cache Write
                            <span class="text-gray-600 text-xs">($<span x-text="msgPricing.cacheWrite"></span>/MTok)</span>:
                        </span>
                        <div class="flex gap-4">
                            <span class="text-gray-200 font-mono" x-text="(breakdownMessage.cacheCreationTokens || 0).toLocaleString()"></span>
                            <span class="text-green-400 font-mono min-w-[80px] text-right" x-text="'$' + (((breakdownMessage.cacheCreationTokens || 0) * msgPricing.cacheWrite) / 1000000).toFixed(6)"></span>
                        </div>
                    </div>

                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-400">
                            Cache Read
                            <span class="text-gray-600 text-xs">($<span x-text="msgPricing.cacheRead"></span>/MTok)</span>:
                        </span>
                        <div class="flex gap-4">
                            <span class="text-gray-200 font-mono" x-text="(breakdownMessage.cacheReadTokens || 0).toLocaleString()"></span>
                            <span class="text-green-400 font-mono min-w-[80px] text-right" x-text="'$' + (((breakdownMessage.cacheReadTokens || 0) * msgPricing.cacheRead) / 1000000).toFixed(6)"></span>
                        </div>
                    </div>

                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-400">
                            Output Tokens
                            <span class="text-gray-600 text-xs">($<span x-text="msgPricing.output"></span>/MTok)</span>:
                        </span>
                        <div class="flex gap-4">
                            <span class="text-gray-200 font-mono" x-text="(breakdownMessage.outputTokens || 0).toLocaleString()"></span>
                            <span class="text-green-400 font-mono min-w-[80px] text-right" x-text="'$' + (((breakdownMessage.outputTokens || 0) * msgPricing.output) / 1000000).toFixed(6)"></span>
                        </div>
                    </div>

                    <div class="border-t border-gray-700 pt-2 mt-2">
                        <div class="flex justify-between items-center font-semibold">
                            <span class="text-gray-200">Total:</span>
                            <span class="text-green-400 font-mono text-lg" x-text="'$' + (breakdownMessage.cost || 0).toFixed(4)"></span>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Token note - only if cost exists --}}
            <template x-if="breakdownMessage.cost">
                <div class="bg-amber-900/20 border border-amber-700/30 rounded-lg p-3 mb-4">
                    <div class="flex gap-2">
                        <svg class="w-4 h-4 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <div class="text-xs text-amber-200">
                            <strong class="text-amber-300">Note:</strong> Extended thinking tokens are billed but summarized for storage.
                        </div>
                    </div>
                </div>
            </template>

            {{-- No cost info message - only for CLI providers --}}
            <template x-if="!breakdownMessage.cost">
                <div class="bg-gray-900/50 border border-gray-700/30 rounded-lg p-4 mb-4 text-center">
                    <div class="text-sm text-gray-400">
                        Cost tracking not available for this provider.
                    </div>
                </div>
            </template>
        </div>
    </template>

    <x-button variant="primary" full-width @click="showCostBreakdown = false">
        Close
    </x-button>
</x-modal>
