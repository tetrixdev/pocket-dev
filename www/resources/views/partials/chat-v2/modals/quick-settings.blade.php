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
        <x-button variant="primary" full-width class="mt-6" @click="showQuickSettings = false">
            Done
        </x-button>
    </div>
</x-modal>
