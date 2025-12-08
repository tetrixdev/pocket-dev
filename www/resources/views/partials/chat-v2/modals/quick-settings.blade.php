{{-- Quick Settings Modal --}}
<div x-show="showQuickSettings"
     @click.self="showQuickSettings = false"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 backdrop-blur-sm"
     style="display: none;">
    <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 shadow-2xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Quick Settings</h2>

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

            {{-- Thinking Level --}}
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Thinking Budget</label>
                <div class="space-y-2">
                    <template x-for="(mode, index) in thinkingModes" :key="index">
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="thinkingLevel" :value="index" @change="saveDefaultSettings()" class="mr-2">
                            <span x-text="mode.icon" class="mr-1"></span>
                            <span x-text="mode.name"></span>
                            <span x-show="mode.tokens > 0" class="ml-2 text-xs text-gray-500" x-text="'(' + mode.tokens.toLocaleString() + ' tokens)'"></span>
                        </label>
                    </template>
                </div>
            </div>

            {{-- Response Level --}}
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Response Length</label>
                <div class="space-y-2">
                    <template x-for="(mode, index) in responseModes" :key="index">
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="responseLevel" :value="index" @change="saveDefaultSettings()" class="mr-2">
                            <span x-text="mode.icon" class="mr-1"></span>
                            <span x-text="mode.name"></span>
                            <span class="ml-2 text-xs text-gray-500" x-text="'(' + mode.tokens.toLocaleString() + ' tokens)'"></span>
                        </label>
                    </template>
                </div>
            </div>

            {{-- Action Buttons --}}
            <div class="flex gap-2 mt-6">
                <button @click="showQuickSettings = false"
                        class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition-all">
                    Done
                </button>
            </div>
        </div>
    </div>
</div>
