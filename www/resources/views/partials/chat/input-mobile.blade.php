{{-- Fixed Bottom Input (Mobile) --}}
<div x-ref="mobileInput" class="fixed bottom-0 left-0 right-0 z-20 bg-gray-800 border-t border-gray-700 safe-area-bottom">
    {{-- Single Row: Voice | Textarea | Send --}}
    <div class="p-2 flex gap-2 items-end">
        {{-- Voice Button --}}
        <button type="button"
                @click="toggleVoiceRecording()"
                :class="voiceButtonClass"
                :disabled="isProcessing || isStreaming || waitingForFinalTranscript"
                class="w-12 py-[10px] rounded-lg text-xl flex items-center justify-center transition-colors cursor-pointer disabled:cursor-not-allowed"
                title="Voice input">
            <i :class="isProcessing || waitingForFinalTranscript ? 'fa-solid fa-spinner fa-spin' : (isRecording ? 'fa-solid fa-stop' : 'fa-solid fa-microphone')"></i>
        </button>

        {{-- Textarea --}}
        <textarea x-model="prompt"
                  x-ref="promptInput"
                  :disabled="isStreaming"
                  placeholder="Hey PocketDev, can you..."
                  rows="1"
                  class="flex-1 px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white resize-none"
                  style="height: 40px; min-height: 40px; max-height: 168px; overflow-y: hidden;"
                  x-effect="prompt; $nextTick(() => { $el.style.height = 'auto'; const sh = $el.scrollHeight; $el.style.height = Math.min(sh, 168) + 'px'; $el.style.overflowY = sh > 168 ? 'auto' : 'hidden'; if (prompt) $el.scrollTop = $el.scrollHeight; })"
                  @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); sendMessage(); }"></textarea>

        {{-- Send/Stop Button --}}
        <template x-if="isStreaming && _streamState.abortPending">
            <button type="button"
                    disabled
                    class="w-12 py-[10px] rounded-lg text-xl flex items-center justify-center transition-colors cursor-not-allowed bg-gray-600 text-gray-300"
                    title="Aborting...">
                <i class="fa-solid fa-spinner fa-spin"></i>
            </button>
        </template>
        <template x-if="isStreaming && !_streamState.abortPending">
            <button type="button"
                    @click="abortStream()"
                    class="w-12 py-[10px] rounded-lg text-xl flex items-center justify-center transition-colors cursor-pointer bg-rose-600/90 hover:bg-rose-500 text-white"
                    title="Stop streaming">
                <i class="fa-solid fa-stop"></i>
            </button>
        </template>
        <template x-if="!isStreaming">
            <button type="button"
                    @click="handleSendClick($event); if(!isRecording && !isProcessing && !waitingForFinalTranscript) sendMessage()"
                    :disabled="isProcessing || isRecording || waitingForFinalTranscript || Alpine.store('attachments').isUploading || (!prompt.trim() && !Alpine.store('attachments').hasFiles)"
                    :class="isProcessing || isRecording || waitingForFinalTranscript || Alpine.store('attachments').isUploading ? 'bg-gray-600/80 text-gray-400' : 'bg-emerald-500/90 hover:bg-emerald-400 text-white'"
                    class="w-12 py-[10px] rounded-lg text-xl flex items-center justify-center transition-colors cursor-pointer disabled:cursor-not-allowed"
                    title="Send message">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </template>
    </div>
</div>
