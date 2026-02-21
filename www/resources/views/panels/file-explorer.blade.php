{{-- File Explorer Panel --}}
{{-- Uses inline x-data for compatibility with dynamic loading via x-html + Alpine.initTree() --}}
{{-- Two-view pattern: file tree ↔ file viewer (same approach as git-status panel) --}}
<div class="h-full bg-gray-900 text-gray-200 flex flex-col overflow-hidden"
     x-data="{
         rootPath: @js($rootPath),
         expanded: @js($expanded),
         selected: @js($selected),
         loadedPaths: @js($loadedPaths),
         loadingPaths: [],
         panelStateId: @js($panelStateId),
         syncTimeout: null,

         // File viewer state
         viewingFile: @js($viewingFile),
         fileContent: null,
         fileLoading: false,
         fileError: null,
         fileAbortController: null,
         copied: false,
         copiedPath: false,

         // Edit state
         editing: false,
         editContent: '',
         saving: false,
         saveError: null,

         // Settings state
         settings: Object.assign(
             { showSize: true, showModified: false, showOwner: false, showPermissions: false },
             @js($settings ?? [])
         ),
         showSettings: false,

         // Download state
         downloading: false,

         init() {
             // Restore file viewer if viewingFile was persisted in state
             if (this.viewingFile) {
                 this.openFile(this.viewingFile.path, this.viewingFile.name, true);
             }
         },

         // --- File Tree Methods ---

         async toggle(path, depth) {
             const idx = this.expanded.indexOf(path);
             if (idx === -1) {
                 // Expanding - check if we need to load children
                 if (!this.loadedPaths.includes(path)) {
                     await this.loadChildren(path, depth);
                 }
                 this.expanded.push(path);
             } else {
                 // Collapsing
                 this.expanded.splice(idx, 1);
             }
             // Sync state to server (debounced)
             this.syncState();
         },

         async loadChildren(path, depth) {
             if (this.loadingPaths.includes(path)) return;

             this.loadingPaths.push(path);

             try {
                 const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({
                         action: 'loadChildren',
                         params: { path, depth }
                     })
                 });

                 if (!response.ok) {
                     throw new Error('Failed to load children');
                 }

                 const result = await response.json();

                 if (result.ok && result.html) {
                     // Find the children container and insert HTML
                     const container = document.querySelector(`[data-children-for='${CSS.escape(path)}']`);
                     if (container) {
                         container.innerHTML = result.html;
                         // Initialize Alpine on new content
                         Alpine.initTree(container);
                     }

                     // Update loaded paths
                     if (!this.loadedPaths.includes(path)) {
                         this.loadedPaths.push(path);
                     }
                 }
             } catch (err) {
                 console.error('Failed to load children:', err);
             } finally {
                 const idx = this.loadingPaths.indexOf(path);
                 if (idx !== -1) {
                     this.loadingPaths.splice(idx, 1);
                 }
             }
         },

         isExpanded(path) {
             return this.expanded.includes(path);
         },

         isLoading(path) {
             return this.loadingPaths.includes(path);
         },

         refresh() {
             window.location.reload();
         },

         // --- File Viewer Methods ---

         async openFile(path, name, skipSync = false) {
             // Cancel any previous in-flight fetch
             if (this.fileAbortController) {
                 this.fileAbortController.abort();
             }
             this.fileAbortController = new AbortController();

             // Reset edit state
             this.editing = false;
             this.editContent = '';
             this.saveError = null;

             this.viewingFile = { path, name };
             this.selected = path;
             this.fileLoading = true;
             this.fileError = null;
             this.fileContent = null;

             try {
                 const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'Accept': 'application/json',
                     },
                     signal: this.fileAbortController.signal,
                     body: JSON.stringify({
                         action: 'readFile',
                         params: { path }
                     })
                 });

                 if (!response.ok) {
                     throw new Error('Server returned ' + response.status);
                 }

                 const result = await response.json();

                 if (result.ok && result.data) {
                     this.fileContent = result.data;
                     // Apply syntax highlighting after DOM update
                     if (result.data.type === 'text') {
                         this.$nextTick(() => this.highlightCode());
                     }
                 } else {
                     this.fileError = result.error || 'Failed to load file';
                 }
             } catch (e) {
                 if (e.name === 'AbortError') return;
                 this.fileError = 'Network error: ' + e.message;
             }

             this.fileLoading = false;
             if (!skipSync) {
                 this.syncState(true);
             }
         },

         closeFile() {
             if (this.fileAbortController) {
                 this.fileAbortController.abort();
                 this.fileAbortController = null;
             }
             this.viewingFile = null;
             this.fileContent = null;
             this.fileError = null;
             this.fileLoading = false;
             this.editing = false;
             this.editContent = '';
             this.saveError = null;
             this.syncState(true);
         },

         highlightCode() {
             if (typeof hljs !== 'undefined') {
                 const block = this.$refs.codeBlock;
                 if (block) {
                     // Reset any previous highlighting
                     block.removeAttribute('data-highlighted');
                     hljs.highlightElement(block);
                 }
             }
         },

         async copyContent() {
             if (this.fileContent?.type === 'text' && this.fileContent?.content) {
                 try {
                     await navigator.clipboard.writeText(this.fileContent.content);
                     this.copied = true;
                     setTimeout(() => this.copied = false, 2000);
                 } catch (e) {
                     console.error('Copy failed:', e);
                 }
             }
         },

         async copyPath() {
             if (this.viewingFile?.path) {
                 try {
                     await navigator.clipboard.writeText(this.viewingFile.path);
                     this.copiedPath = true;
                     setTimeout(() => this.copiedPath = false, 2000);
                 } catch (e) {
                     console.error('Copy failed:', e);
                 }
             }
         },

         // --- Edit Methods ---

         startEditing() {
             if (!this.fileContent || this.fileContent.type !== 'text') return;
             this.editContent = this.fileContent.content || '';
             this.editing = true;
             this.saveError = null;
             this.$nextTick(() => {
                 const ta = this.$refs.editTextarea;
                 if (ta) ta.focus();
             });
         },

         cancelEditing() {
             this.editing = false;
             this.editContent = '';
             this.saveError = null;
         },

         async saveFile() {
             if (!this.editing || this.saving || !this.viewingFile) return;

             this.saving = true;
             this.saveError = null;

             try {
                 const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({
                         action: 'writeFile',
                         params: {
                             path: this.viewingFile.path,
                             content: this.editContent,
                         }
                     })
                 });

                 if (!response.ok) {
                     throw new Error('Server returned ' + response.status);
                 }

                 const result = await response.json();

                 if (result.ok && result.data?.success) {
                     // Update in-memory content and exit edit mode
                     this.fileContent.content = this.editContent;
                     this.fileContent.size = result.data.bytesWritten;
                     this.fileContent.sizeFormatted = result.data.sizeFormatted;
                     this.fileContent.truncated = false;
                     this.editing = false;
                     this.editContent = '';
                     // Re-highlight code
                     this.$nextTick(() => this.highlightCode());
                 } else {
                     this.saveError = result.error || 'Failed to save file';
                 }
             } catch (e) {
                 this.saveError = 'Network error: ' + e.message;
             }

             this.saving = false;
         },

         // --- Download Method ---

         base64ToBlob(b64, mime) {
             // Decode in chunks to avoid freezing the browser on large files.
             // Slice at multiples of 4 characters to maintain valid base64 boundaries.
             const CHUNK = 524288; // 512 KB of base64 chars (produces ~384 KB decoded)
             const parts = [];
             for (let off = 0; off < b64.length; off += CHUNK) {
                 const chunk = atob(b64.slice(off, off + CHUNK));
                 const bytes = new Uint8Array(chunk.length);
                 for (let i = 0; i < chunk.length; i++) bytes[i] = chunk.charCodeAt(i);
                 parts.push(bytes);
             }
             return new Blob(parts, { type: mime || 'application/octet-stream' });
         },

         async downloadFile() {
             if (!this.viewingFile || this.downloading) return;
             this.downloading = true;

             try {
                 let blob;
                 const name = this.viewingFile.name;

                 if (this.fileContent?.type === 'text' && this.fileContent?.content != null) {
                     // Text content already loaded — create blob directly
                     blob = new Blob([this.fileContent.content], { type: 'text/plain' });
                 } else if (this.fileContent?.type === 'image' && this.fileContent?.base64) {
                     // Image already loaded as base64
                     blob = this.base64ToBlob(this.fileContent.base64, this.fileContent.mime);
                 } else {
                     // Binary or not loaded — fetch from server
                     const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
                         method: 'POST',
                         headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                         body: JSON.stringify({ action: 'downloadFile', params: { path: this.viewingFile.path } })
                     });
                     if (!response.ok) {
                         throw new Error('Server returned ' + response.status);
                     }
                     const result = await response.json();
                     if (!result.ok || !result.data?.base64) {
                         this.fileError = result.error || 'Download failed';
                         this.downloading = false;
                         return;
                     }
                     blob = this.base64ToBlob(result.data.base64, result.data.mime);
                 }

                 // Trigger browser download — use parent document for better
                 // mobile iframe support, fall back to current document
                 const url = URL.createObjectURL(blob);
                 try {
                     const doc = window.parent?.document || document;
                     const a = doc.createElement('a');
                     a.href = url;
                     a.download = name;
                     a.style.display = 'none';
                     doc.body.appendChild(a);
                     a.click();
                     doc.body.removeChild(a);
                     URL.revokeObjectURL(url);
                 } catch (e) {
                     // Cross-origin fallback: open in new tab — delay revocation
                     // so the new tab has time to load the blob
                     window.open(url, '_blank');
                     setTimeout(() => URL.revokeObjectURL(url), 30000);
                 }
             } catch (e) {
                 this.fileError = 'Download error: ' + e.message;
             }

             this.downloading = false;
         },

         formatBytes(bytes) {
             if (!bytes) return '0 B';
             if (bytes < 1024) return bytes + ' B';
             if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
             return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
         },

         // --- State Sync ---

         // Debounced state sync to server
         syncState(immediate = false) {
             if (this.syncTimeout) {
                 clearTimeout(this.syncTimeout);
             }

             const doSync = () => {
                 this.doSyncState();
             };

             if (immediate) {
                 doSync();
             } else {
                 this.syncTimeout = setTimeout(doSync, 300);
             }
         },

         async doSyncState() {
             if (!this.panelStateId) return;

             try {
                 await fetch(`/api/panel/${this.panelStateId}/state`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({
                         state: {
                             expanded: this.expanded,
                             selected: this.selected,
                             loadedPaths: this.loadedPaths,
                             viewingFile: this.viewingFile,
                             settings: this.settings,
                         },
                         merge: true
                     })
                 });
             } catch (err) {
                 console.error('Failed to sync panel state:', err);
             }
         },

         select(path) {
             this.selected = path;
             this.syncState();
         }
     }">

    {{-- ===== FILE TREE VIEW ===== --}}
    <div x-show="!viewingFile" class="h-full flex flex-col">
        {{-- Sticky Header --}}
        <div class="flex-none flex items-center gap-2 p-4 pb-2 border-b border-gray-700 bg-gray-900">
            <i class="fa-solid fa-folder-tree text-blue-400"></i>
            <span class="font-medium text-sm truncate" x-text="rootPath"></span>
            {{-- SSH indicator --}}
            @if(!empty($sshLabel))
                <span class="text-xs bg-purple-500/20 text-purple-300 px-2 py-0.5 rounded-full flex items-center gap-1.5 shrink-0">
                    <i class="fa-solid fa-terminal text-[9px]"></i>
                    {{ $sshLabel }}
                </span>
            @endif

            <div class="ml-auto flex items-center gap-1">
                <button @click="refresh()"
                        class="p-1.5 hover:bg-gray-700 rounded transition-colors"
                        title="Refresh">
                    <i class="fa-solid fa-rotate text-gray-400 hover:text-white text-sm"></i>
                </button>

                {{-- Settings dropdown --}}
                <div class="relative">
                    <button @click="showSettings = !showSettings"
                            class="p-1.5 hover:bg-gray-700 rounded transition-colors"
                            :class="{ 'bg-gray-700': showSettings }"
                            title="Display settings">
                        <i class="fa-solid fa-sliders text-gray-400 hover:text-white text-sm"></i>
                    </button>

                    <div x-show="showSettings"
                         @click.away="showSettings = false"
                         x-cloak
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="absolute right-0 top-full mt-1 w-44 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-20 py-1">
                        <div class="px-3 py-1.5 text-[10px] text-gray-500 uppercase tracking-wider font-medium">Show columns</div>
                        <label class="flex items-center gap-2.5 px-3 py-1.5 hover:bg-gray-700/50 cursor-pointer text-sm text-gray-300">
                            <input type="checkbox" x-model="settings.showPermissions" @change="syncState()"
                                   class="w-3.5 h-3.5 rounded bg-gray-700 border-gray-600 text-blue-500 focus:ring-0 focus:ring-offset-0">
                            <span>Permissions</span>
                        </label>
                        <label class="flex items-center gap-2.5 px-3 py-1.5 hover:bg-gray-700/50 cursor-pointer text-sm text-gray-300">
                            <input type="checkbox" x-model="settings.showOwner" @change="syncState()"
                                   class="w-3.5 h-3.5 rounded bg-gray-700 border-gray-600 text-blue-500 focus:ring-0 focus:ring-offset-0">
                            <span>Owner / Group</span>
                        </label>
                        <label class="flex items-center gap-2.5 px-3 py-1.5 hover:bg-gray-700/50 cursor-pointer text-sm text-gray-300">
                            <input type="checkbox" x-model="settings.showModified" @change="syncState()"
                                   class="w-3.5 h-3.5 rounded bg-gray-700 border-gray-600 text-blue-500 focus:ring-0 focus:ring-offset-0">
                            <span>Date modified</span>
                        </label>
                        <label class="flex items-center gap-2.5 px-3 py-1.5 hover:bg-gray-700/50 cursor-pointer text-sm text-gray-300">
                            <input type="checkbox" x-model="settings.showSize" @change="syncState()"
                                   class="w-3.5 h-3.5 rounded bg-gray-700 border-gray-600 text-blue-500 focus:ring-0 focus:ring-offset-0">
                            <span>Size</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        {{-- Scrollable Content --}}
        <div class="flex-1 overflow-auto p-4 pt-3">
            @if(!empty($error ?? null))
                <div class="flex items-start gap-3 p-3 bg-red-500/10 border border-red-500/20 rounded-lg text-sm">
                    <i class="fa-solid fa-circle-exclamation text-red-400 mt-0.5"></i>
                    <span class="text-red-300">{{ $error }}</span>
                </div>
            @else
                <div class="space-y-0.5 min-w-fit">
                    @foreach($tree as $item)
                        @include('panels.partials.file-tree-item', ['item' => $item, 'depth' => 0])
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ===== FILE VIEWER ===== --}}
    <div x-show="viewingFile" x-cloak class="h-full flex flex-col">
        {{-- Viewer Header --}}
        <div class="flex-none p-2 md:p-3 border-b border-gray-700 bg-gray-800/50">
            <div class="flex items-center gap-2">
                {{-- Back button --}}
                <button @click="closeFile()"
                        class="p-1.5 hover:bg-gray-700 rounded text-gray-400 hover:text-white transition-colors"
                        title="Back to file list">
                    <i class="fa-solid fa-arrow-left text-sm"></i>
                </button>

                {{-- File info --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-200 truncate" x-text="viewingFile?.name"></span>
                    </div>
                    <div class="flex items-center gap-3 mt-0.5">
                        <span class="text-xs text-gray-500" x-text="fileContent?.sizeFormatted || ''"></span>
                        <span x-show="fileContent?.truncated" class="text-xs text-yellow-500">
                            <i class="fa-solid fa-scissors text-[10px]"></i> truncated
                        </span>
                        <span x-show="fileContent?.language && fileContent?.language !== 'plaintext'" class="text-xs text-gray-600" x-text="fileContent?.language"></span>
                    </div>
                </div>

                {{-- View mode buttons --}}
                <div x-show="!editing" class="flex items-center gap-1 shrink-0">
                    {{-- Copy path --}}
                    <button @click="copyPath()"
                            class="p-1.5 hover:bg-gray-700 rounded text-gray-400 hover:text-white transition-colors"
                            title="Copy file path">
                        <template x-if="!copiedPath">
                            <i class="fa-solid fa-link text-xs"></i>
                        </template>
                        <template x-if="copiedPath">
                            <i class="fa-solid fa-check text-xs text-green-400"></i>
                        </template>
                    </button>

                    {{-- Download file --}}
                    <button @click="downloadFile()"
                            class="p-1.5 hover:bg-gray-700 rounded text-gray-400 hover:text-white transition-colors"
                            :class="{ 'opacity-50 cursor-not-allowed': downloading }"
                            :disabled="downloading"
                            title="Download file">
                        <i x-show="!downloading" class="fa-solid fa-download text-xs"></i>
                        <svg x-show="downloading" x-cloak class="animate-spin w-3 h-3" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    </button>

                    {{-- Copy content (text files only) --}}
                    <button x-show="fileContent?.type === 'text'"
                            @click="copyContent()"
                            class="p-1.5 hover:bg-gray-700 rounded text-gray-400 hover:text-white transition-colors"
                            title="Copy content">
                        <template x-if="!copied">
                            <i class="fa-regular fa-copy text-xs"></i>
                        </template>
                        <template x-if="copied">
                            <i class="fa-solid fa-check text-xs text-green-400"></i>
                        </template>
                    </button>

                    {{-- Edit button (text files only, not truncated) --}}
                    <button x-show="fileContent?.type === 'text' && !fileContent?.truncated"
                            @click="startEditing()"
                            class="p-1.5 hover:bg-gray-700 rounded text-gray-400 hover:text-white transition-colors"
                            title="Edit file">
                        <i class="fa-regular fa-pen-to-square text-xs"></i>
                    </button>
                </div>

                {{-- Edit mode buttons --}}
                <div x-show="editing" x-cloak class="flex items-center gap-2 shrink-0">
                    <button @click="cancelEditing()"
                            class="px-2.5 py-1 text-xs text-gray-300 hover:text-white transition-colors rounded hover:bg-gray-700"
                            :disabled="saving">
                        Cancel
                    </button>
                    <button @click="saveFile()"
                            class="px-2.5 py-1 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded transition-colors flex items-center gap-1.5"
                            :disabled="saving"
                            :class="{ 'opacity-50 cursor-not-allowed': saving }">
                        <svg x-show="saving" class="animate-spin w-3 h-3" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        <span x-text="saving ? 'Saving...' : 'Save'"></span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Viewer Content --}}
        <div class="flex-1 overflow-auto flex flex-col">
            {{-- Save error banner --}}
            <div x-show="saveError" x-cloak class="flex-none px-3 py-2 bg-red-900/50 border-b border-red-700 text-red-300 text-xs flex items-center gap-2">
                <i class="fa-solid fa-exclamation-circle"></i>
                <span x-text="saveError"></span>
            </div>

            {{-- Edit mode textarea --}}
            <div x-show="editing" x-cloak class="flex-1 flex flex-col min-h-0">
                <textarea x-ref="editTextarea"
                          x-model="editContent"
                          class="flex-1 w-full p-3 bg-gray-950 text-gray-300 font-mono text-xs resize-none border-none focus:ring-0 focus:outline-none leading-5"
                          :disabled="saving"
                          spellcheck="false"
                          style="tab-size: 4;"></textarea>
            </div>

            {{-- Loading --}}
            <div x-show="fileLoading && !editing" class="flex items-center justify-center h-full">
                <svg class="animate-spin w-6 h-6 text-gray-500" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                </svg>
            </div>

            {{-- Error --}}
            <div x-show="fileError && !fileLoading && !editing" class="flex flex-col items-center justify-center h-full p-4 text-center">
                <i class="fa-solid fa-exclamation-triangle text-yellow-500 text-2xl mb-3"></i>
                <p class="text-gray-400 text-sm" x-text="fileError"></p>
            </div>

            {{-- Image preview --}}
            <div x-show="!fileLoading && !fileError && !editing && fileContent?.type === 'image'" class="flex items-center justify-center h-full p-4 bg-gray-950/50">
                <img x-bind:src="fileContent?.base64 ? `data:${fileContent.mime};base64,${fileContent.base64}` : ''"
                     x-bind:alt="fileContent?.name || ''"
                     class="max-w-full max-h-full object-contain rounded shadow-lg"
                     style="image-rendering: auto;">
            </div>

            {{-- Binary file placeholder --}}
            <div x-show="!fileLoading && !fileError && !editing && fileContent?.type === 'binary'" class="flex flex-col items-center justify-center h-full p-4 text-center">
                <i class="fa-solid fa-file-zipper text-gray-600 text-4xl mb-3"></i>
                <p class="text-gray-400 text-sm font-medium" x-text="fileContent?.name || 'Binary file'"></p>
                <p class="text-gray-500 text-xs mt-1" x-text="fileContent?.sizeFormatted || ''"></p>
                <p x-show="fileContent?.message" class="text-gray-600 text-xs mt-2" x-text="fileContent?.message"></p>
                <p class="text-gray-600 text-xs mt-2">Binary files cannot be previewed</p>
            </div>

            {{-- Text/Code content with line numbers --}}
            <div x-show="!fileLoading && !fileError && !editing && fileContent?.type === 'text'" class="min-h-full">
                {{-- Truncation warning banner --}}
                <div x-show="fileContent?.truncated" class="sticky top-0 z-10 px-3 py-1.5 bg-yellow-900/40 border-b border-yellow-700/50 text-xs text-yellow-300 flex items-center gap-2">
                    <i class="fa-solid fa-scissors"></i>
                    <span>File truncated — showing first 512 KB of <span x-text="fileContent?.sizeFormatted"></span></span>
                </div>

                <div class="flex font-mono text-xs leading-5">
                    {{-- Line numbers column --}}
                    <div x-ref="lineNumbers"
                         class="flex-none select-none text-right pr-3 pl-3 py-3 text-gray-600 border-r border-gray-700/50 bg-gray-900/50 whitespace-pre"
                         x-effect="
                             if (fileContent?.type === 'text' && fileContent?.content != null) {
                                 const lines = fileContent.content.split('\n').length;
                                 $refs.lineNumbers.textContent = Array.from({length: lines}, (_, i) => i + 1).join('\n');
                             }
                         "
                         style="min-width: 3rem;"></div>

                    {{-- Code content --}}
                    <div class="flex-1 overflow-x-auto">
                        <pre class="p-0 m-0 bg-transparent"><code x-ref="codeBlock"
                            class="!p-3 !bg-transparent block"
                            x-bind:class="'language-' + (fileContent?.language || 'plaintext')"
                            x-text="fileContent?.content || ''"></code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
