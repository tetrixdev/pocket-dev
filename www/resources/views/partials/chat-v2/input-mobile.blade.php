{{-- Fixed Bottom Input (Mobile) --}}
<div class="fixed bottom-0 left-0 right-0 z-20 bg-gray-800 border-t border-gray-700 safe-area-bottom">
    {{-- Input Row --}}
    <div class="p-3">
        <input type="text"
               x-model="prompt"
               :disabled="isStreaming"
               @keydown.enter="sendMessage()"
               placeholder="Ask Claude..."
               class="w-full px-4 py-3 bg-gray-900 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white">
    </div>

    {{-- Controls Row 1: Thinking + Clear --}}
    <div class="px-3 pb-2 grid grid-cols-2 gap-2">
        {{-- Thinking Toggle --}}
        <button type="button"
                @click="cycleThinkingMode()"
                :class="thinkingModes[thinkingLevel].color"
                class="px-4 py-3 rounded-lg font-medium text-sm flex items-center justify-center transition-all">
            <span x-text="thinkingModes[thinkingLevel].icon"></span>
            <span class="ml-1" x-text="thinkingModes[thinkingLevel].name"></span>
        </button>

        {{-- Clear Button --}}
        <button type="button"
                @click="prompt = ''"
                class="px-4 py-3 bg-red-600 hover:bg-red-700 rounded-lg font-medium text-sm">
            Clear
        </button>
    </div>

    {{-- Controls Row 2: Voice + Send --}}
    <div class="px-3 pb-3 grid grid-cols-2 gap-2">
        {{-- Voice Button --}}
        <button type="button"
                @click="toggleVoiceRecording()"
                :class="voiceButtonClass"
                :disabled="isProcessing || isStreaming"
                class="px-4 py-3 rounded-lg font-semibold text-sm flex items-center justify-center"
                x-text="voiceButtonText">
        </button>

        {{-- Send Button --}}
        <button type="button"
                @click="handleSendClick($event); if(!isRecording) sendMessage()"
                :disabled="isStreaming || (!prompt.trim() && !isRecording)"
                :class="isStreaming ? 'bg-gray-600' : 'bg-green-600 hover:bg-green-700'"
                class="px-4 py-3 rounded-lg font-semibold text-sm disabled:cursor-not-allowed">
            <template x-if="!isStreaming">
                <span>Send</span>
            </template>
            <template x-if="isStreaming">
                <span>Streaming...</span>
            </template>
        </button>
    </div>
</div>
