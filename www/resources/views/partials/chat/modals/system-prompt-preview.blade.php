{{-- System Prompt Preview Modal - Responsive fullscreen on mobile, large modal on desktop --}}
<div x-show="showSystemPromptPreview"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="if (showSystemPromptPreview) showSystemPromptPreview = false"
     class="fixed inset-0 z-50 flex items-center justify-center"
     style="display: none;">

    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"
         @click="showSystemPromptPreview = false"></div>

    {{-- Modal Content --}}
    <div class="relative w-full h-full md:h-[85vh] md:max-h-[85vh] md:max-w-4xl md:mx-4 md:rounded-lg bg-gray-900 flex flex-col overflow-hidden shadow-2xl"
         @click.stop>

        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3 bg-gray-800 border-b border-gray-700 shrink-0">
            <div class="flex items-center gap-3 min-w-0 flex-1">
                <i class="fas fa-terminal text-purple-400 shrink-0"></i>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-gray-100">System Prompt Preview</div>
                    <div class="text-xs text-gray-500" x-text="systemPromptPreview.agentName ? 'Agent: ' + systemPromptPreview.agentName : ''"></div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-2 shrink-0 ml-2">
                <span class="text-xs text-gray-500 hidden sm:inline" x-show="systemPromptPreview.totalTokens > 0">
                    ~<span x-text="systemPromptPreview.totalTokens.toLocaleString()"></span> tokens
                </span>
                <button @click="copySystemPrompt()"
                        class="p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                        title="Copy full system prompt"
                        x-show="systemPromptPreview.sections.length > 0">
                    <template x-if="!systemPromptPreview.copied">
                        <i class="fa-regular fa-copy"></i>
                    </template>
                    <template x-if="systemPromptPreview.copied">
                        <i class="fa-solid fa-check text-green-400"></i>
                    </template>
                </button>
                <button @click="toggleAllSystemPromptSections()"
                        class="px-2 py-1 text-xs text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                        x-show="systemPromptPreview.sections.length > 0">
                    <span x-text="Object.keys(systemPromptPreview.expandedSections).length > 0 ? 'Collapse all' : 'Expand all'"></span>
                </button>
                <button @click="showSystemPromptPreview = false"
                        class="p-2 text-gray-400 hover:text-white transition-colors rounded hover:bg-gray-700"
                        title="Close (Esc)">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        {{-- Content Area --}}
        <div class="flex-1 overflow-auto p-4">
            {{-- Loading state --}}
            <template x-if="systemPromptPreview.loading">
                <div class="flex items-center justify-center h-full">
                    <div class="text-gray-400">
                        <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                        Loading system prompt...
                    </div>
                </div>
            </template>

            {{-- Error state --}}
            <template x-if="!systemPromptPreview.loading && systemPromptPreview.error">
                <div class="flex flex-col items-center justify-center h-full text-center p-6">
                    <i class="fa-solid fa-exclamation-triangle text-4xl text-yellow-500 mb-4"></i>
                    <div class="text-gray-300 mb-2" x-text="systemPromptPreview.error"></div>
                </div>
            </template>

            {{-- Sections - using shared partial with aliased variables --}}
            <template x-if="!systemPromptPreview.loading && !systemPromptPreview.error && systemPromptPreview.sections.length > 0">
                <div x-data="{
                    // Alias variables for the partial
                    get sections() { return systemPromptPreview.sections },
                    get expandedSections() { return systemPromptPreview.expandedSections },
                    get rawViewSections() { return systemPromptPreview.rawViewSections },
                    copiedSectionIdx: null,
                    toggleSection(path) { toggleSystemPromptSection(path) },
                    toggleRawView(path) { toggleSystemPromptRawView(path) },
                    parseMarkdown(text) { return parseMarkdownForSystemPrompt(text) },
                    copySectionContent(section, path) {
                        if (!section.content) return;
                        navigator.clipboard.writeText(section.content)
                            .then(() => {
                                this.copiedSectionIdx = path;
                                setTimeout(() => {
                                    if (this.copiedSectionIdx === path) {
                                        this.copiedSectionIdx = null;
                                    }
                                }, 1500);
                            })
                            .catch(err => {
                                console.error('Failed to copy:', err);
                            });
                    }
                }">
                    @include('partials.system-prompt-sections')

                    {{-- Footer info --}}
                    <div class="mt-4 pt-3 border-t border-gray-700 text-xs text-gray-500 flex items-center justify-between">
                        <span>Token estimate assumes ~4 characters per token</span>
                        <a href="https://claude-tokenizer.vercel.app/" target="_blank" class="text-blue-400 hover:text-blue-300">
                            Claude Tokenizer <i class="fas fa-external-link-alt ml-1"></i>
                        </a>
                    </div>
                </div>
            </template>

            {{-- Empty state --}}
            <template x-if="!systemPromptPreview.loading && !systemPromptPreview.error && systemPromptPreview.sections.length === 0">
                <div class="flex flex-col items-center justify-center h-full text-center p-6">
                    <i class="fas fa-terminal text-4xl text-gray-600 mb-4"></i>
                    <div class="text-gray-400">No system prompt sections available</div>
                </div>
            </template>
        </div>
    </div>
</div>
