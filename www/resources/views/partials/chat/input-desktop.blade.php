{{-- Desktop Input Form --}}
<div class="border-t border-gray-700 p-3">
    <form @submit.prevent="sendMessage()" class="flex gap-2 items-center">
        {{-- Voice Button --}}
        <button type="button"
                @click="toggleVoiceRecording()"
                :class="voiceButtonClass"
                :disabled="isProcessing || isStreaming || waitingForFinalTranscript"
                class="p-4 rounded-lg font-medium text-sm flex items-center justify-center"
                title="Voice input (Ctrl+Space)"
                @keydown.ctrl.space.window.prevent="toggleVoiceRecording()"
                x-text="voiceButtonText">
        </button>

        {{-- Input Field (fixed 2-row height) --}}
        <textarea x-model="prompt"
                  x-ref="promptInput"
                  :disabled="isStreaming"
                  placeholder="Ask AI to help with your code..."
                  rows="2"
                  class="flex-1 px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white resize-none overflow-y-auto"
                  style="height: 52px; max-height: 200px;"
                  @keydown.ctrl.t.prevent="cycleReasoningLevel()"
                  @keydown.ctrl.space.prevent="toggleVoiceRecording()"
                  @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); sendMessage(); }"></textarea>

        {{-- Reasoning Toggle - DISABLED: Now relying on agent's thinking level instead of per-message override.
             Uncomment to re-enable per-message reasoning control.
        <button type="button"
                @click="cycleReasoningLevel()"
                :class="{
                    'bg-gray-600 text-gray-200': currentReasoningName === 'Off',
                    'bg-blue-600 text-white': currentReasoningName === 'Light',
                    'bg-purple-600 text-white': currentReasoningName === 'Standard',
                    'bg-pink-600 text-white': currentReasoningName === 'Deep',
                    'bg-yellow-600 text-white': currentReasoningName === 'Maximum'
                }"
                class="px-4 py-4 rounded-lg font-medium text-sm cursor-pointer transition-all duration-200 hover:opacity-80 flex items-center justify-center"
                title="Click to toggle reasoning (Ctrl+T)">
            <span x-text="currentReasoningName === 'Off' ? '🧠' : (currentReasoningName === 'Light' ? '💭' : (currentReasoningName === 'Standard' ? '🤔' : (currentReasoningName === 'Deep' ? '🧩' : '🌟')))"></span>
            <span class="ml-1" x-text="currentReasoningName"></span>
        </button>
        --}}

        {{-- Send Button --}}
        <button type="submit"
                @click="handleSendClick($event)"
                :disabled="isStreaming || isProcessing || isRecording || waitingForFinalTranscript || !prompt.trim()"
                :class="isStreaming || isProcessing || isRecording || waitingForFinalTranscript ? 'bg-gray-600' : 'bg-green-600 hover:bg-green-700'"
                class="p-4 rounded-lg flex items-center justify-center disabled:cursor-not-allowed">
            <template x-if="!isStreaming">
                <span>▶️ Send</span>
            </template>
            <template x-if="isStreaming">
                <span>⏳ Streaming...</span>
            </template>
        </button>
    </form>
</div>
