{{-- Cost Breakdown Modal (per-message) --}}
<div x-show="showCostBreakdown"
     @click.self="showCostBreakdown = false"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 backdrop-blur-sm"
     style="display: none;">
    <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-lg w-full mx-4 shadow-2xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Cost Breakdown</h2>

        <template x-if="breakdownMessage">
            <div x-data="{ msgModel: breakdownMessage.model || model, msgPricing: getPricing(breakdownMessage.model || model) }">
                <div class="mb-4">
                    <div class="text-sm text-gray-400 mb-2">Model:</div>
                    <div class="text-gray-200 font-mono text-sm bg-gray-900 px-3 py-2 rounded flex justify-between items-center">
                        <span x-text="msgPricing.name || msgModel"></span>
                        <button @click="openPricingForModel(msgModel); showCostBreakdown = false"
                                class="text-xs text-blue-400 hover:text-blue-300">
                            Edit pricing
                        </button>
                    </div>
                </div>

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
            </div>
        </template>

        <button @click="showCostBreakdown = false"
                class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-white font-medium">
            Close
        </button>
    </div>
</div>
