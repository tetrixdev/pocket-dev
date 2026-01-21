{{-- File Preview Modal - Responsive fullscreen on mobile, large modal on desktop --}}
{{-- Supports stacked file previews - clicking a file path inside opens on top --}}
<div x-data
     x-show="$store.filePreview.isOpen"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="$store.filePreview.close()"
     class="fixed inset-0 z-50 flex items-center justify-center"
     style="display: none;">

    {{-- Backdrop - closes all on click --}}
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"
         @click="$store.filePreview.closeAll()"></div>

    {{-- Modal Content --}}
    <div class="relative w-full h-full md:h-[85vh] md:max-h-[85vh] md:max-w-4xl md:mx-4 md:rounded-lg bg-gray-900 flex flex-col overflow-hidden shadow-2xl"
         @click.stop>

        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3 bg-gray-800 border-b border-gray-700 shrink-0">
            <div class="flex items-center gap-3 min-w-0 flex-1">
                {{-- File icon --}}
                <i class="fa-regular fa-file-lines text-blue-400 shrink-0"></i>

                {{-- Filename and path --}}
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-100 truncate" x-text="$store.filePreview.filename"></span>
                        {{-- Stack depth indicator --}}
                        <span x-show="$store.filePreview.stackDepth > 1"
                              class="text-xs bg-blue-600 text-white px-1.5 py-0.5 rounded-full"
                              x-text="$store.filePreview.stackDepth"></span>
                    </div>
                    <div class="text-xs text-gray-500 break-all" x-text="$store.filePreview.path"></div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-2 shrink-0 ml-2">
                {{-- File size (hidden when editing) --}}
                <span class="text-xs text-gray-500 hidden sm:inline"
                      x-show="!$store.filePreview.editing"
                      x-text="$store.filePreview.sizeFormatted"></span>

                {{-- View mode buttons --}}
                <template x-if="!$store.filePreview.editing">
                    <div class="flex items-center gap-2">
                        {{-- Copy button - show when file loaded (even if empty) --}}
                        <button @click="$store.filePreview.copyContent()"
                                class="p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                                title="Copy content"
                                x-show="!$store.filePreview.loading && !$store.filePreview.stack.at(-1)?.error">
                            <template x-if="!$store.filePreview.copied">
                                <i class="fa-regular fa-copy"></i>
                            </template>
                            <template x-if="$store.filePreview.copied">
                                <i class="fa-solid fa-check text-green-400"></i>
                            </template>
                        </button>

                        {{-- Edit button - show when file loaded (even if empty) --}}
                        <button @click="$store.filePreview.startEditing()"
                                class="p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                                title="Edit file"
                                x-show="!$store.filePreview.loading && !$store.filePreview.stack.at(-1)?.error">
                            <i class="fa-regular fa-pen-to-square"></i>
                        </button>

                        {{-- Close/Back button --}}
                        <button @click="$store.filePreview.close()"
                                class="p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                                :title="$store.filePreview.stackDepth > 1 ? 'Back (Esc)' : 'Close (Esc)'">
                            <i :class="$store.filePreview.stackDepth > 1 ? 'fa-solid fa-arrow-left' : 'fa-solid fa-xmark'"></i>
                        </button>
                    </div>
                </template>

                {{-- Edit mode buttons --}}
                <template x-if="$store.filePreview.editing">
                    <div class="flex items-center gap-2">
                        {{-- Cancel button --}}
                        <button @click="$store.filePreview.cancelEditing()"
                                class="px-3 py-1.5 text-sm text-gray-300 hover:text-white transition-colors rounded hover:bg-gray-700"
                                :disabled="$store.filePreview.saving">
                            Cancel
                        </button>

                        {{-- Save button --}}
                        <button @click="$store.filePreview.saveFile()"
                                class="px-3 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded transition-colors flex items-center gap-2"
                                :disabled="$store.filePreview.saving"
                                :class="{ 'opacity-50 cursor-not-allowed': $store.filePreview.saving }">
                            <template x-if="$store.filePreview.saving">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                            </template>
                            <span x-text="$store.filePreview.saving ? 'Saving...' : 'Save'"></span>
                        </button>
                    </div>
                </template>
            </div>
        </div>

        {{-- Content Area --}}
        {{-- Handle file path link clicks here since @click.stop on modal prevents bubbling to document --}}
        <div class="flex-1 overflow-auto flex flex-col"
             @click="
                if ($store.filePreview.editing) return;
                const link = $event.target.closest('.file-path-link');
                if (link) {
                    $event.preventDefault();
                    const filePath = link.dataset.filePath;
                    if (filePath) {
                        $store.filePreview.open(filePath);
                    }
                }
             ">
            {{-- Save error banner --}}
            <template x-if="$store.filePreview.saveError">
                <div class="px-4 py-2 bg-red-900/50 border-b border-red-700 text-red-300 text-sm flex items-center gap-2">
                    <i class="fa-solid fa-exclamation-circle"></i>
                    <span x-text="$store.filePreview.saveError"></span>
                </div>
            </template>

            {{-- Loading state --}}
            <template x-if="$store.filePreview.loading">
                <div class="flex items-center justify-center h-full">
                    <div class="text-gray-400">
                        <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                        Loading file...
                    </div>
                </div>
            </template>

            {{-- Error state --}}
            <template x-if="!$store.filePreview.loading && $store.filePreview.error">
                <div class="flex flex-col items-center justify-center h-full text-center p-6">
                    <i class="fa-solid fa-exclamation-triangle text-4xl text-yellow-500 mb-4"></i>
                    <div class="text-gray-300 mb-2" x-text="$store.filePreview.error"></div>
                    <div class="text-sm text-gray-500" x-text="$store.filePreview.path"></div>
                </div>
            </template>

            {{-- Edit mode - textarea --}}
            <template x-if="!$store.filePreview.loading && !$store.filePreview.error && $store.filePreview.editing">
                <div class="flex-1 flex flex-col min-h-0">
                    <textarea x-model="$store.filePreview.editContent"
                              class="flex-1 w-full p-4 bg-gray-900 text-gray-300 font-mono text-sm resize-none border-none focus:ring-0 focus:outline-none"
                              :disabled="$store.filePreview.saving"
                              spellcheck="false"
                              x-init="$nextTick(() => $el.focus())"></textarea>
                </div>
            </template>

            {{-- View mode - File content --}}
            <template x-if="!$store.filePreview.loading && !$store.filePreview.error && $store.filePreview.content && !$store.filePreview.editing">
                <div class="h-full">
                    {{-- HTML rendering (emails, web pages) - scaled to fit --}}
                    <template x-if="$store.filePreview.isHtml">
                        <div x-data="{
                                containerWidth: 0,
                                iframeWidth: 700,
                                get scale() {
                                    if (this.containerWidth <= 0) return 1;
                                    return Math.min(1, this.containerWidth / this.iframeWidth);
                                }
                             }"
                             x-init="containerWidth = $el.offsetWidth; new ResizeObserver(entries => containerWidth = entries[0].contentRect.width).observe($el)"
                             class="w-full h-full overflow-hidden">
                            <iframe class="bg-white origin-top-left"
                                    :style="`width: ${iframeWidth}px; height: ${100 / scale}%; transform: scale(${scale});`"
                                    :srcdoc="$store.filePreview.sanitizedHtml"
                                    sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                    referrerpolicy="no-referrer"></iframe>
                        </div>
                    </template>

                    {{-- Markdown rendering --}}
                    <template x-if="$store.filePreview.isMarkdown && !$store.filePreview.isHtml">
                        <div class="p-4 md:p-6 markdown-content text-sm"
                             x-html="$store.filePreview.renderedContent"></div>
                    </template>

                    {{-- Plain text / code --}}
                    <template x-if="!$store.filePreview.isMarkdown && !$store.filePreview.isHtml">
                        <pre class="p-4 text-sm text-gray-300 font-mono whitespace-pre-wrap break-words"><code x-html="$store.filePreview.highlightedContent"></code></pre>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>
