{{-- Fixed Bottom Input (Mobile) --}}
<div class="fixed bottom-0 left-0 right-0 z-20 bg-gray-800 border-t border-gray-700 safe-area-bottom">
    {{-- Input Row --}}
    <div class="p-2">
        <textarea x-model="prompt"
                  x-ref="promptInput"
                  :disabled="isStreaming"
                  @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); sendMessage(); }"
                  placeholder="Ask AI..."
                  rows="1"
                  class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white resize-none overflow-y-auto"
                  style="height: 40px; max-height: 120px;"></textarea>
    </div>

    {{-- Controls Row: Square icon buttons --}}
    <div class="px-2 pb-2 grid grid-cols-4 gap-2">
        {{-- Voice Button --}}
        <button type="button"
                @click="toggleVoiceRecording()"
                :class="voiceButtonClass"
                :disabled="isProcessing || isStreaming || waitingForFinalTranscript"
                class="aspect-square rounded-lg text-3xl flex items-center justify-center"
                title="Voice input">
            <span x-text="isProcessing ? '⏳' : (waitingForFinalTranscript ? '⏳' : (isRecording ? '⏹️' : '🎙️'))"></span>
        </button>

        {{-- Reasoning Toggle --}}
        <button type="button"
                @click="cycleReasoningLevel()"
                :class="{
                    'bg-gray-600 text-gray-200': currentReasoningName === 'Off',
                    'bg-blue-600 text-white': currentReasoningName === 'Light',
                    'bg-purple-600 text-white': currentReasoningName === 'Standard',
                    'bg-pink-600 text-white': currentReasoningName === 'Deep',
                    'bg-yellow-600 text-white': currentReasoningName === 'Maximum'
                }"
                class="aspect-square rounded-lg text-3xl flex items-center justify-center"
                title="Toggle reasoning">
            <span x-text="currentReasoningName === 'Off' ? '🧠' : (currentReasoningName === 'Light' ? '💭' : (currentReasoningName === 'Standard' ? '🤔' : (currentReasoningName === 'Deep' ? '🧩' : '🌟')))"></span>
        </button>

        {{-- Clear Button --}}
        <button type="button"
                @click="prompt = ''"
                class="aspect-square bg-red-600 hover:bg-red-700 rounded-lg text-3xl flex items-center justify-center"
                title="Clear input">
            🗑️
        </button>

        {{-- Send Button --}}
        <button type="button"
                @click="handleSendClick($event); if(!isRecording && !isProcessing && !waitingForFinalTranscript) sendMessage()"
                :disabled="isStreaming || isProcessing || isRecording || waitingForFinalTranscript || !prompt.trim()"
                :class="isStreaming || isProcessing || isRecording || waitingForFinalTranscript ? 'bg-gray-600' : 'bg-green-600 hover:bg-green-700'"
                class="aspect-square rounded-lg text-3xl flex items-center justify-center disabled:cursor-not-allowed"
                title="Send message">
            <span x-text="isStreaming ? '⏳' : '➤'"></span>
        </button>
    </div>
</div>
