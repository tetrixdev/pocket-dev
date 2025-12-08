{{-- OpenAI API Key Modal --}}
<x-modal show="showOpenAiModal" title="Voice Transcription Setup">
    <p class="text-gray-300 text-sm mb-4">
        Voice transcription uses OpenAI's Whisper API. Please enter your OpenAI API key to enable this feature.
    </p>

    <x-text-input
        type="password"
        x-model="openAiKeyInput"
        placeholder="sk-..."
        label="API Key"
        class="mb-4"
    />

    <p class="text-gray-500 text-xs mb-4">
        Your key is stored locally and used only for transcription. Get one at
        <a href="https://platform.openai.com/api-keys" target="_blank" class="text-blue-400 hover:underline">platform.openai.com</a>
    </p>

    <div class="flex gap-2">
        <x-button variant="secondary" class="flex-1" @click="showOpenAiModal = false">
            Cancel
        </x-button>
        <x-button variant="primary" class="flex-1" @click="saveOpenAiKey()">
            Save & Record
        </x-button>
    </div>
</x-modal>
