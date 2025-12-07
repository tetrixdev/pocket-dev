{{-- OpenAI API Key Modal --}}
<div x-show="showOpenAiModal"
     @click.self="showOpenAiModal = false"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 backdrop-blur-sm"
     style="display: none;">
    <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 shadow-2xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Voice Transcription Setup</h2>

        <p class="text-gray-300 text-sm mb-4">
            Voice transcription uses OpenAI's Whisper API. Please enter your OpenAI API key to enable this feature.
        </p>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-300 mb-2">API Key</label>
            <input type="password"
                   x-model="openAiKeyInput"
                   placeholder="sk-..."
                   class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:outline-none focus:border-blue-500 text-white">
        </div>

        <p class="text-gray-500 text-xs mb-4">
            Your key is stored locally and used only for transcription. Get one at
            <a href="https://platform.openai.com/api-keys" target="_blank" class="text-blue-400 hover:underline">platform.openai.com</a>
        </p>

        <div class="flex gap-2">
            <button @click="showOpenAiModal = false"
                    class="flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg font-medium transition-all">
                Cancel
            </button>
            <button @click="saveOpenAiKey()"
                    class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition-all">
                Save & Record
            </button>
        </div>
    </div>
</div>
