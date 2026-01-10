{{--
    System Prompt Preview Sections Partial

    Expected Alpine variables in scope:
    - sections: array of section objects with title, content, children, estimated_tokens, chars
    - expandedSections: object tracking which paths are expanded (e.g., {"0": true, "0.1": true})
    - rawViewSections: object tracking which paths show raw markdown
    - toggleSection(path): function to toggle expand/collapse
    - toggleRawView(path): function to toggle raw/rendered view
    - parseMarkdown(text): function to render markdown
    - Optional: copySectionContent(section, path), copiedSectionIdx for copy functionality
--}}

<div class="space-y-3">
    <template x-for="(section, idx) in sections" :key="idx">
        <div class="bg-gray-800 border border-gray-700 rounded overflow-hidden">
            {{-- Level 0: Top-level section --}}
            <div
                class="flex items-center justify-between px-4 py-2 bg-gray-750 cursor-pointer hover:bg-gray-700 transition-colors"
                @click="toggleSection(String(idx))"
            >
                <div class="flex items-center gap-2 min-w-0">
                    <i
                        class="fa-solid fa-chevron-right text-gray-400 text-xs transition-transform duration-200"
                        :class="{ 'rotate-90': expandedSections[String(idx)] }"
                    ></i>
                    <span class="font-medium text-white truncate" x-text="section.title"></span>
                    <template x-if="section.children && section.children.length">
                        <span class="text-xs text-gray-500" x-text="'(' + section.children.length + ' items)'"></span>
                    </template>
                    <span class="text-xs text-gray-500 hidden sm:inline" x-text="section.source || ''"></span>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-xs text-blue-400 font-medium" x-text="'~' + (section.estimated_tokens || 0).toLocaleString() + ' tokens'"></span>
                    <span class="text-xs text-gray-500 hidden sm:inline" x-text="'(' + (section.chars || 0).toLocaleString() + ' chars)'"></span>
                    {{-- Raw/Markdown toggle --}}
                    <button
                        type="button"
                        @click.stop="toggleRawView(String(idx))"
                        class="text-gray-400 hover:text-white transition-colors"
                        :title="rawViewSections[String(idx)] ? 'Show rendered' : 'Show raw markdown'"
                        x-show="section.content"
                    >
                        <i class="fa-solid text-sm" :class="rawViewSections[String(idx)] ? 'fa-eye' : 'fa-code'"></i>
                    </button>
                    {{-- Copy button --}}
                    <template x-if="typeof copySectionContent === 'function'">
                        <button
                            type="button"
                            @click.stop="copySectionContent(section, String(idx))"
                            class="text-gray-400 hover:text-white transition-colors"
                            title="Copy section"
                            x-show="section.content"
                        >
                            <template x-if="copiedSectionIdx !== String(idx)">
                                <i class="fa-regular fa-copy text-sm"></i>
                            </template>
                            <template x-if="copiedSectionIdx === String(idx)">
                                <span class="text-green-400 text-xs font-medium">Copied!</span>
                            </template>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Level 0 Content and/or Children --}}
            <div x-show="expandedSections[String(idx)]" x-collapse class="border-t border-gray-700">
                {{-- Section intro content (shown before children if exists) --}}
                <template x-if="section.content && section.children && section.children.length">
                    <div class="p-4 border-b border-gray-700/50 bg-gray-900/50">
                        <div x-show="!rawViewSections[String(idx)]" class="prose-preview text-sm" x-html="parseMarkdown(section.content)"></div>
                        <pre x-show="rawViewSections[String(idx)]" class="text-xs text-gray-300 whitespace-pre-wrap font-mono" x-text="section.content"></pre>
                    </div>
                </template>

                {{-- Direct content only (no children) --}}
                <template x-if="section.content && (!section.children || !section.children.length)">
                    <div class="p-4 overflow-y-auto bg-gray-900/50">
                        <div x-show="!rawViewSections[String(idx)]" class="prose-preview text-sm" x-html="parseMarkdown(section.content)"></div>
                        <pre x-show="rawViewSections[String(idx)]" class="text-xs text-gray-300 whitespace-pre-wrap font-mono" x-text="section.content"></pre>
                    </div>
                </template>

                {{-- Level 1: Children --}}
                <template x-if="section.children && section.children.length">
                    <div class="divide-y divide-gray-700">
                        <template x-for="(child1, idx1) in section.children" :key="idx1">
                            <div>
                                <div
                                    class="flex items-center justify-between px-4 py-2 pl-8 bg-gray-800 cursor-pointer hover:bg-gray-750 transition-colors"
                                    @click="toggleSection(idx + '.' + idx1)"
                                >
                                    <div class="flex items-center gap-2 min-w-0">
                                        <i
                                            class="fa-solid fa-chevron-right text-gray-500 text-xs transition-transform duration-200"
                                            :class="{ 'rotate-90': expandedSections[idx + '.' + idx1] }"
                                        ></i>
                                        <span class="text-gray-200 truncate" x-text="child1.title"></span>
                                        <template x-if="child1.children && child1.children.length">
                                            <span class="text-xs text-gray-500" x-text="'(' + child1.children.length + ' items)'"></span>
                                        </template>
                                    </div>
                                    <div class="flex items-center gap-3 flex-shrink-0">
                                        <span class="text-xs text-blue-400/80" x-text="'~' + (child1.estimated_tokens || 0).toLocaleString() + ' tokens'"></span>
                                        {{-- Raw/Markdown toggle --}}
                                        <button
                                            type="button"
                                            @click.stop="toggleRawView(idx + '.' + idx1)"
                                            class="text-gray-500 hover:text-white transition-colors"
                                            x-show="child1.content"
                                        >
                                            <i class="fa-solid text-sm" :class="rawViewSections[idx + '.' + idx1] ? 'fa-eye' : 'fa-code'"></i>
                                        </button>
                                        {{-- Copy button --}}
                                        <template x-if="typeof copySectionContent === 'function'">
                                            <button
                                                type="button"
                                                @click.stop="copySectionContent(child1, idx + '.' + idx1)"
                                                class="text-gray-500 hover:text-white transition-colors"
                                                title="Copy section"
                                                x-show="child1.content"
                                            >
                                                <template x-if="copiedSectionIdx !== (idx + '.' + idx1)">
                                                    <i class="fa-regular fa-copy text-sm"></i>
                                                </template>
                                                <template x-if="copiedSectionIdx === (idx + '.' + idx1)">
                                                    <span class="text-green-400 text-xs font-medium">Copied!</span>
                                                </template>
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                {{-- Level 1 Content and/or Children --}}
                                <div x-show="expandedSections[idx + '.' + idx1]" x-collapse class="bg-gray-850">
                                    {{-- Group intro content (shown before children if exists) --}}
                                    <template x-if="child1.content && child1.children && child1.children.length">
                                        <div class="px-4 py-2 border-b border-gray-700/50 bg-gray-900/50">
                                            <div x-show="!rawViewSections[idx + '.' + idx1]" class="prose-preview text-sm" x-html="parseMarkdown(child1.content)"></div>
                                            <pre x-show="rawViewSections[idx + '.' + idx1]" class="text-xs text-gray-300 whitespace-pre-wrap font-mono" x-text="child1.content"></pre>
                                        </div>
                                    </template>

                                    {{-- Direct content only (no children) --}}
                                    <template x-if="child1.content && (!child1.children || !child1.children.length)">
                                        <div class="p-4 overflow-y-auto bg-gray-900/50">
                                            <div x-show="!rawViewSections[idx + '.' + idx1]" class="prose-preview text-sm" x-html="parseMarkdown(child1.content)"></div>
                                            <pre x-show="rawViewSections[idx + '.' + idx1]" class="text-xs text-gray-300 whitespace-pre-wrap font-mono" x-text="child1.content"></pre>
                                        </div>
                                    </template>

                                    {{-- Level 2: Children --}}
                                    <template x-if="child1.children && child1.children.length">
                                        <div class="divide-y divide-gray-700/50">
                                            <template x-for="(child2, idx2) in child1.children" :key="idx2">
                                                <div>
                                                    <div
                                                        class="flex items-center justify-between px-4 py-1.5 pl-12 bg-gray-850 cursor-pointer hover:bg-gray-800 transition-colors"
                                                        @click="toggleSection(idx + '.' + idx1 + '.' + idx2)"
                                                    >
                                                        <div class="flex items-center gap-2 min-w-0">
                                                            <i
                                                                class="fa-solid fa-chevron-right text-gray-600 text-xs transition-transform duration-200"
                                                                :class="{ 'rotate-90': expandedSections[idx + '.' + idx1 + '.' + idx2] }"
                                                            ></i>
                                                            <span class="text-gray-300 text-sm truncate" x-text="child2.title"></span>
                                                            <template x-if="child2.children && child2.children.length">
                                                                <span class="text-xs text-gray-600" x-text="'(' + child2.children.length + ')'"></span>
                                                            </template>
                                                        </div>
                                                        <div class="flex items-center gap-2 flex-shrink-0">
                                                            <span class="text-xs text-blue-400/60" x-text="'~' + (child2.estimated_tokens || 0).toLocaleString() + ' tokens'"></span>
                                                            {{-- Raw/Markdown toggle --}}
                                                            <button
                                                                type="button"
                                                                @click.stop="toggleRawView(idx + '.' + idx1 + '.' + idx2)"
                                                                class="text-gray-600 hover:text-white transition-colors"
                                                                x-show="child2.content"
                                                            >
                                                                <i class="fa-solid text-xs" :class="rawViewSections[idx + '.' + idx1 + '.' + idx2] ? 'fa-eye' : 'fa-code'"></i>
                                                            </button>
                                                            {{-- Copy button --}}
                                                            <template x-if="typeof copySectionContent === 'function'">
                                                                <button
                                                                    type="button"
                                                                    @click.stop="copySectionContent(child2, idx + '.' + idx1 + '.' + idx2)"
                                                                    class="text-gray-600 hover:text-white transition-colors"
                                                                    title="Copy section"
                                                                    x-show="child2.content"
                                                                >
                                                                    <template x-if="copiedSectionIdx !== (idx + '.' + idx1 + '.' + idx2)">
                                                                        <i class="fa-regular fa-copy text-sm"></i>
                                                                    </template>
                                                                    <template x-if="copiedSectionIdx === (idx + '.' + idx1 + '.' + idx2)">
                                                                        <span class="text-green-400 text-xs font-medium">Copied!</span>
                                                                    </template>
                                                                </button>
                                                            </template>
                                                        </div>
                                                    </div>

                                                    {{-- Level 2 Content --}}
                                                    <div x-show="expandedSections[idx + '.' + idx1 + '.' + idx2]" x-collapse>
                                                        <template x-if="child2.content && (!child2.children || !child2.children.length)">
                                                            <div class="p-4 bg-gray-900/50 overflow-y-auto">
                                                                <div x-show="!rawViewSections[idx + '.' + idx1 + '.' + idx2]" class="prose-preview text-sm" x-html="parseMarkdown(child2.content || '')"></div>
                                                                <pre x-show="rawViewSections[idx + '.' + idx1 + '.' + idx2]" class="text-xs text-gray-300 whitespace-pre-wrap font-mono" x-text="child2.content || ''"></pre>
                                                            </div>
                                                        </template>

                                                        {{-- Level 3: Children --}}
                                                        <template x-if="child2.children && child2.children.length">
                                                            <div class="divide-y divide-gray-700/30">
                                                                <template x-for="(child3, idx3) in child2.children" :key="idx3">
                                                                    <div>
                                                                        <div
                                                                            class="flex items-center justify-between px-4 py-1 pl-16 bg-gray-900/30 cursor-pointer hover:bg-gray-850 transition-colors"
                                                                            @click="toggleSection(idx + '.' + idx1 + '.' + idx2 + '.' + idx3)"
                                                                        >
                                                                            <div class="flex items-center gap-2 min-w-0">
                                                                                <i
                                                                                    class="fa-solid fa-chevron-right text-gray-600 text-[10px] transition-transform duration-200"
                                                                                    :class="{ 'rotate-90': expandedSections[idx + '.' + idx1 + '.' + idx2 + '.' + idx3] }"
                                                                                ></i>
                                                                                <span class="text-gray-400 text-xs truncate" x-text="child3.title"></span>
                                                                            </div>
                                                                            <div class="flex items-center gap-2 flex-shrink-0">
                                                                                <span class="text-xs text-blue-400/40" x-text="'~' + (child3.estimated_tokens || 0).toLocaleString() + ' tokens'"></span>
                                                                                {{-- Raw/Markdown toggle --}}
                                                                                <button
                                                                                    type="button"
                                                                                    @click.stop="toggleRawView(idx + '.' + idx1 + '.' + idx2 + '.' + idx3)"
                                                                                    class="text-gray-600 hover:text-white transition-colors"
                                                                                    x-show="child3.content"
                                                                                >
                                                                                    <i class="fa-solid text-[10px]" :class="rawViewSections[idx + '.' + idx1 + '.' + idx2 + '.' + idx3] ? 'fa-eye' : 'fa-code'"></i>
                                                                                </button>
                                                                                {{-- Copy button --}}
                                                                                <template x-if="typeof copySectionContent === 'function'">
                                                                                    <button
                                                                                        type="button"
                                                                                        @click.stop="copySectionContent(child3, idx + '.' + idx1 + '.' + idx2 + '.' + idx3)"
                                                                                        class="text-gray-600 hover:text-white transition-colors"
                                                                                        title="Copy section"
                                                                                        x-show="child3.content"
                                                                                    >
                                                                                        <template x-if="copiedSectionIdx !== (idx + '.' + idx1 + '.' + idx2 + '.' + idx3)">
                                                                                            <i class="fa-regular fa-copy text-sm"></i>
                                                                                        </template>
                                                                                        <template x-if="copiedSectionIdx === (idx + '.' + idx1 + '.' + idx2 + '.' + idx3)">
                                                                                            <span class="text-green-400 text-xs font-medium">Copied!</span>
                                                                                        </template>
                                                                                    </button>
                                                                                </template>
                                                                            </div>
                                                                        </div>

                                                                        {{-- Level 3 Content --}}
                                                                        <div x-show="expandedSections[idx + '.' + idx1 + '.' + idx2 + '.' + idx3]" x-collapse>
                                                                            <template x-if="child3.content">
                                                                                <div class="p-4 bg-gray-900/50 overflow-y-auto">
                                                                                    <div x-show="!rawViewSections[idx + '.' + idx1 + '.' + idx2 + '.' + idx3]" class="prose-preview text-sm" x-html="parseMarkdown(child3.content || '')"></div>
                                                                                    <pre x-show="rawViewSections[idx + '.' + idx1 + '.' + idx2 + '.' + idx3]" class="text-xs text-gray-300 whitespace-pre-wrap font-mono" x-text="child3.content || ''"></pre>
                                                                                </div>
                                                                            </template>
                                                                        </div>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
