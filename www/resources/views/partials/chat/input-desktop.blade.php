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

        {{-- Attachment Button --}}
        <div x-data="{
            showModal: false,
            get attachments() { return Alpine.store('attachments'); },
            get hasAnyFiles() { return this.attachments.files.length > 0; },
            get isUploading() { return this.attachments.isUploading; },
            openFilePicker() {
                this.$refs.desktopFileInput.click();
            },
            handleFileSelect(event) {
                const files = event.target.files;
                for (const file of files) {
                    this.attachments.addFile(file);
                }
                event.target.value = '';
            },
            handleClick() {
                if (this.hasAnyFiles) {
                    this.showModal = true;
                } else {
                    this.openFilePicker();
                }
            },
            confirmClearAll() {
                if (confirm('Remove all attachments?')) {
                    this.attachments.clear();
                    if (this.attachments.files.length === 0) {
                        this.showModal = false;
                    }
                }
            }
        }">
            <input type="file"
                   x-ref="desktopFileInput"
                   @change="handleFileSelect($event)"
                   multiple
                   class="hidden"
                   accept="*/*">

            <button type="button"
                    @click="handleClick()"
                    :class="hasAnyFiles ? 'bg-blue-600 hover:bg-blue-500 text-white' : 'bg-gray-600 hover:bg-gray-500 text-gray-200'"
                    class="relative px-4 py-[10px] min-w-[106px] rounded-lg font-medium text-sm flex items-center justify-center gap-2 transition-colors cursor-pointer"
                    title="Attach files">
                <i x-show="isUploading" class="fa-solid fa-spinner fa-spin"></i>
                <i x-show="!isUploading" class="fa-solid fa-paperclip"></i>
                <span x-show="!hasAnyFiles">Attach</span>
                <span x-show="hasAnyFiles"
                      x-text="'(' + (attachments.count > 9 ? '\u221E' : attachments.count) + ')'"></span>
            </button>

            {{-- Desktop Attachments Modal --}}
            <div x-show="showModal"
                 x-cloak
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 @keydown.escape.window="showModal = false"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">

                <div @click.outside="showModal = false"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     class="w-full max-w-md bg-gray-800 rounded-xl shadow-xl max-h-[70vh] flex flex-col">

                    {{-- Header --}}
                    <div class="flex items-center justify-between p-4 border-b border-gray-700">
                        <h3 class="text-lg font-semibold text-white">
                            Attachments
                            <span x-show="attachments.count > 0" class="text-gray-400 font-normal">(<span x-text="attachments.count"></span>)</span>
                        </h3>
                        <button @click="showModal = false" class="text-gray-400 hover:text-white p-1">
                            <i class="fa-solid fa-times text-xl"></i>
                        </button>
                    </div>

                    {{-- File List --}}
                    <div class="flex-1 overflow-y-auto p-4 space-y-3">
                        <template x-for="file in attachments.files" :key="file.id">
                            <div class="flex items-center justify-between p-3 bg-gray-700 rounded-lg">
                                <div class="flex-1 min-w-0 mr-3">
                                    <p class="text-sm text-gray-200 truncate" x-text="file.filename"></p>
                                    <p class="text-xs text-gray-400" x-text="file.sizeFormatted"></p>
                                    <p x-show="file.error" class="text-xs text-red-400 mt-1" x-text="file.error"></p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <template x-if="file.uploading">
                                        <i class="fa-solid fa-spinner fa-spin text-blue-400"></i>
                                    </template>
                                    <template x-if="!file.uploading && !file.error">
                                        <i class="fa-solid fa-check text-green-400"></i>
                                    </template>
                                    <template x-if="file.error">
                                        <i class="fa-solid fa-exclamation-triangle text-red-400"></i>
                                    </template>
                                    <button @click="attachments.removeFile(file.id)"
                                            class="text-gray-400 hover:text-red-400 p-1 transition-colors">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </template>

                        {{-- Empty State --}}
                        <template x-if="attachments.files.length === 0">
                            <div class="text-center py-8">
                                <i class="fa-solid fa-paperclip text-4xl text-gray-600 mb-3"></i>
                                <p class="text-gray-400">No files attached</p>
                                <p class="text-gray-500 text-sm mt-1">Click the button below or drag files onto the chat</p>
                            </div>
                        </template>
                    </div>

                    {{-- Actions --}}
                    <div class="p-4 border-t border-gray-700">
                        <div class="flex gap-2">
                            <button @click="openFilePicker()"
                                    class="flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg font-medium transition-colors">
                                <i class="fa-solid fa-plus mr-2"></i>Add Files
                            </button>
                            <button @click="confirmClearAll()"
                                    x-show="attachments.files.length > 0"
                                    class="px-4 py-2 bg-red-600/80 hover:bg-red-500 text-white rounded-lg font-medium transition-colors">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
                    :disabled="isProcessing || isRecording || waitingForFinalTranscript || Alpine.store('attachments').isUploading || (!prompt.trim() && !Alpine.store('attachments').hasFiles)"
                    :class="isProcessing || isRecording || waitingForFinalTranscript || Alpine.store('attachments').isUploading ? 'bg-gray-600 text-gray-400' : 'bg-emerald-600/90 hover:bg-emerald-500 text-white'"
                    class="px-4 py-[10px] rounded-lg font-medium text-sm flex items-center justify-center gap-2 transition-colors cursor-pointer disabled:cursor-not-allowed">
                <i class="fa-solid fa-paper-plane"></i> Send
            </button>
        </template>
    </form>
</div>
