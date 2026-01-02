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
                {{-- File size --}}
                <span class="text-xs text-gray-500 hidden sm:inline" x-text="$store.filePreview.sizeFormatted"></span>

                {{-- Copy button - access stack directly for Alpine reactivity --}}
                <button @click="$store.filePreview.copyContent()"
                        class="p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                        title="Copy content"
                        x-show="$store.filePreview.stack.at(-1)?.content && !$store.filePreview.stack.at(-1)?.error">
                    <template x-if="!$store.filePreview.copied">
                        <i class="fa-regular fa-copy"></i>
                    </template>
                    <template x-if="$store.filePreview.copied">
                        <i class="fa-solid fa-check text-green-400"></i>
                    </template>
                </button>

                {{-- Close/Back button --}}
                <button @click="$store.filePreview.close()"
                        class="p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                        :title="$store.filePreview.stackDepth > 1 ? 'Back (Esc)' : 'Close (Esc)'">
                    <i :class="$store.filePreview.stackDepth > 1 ? 'fa-solid fa-arrow-left' : 'fa-solid fa-xmark'"></i>
                </button>
            </div>
        </div>

        {{-- Content Area --}}
        {{-- Handle file path link clicks here since @click.stop on modal prevents bubbling to document --}}
        <div class="flex-1 overflow-auto"
             @click="
                const link = $event.target.closest('.file-path-link');
                if (link) {
                    $event.preventDefault();
                    const filePath = link.dataset.filePath;
                    if (filePath) {
                        $store.filePreview.open(filePath);
                    }
                }
             ">
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

            {{-- File content --}}
            <template x-if="!$store.filePreview.loading && !$store.filePreview.error && $store.filePreview.content">
                <div class="h-full">
                    {{-- Markdown rendering --}}
                    <template x-if="$store.filePreview.isMarkdown">
                        <div class="p-4 md:p-6 markdown-content text-sm"
                             x-html="$store.filePreview.renderedContent"></div>
                    </template>

                    {{-- Plain text / code --}}
                    <template x-if="!$store.filePreview.isMarkdown">
                        <pre class="p-4 text-sm text-gray-300 font-mono whitespace-pre-wrap break-words"><code x-html="$store.filePreview.highlightedContent"></code></pre>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>
