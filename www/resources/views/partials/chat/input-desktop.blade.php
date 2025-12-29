{{-- Desktop Input Form --}}
<div class="border-t border-gray-700 p-3 bg-gray-800">
    <form @submit.prevent="sendMessage()" class="flex gap-2 items-end">
        {{-- Voice Button --}}
        <button type="button"
                @click="toggleVoiceRecording()"
                :class="voiceButtonClass"
                :disabled="isProcessing || isStreaming || waitingForFinalTranscript"
                class="px-4 py-[10px] min-w-[106px] rounded-lg font-medium text-sm flex items-center justify-center gap-2 transition-colors cursor-pointer disabled:cursor-not-allowed"
                title="Voice input (Ctrl+Space)"
                @keydown.ctrl.space.window.prevent="toggleVoiceRecording()"
                x-html="voiceButtonText">
        </button>

        {{-- Input Field (auto-grow, max 6 rows) --}}
        <textarea x-model="prompt"
                  x-ref="promptInput"
                  :disabled="isStreaming"
                  placeholder="Hey PocketDev, can you..."
                  rows="1"
                  class="flex-1 px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white resize-none"
                  style="height: 40px; min-height: 40px; max-height: 168px; overflow-y: hidden;"
                  x-effect="prompt; $nextTick(() => { $el.style.height = 'auto'; const sh = $el.scrollHeight; $el.style.height = Math.min(sh, 168) + 'px'; $el.style.overflowY = sh > 168 ? 'auto' : 'hidden'; if (prompt) $el.scrollTop = $el.scrollHeight; })"
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

        {{-- Send/Stop Button --}}
        <template x-if="isStreaming && _streamState.abortPending">
            <button type="button"
                    disabled
                    class="px-4 py-[10px] rounded-lg font-medium text-sm flex items-center justify-center gap-2 transition-colors cursor-not-allowed bg-gray-600 text-gray-300">
                <i class="fa-solid fa-spinner fa-spin"></i> Aborting...
            </button>
        </template>
        <template x-if="isStreaming && !_streamState.abortPending">
            <button type="button"
                    @click="abortStream()"
                    class="px-4 py-[10px] rounded-lg font-medium text-sm flex items-center justify-center gap-2 transition-colors cursor-pointer bg-rose-600/90 hover:bg-rose-500 text-white">
                <i class="fa-solid fa-stop"></i> Stop
            </button>
        </template>
        <template x-if="!isStreaming">
            <button type="submit"
                    @click="handleSendClick($event)"
                    :disabled="isProcessing || isRecording || waitingForFinalTranscript || !prompt.trim()"
                    :class="isProcessing || isRecording || waitingForFinalTranscript ? 'bg-gray-600 text-gray-400' : 'bg-emerald-600/90 hover:bg-emerald-500 text-white'"
                    class="px-4 py-[10px] rounded-lg font-medium text-sm flex items-center justify-center gap-2 transition-colors cursor-pointer disabled:cursor-not-allowed">
                <i class="fa-solid fa-paper-plane"></i> Send
            </button>
        </template>
    </form>
</div>
