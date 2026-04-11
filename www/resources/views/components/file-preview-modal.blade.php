{{-- File Preview Modal - Responsive fullscreen on mobile, large modal on desktop --}}
{{-- Supports stacked file previews - clicking a file path inside opens on top --}}
{{-- Note: This is intentionally standalone (not using x-modal) because it has stack-based navigation --}}
{{-- with different close behaviors (escape pops one, backdrop closes all) and its own history management --}}
<div x-data="{ mousedownOnBackdrop: false }"
     x-show="$store.filePreview.isOpen"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="$store.filePreview.close()"
     @mouseup.window="mousedownOnBackdrop = false"
     class="fixed inset-0 z-50 flex items-center justify-center"
     style="display: none;">

    {{-- Backdrop - only close if mousedown AND mouseup both on backdrop (prevents drag-select closing) --}}
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"
         @mousedown="mousedownOnBackdrop = true"
         @mouseup="if (mousedownOnBackdrop) $store.filePreview.closeAll()"></div>

    {{-- Modal Content --}}
    <div class="relative w-full h-full md:h-[85vh] md:max-h-[85vh] md:max-w-4xl md:mx-4 md:rounded-lg bg-gray-900 flex flex-col overflow-hidden shadow-2xl"
         @click.stop>

        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3 bg-gray-800 border-b border-gray-700 shrink-0">
            <div class="flex items-center gap-3 min-w-0 flex-1">
                {{-- File icon --}}
                <i :class="{
                    'fa-regular fa-image text-purple-400 shrink-0': $store.filePreview.isImage,
                    'fa-regular fa-file-lines text-blue-400 shrink-0': !$store.filePreview.isImage
                }"></i>

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
                {{-- File size (desktop only, hidden when editing) --}}
                <span class="text-xs text-gray-500 hidden sm:inline"
                      x-show="!$store.filePreview.editing"
                      x-text="$store.filePreview.sizeFormatted"></span>

                {{-- View mode buttons (desktop only for copy/download/edit, close/back always visible) --}}
                <template x-if="!$store.filePreview.editing">
                    <div class="flex items-center gap-2">
                        {{-- Copy button - desktop only, text files only --}}
                        <button @click="$store.filePreview.copyContent()"
                                class="hidden md:inline-flex p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                                title="Copy content"
                                x-show="!$store.filePreview.loading && !$store.filePreview.stack.at(-1)?.error && !$store.filePreview.isImage && !$store.filePreview.isBinary">
                            <template x-if="!$store.filePreview.copied">
                                <i class="fa-regular fa-copy"></i>
                            </template>
                            <template x-if="$store.filePreview.copied">
                                <i class="fa-solid fa-check text-green-400"></i>
                            </template>
                        </button>

                        {{-- Download button - desktop only, show for text files, binary files, AND images --}}
                        <button @click="$store.filePreview.downloadFile()"
                                class="hidden md:inline-flex p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                                title="Download file"
                                x-show="!$store.filePreview.loading && $store.filePreview.readable">
                            <i class="fa-solid fa-download"></i>
                        </button>

                        {{-- Edit button - desktop only, text files only --}}
                        <button @click="$store.filePreview.startEditing()"
                                class="hidden md:inline-flex p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                                title="Edit file"
                                x-show="!$store.filePreview.loading && !$store.filePreview.stack.at(-1)?.error && !$store.filePreview.isImage && !$store.filePreview.isBinary">
                            <i class="fa-regular fa-pen-to-square"></i>
                        </button>

                        {{-- Close/Back button - always visible --}}
                        <button @click="$store.filePreview.close()"
                                class="p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                                :title="$store.filePreview.stackDepth > 1 ? 'Back (Esc)' : 'Close (Esc)'">
                            <i :class="$store.filePreview.stackDepth > 1 ? 'fa-solid fa-arrow-left' : 'fa-solid fa-xmark'"></i>
                        </button>
                    </div>
                </template>

                {{-- Edit mode buttons (desktop only) --}}
                <template x-if="$store.filePreview.editing">
                    <div class="flex items-center gap-2">
                        {{-- Cancel button --}}
                        <button @click="$store.filePreview.cancelEditing()"
                                class="hidden md:inline-flex px-3 py-1.5 text-sm text-gray-300 hover:text-white transition-colors rounded hover:bg-gray-700"
                                :disabled="$store.filePreview.saving">
                            Cancel
                        </button>

                        {{-- Save button --}}
                        <button @click="$store.filePreview.saveFile()"
                                class="hidden md:inline-flex px-3 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded transition-colors items-center gap-2"
                                :disabled="$store.filePreview.saving"
                                :class="{ 'opacity-50 cursor-not-allowed': $store.filePreview.saving }">
                            <template x-if="$store.filePreview.saving">
                                <x-spinner />
                            </template>
                            <span x-text="$store.filePreview.saving ? 'Saving...' : 'Save'"></span>
                        </button>

                        {{-- Close/Back button - always visible during edit mode too --}}
                        <button @click="$store.filePreview.cancelEditing(); $store.filePreview.close()"
                                class="md:hidden p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                                :title="$store.filePreview.stackDepth > 1 ? 'Back (Esc)' : 'Close (Esc)'">
                            <i :class="$store.filePreview.stackDepth > 1 ? 'fa-solid fa-arrow-left' : 'fa-solid fa-xmark'"></i>
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
                    <div class="text-gray-400 flex items-center">
                        <x-spinner class="mr-2" />
                        Loading file...
                    </div>
                </div>
            </template>

            {{-- Error state (not for images - they have their own view) --}}
            <template x-if="!$store.filePreview.loading && $store.filePreview.error && !$store.filePreview.isImage">
                <div class="flex flex-col items-center justify-center h-full text-center p-6">
                    <i class="fa-solid fa-exclamation-triangle text-4xl text-yellow-500 mb-4"></i>
                    <div class="text-gray-300 mb-2" x-text="$store.filePreview.error"></div>
                    <div class="text-sm text-gray-500" x-text="$store.filePreview.path"></div>
                </div>
            </template>

            {{-- Image preview --}}
            <template x-if="!$store.filePreview.loading && $store.filePreview.isImage">
                <div x-data="{ previewFailed: false }"
                     class="flex items-center justify-center h-full p-4 bg-gray-950/50">
                    <img x-show="!previewFailed"
                         :src="'/api/file/download?path=' + encodeURIComponent($store.filePreview.path)"
                         :alt="$store.filePreview.filename"
                         x-on:error="previewFailed = true"
                         class="max-w-full max-h-full object-contain rounded shadow-lg">
                    <div x-show="previewFailed" x-cloak class="text-center text-gray-400">
                        <i class="fa-regular fa-image text-3xl mb-3"></i>
                        <div class="text-sm">Image preview unavailable.</div>
                        <div class="text-xs text-gray-500 mt-1">Use Download to open the file.</div>
                    </div>
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

        {{-- Mobile bottom action bar (view mode) - hidden on desktop --}}
        <template x-if="!$store.filePreview.editing">
            <div class="flex md:hidden items-center justify-center gap-6 px-4 py-3 bg-gray-800 border-t border-gray-700 shrink-0"
                 x-show="!$store.filePreview.loading">
                {{-- Copy button - text files only (not images, not binary) --}}
                <button @click="$store.filePreview.copyContent()"
                        class="flex flex-col items-center gap-1 text-gray-400 active:text-white transition-colors"
                        x-show="!$store.filePreview.stack.at(-1)?.error && !$store.filePreview.isImage && !$store.filePreview.isBinary">
                    <template x-if="!$store.filePreview.copied">
                        <i class="fa-regular fa-copy text-lg"></i>
                    </template>
                    <template x-if="$store.filePreview.copied">
                        <i class="fa-solid fa-check text-lg text-green-400"></i>
                    </template>
                    <span class="text-xs" x-text="$store.filePreview.copied ? 'Copied' : 'Copy'"></span>
                </button>

                {{-- Download button - show for text files, binary files, AND images --}}
                <button @click="$store.filePreview.downloadFile()"
                        class="flex flex-col items-center gap-1 text-gray-400 active:text-white transition-colors"
                        x-show="$store.filePreview.readable">
                    <i class="fa-solid fa-download text-lg"></i>
                    <span class="text-xs">Download</span>
                </button>

                {{-- Edit button - text files only (not images, not binary) --}}
                <button @click="$store.filePreview.startEditing()"
                        class="flex flex-col items-center gap-1 text-gray-400 active:text-white transition-colors"
                        x-show="!$store.filePreview.stack.at(-1)?.error && !$store.filePreview.isImage && !$store.filePreview.isBinary">
                    <i class="fa-regular fa-pen-to-square text-lg"></i>
                    <span class="text-xs">Edit</span>
                </button>
            </div>
        </template>

        {{-- Mobile bottom action bar (edit mode) - hidden on desktop --}}
        <template x-if="$store.filePreview.editing">
            <div class="flex md:hidden items-center justify-between px-4 py-3 bg-gray-800 border-t border-gray-700 shrink-0">
                {{-- Cancel button --}}
                <button @click="$store.filePreview.cancelEditing()"
                        class="px-4 py-2 text-sm text-gray-300 active:text-white transition-colors rounded hover:bg-gray-700"
                        :disabled="$store.filePreview.saving">
                    Cancel
                </button>

                {{-- Save button --}}
                <button @click="$store.filePreview.saveFile()"
                        class="px-4 py-2 text-sm bg-blue-600 active:bg-blue-700 text-white rounded transition-colors flex items-center gap-2"
                        :disabled="$store.filePreview.saving"
                        :class="{ 'opacity-50': $store.filePreview.saving }">
                    <template x-if="$store.filePreview.saving">
                        <x-spinner />
                    </template>
                    <span x-text="$store.filePreview.saving ? 'Saving...' : 'Save'"></span>
                </button>
            </div>
        </template>
    </div>
</div>
