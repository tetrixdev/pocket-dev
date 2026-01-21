<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PocketDev Chat</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Global helpers --}}
    <script>
        // Title length constants (centralized for consistency)
        window.TITLE_MAX_LENGTH = 50;      // Maximum allowed characters
        window.TITLE_MOBILE_LENGTH = 25;   // Approximate mobile truncation point

        // Global helper to linkify file paths in HTML content
        window.linkifyFilePaths = function(html) {
            // Allowed paths from Laravel config (single source of truth)
            const allowedPaths = @json(config('ai.file_preview.allowed_paths', ['/var/www']));
            // Escape all regex metacharacters, then strip leading /
            const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const pathsPattern = allowedPaths.map(p => escapeRegex(p).replace(/^\//, '')).join('|');
            const filePathPattern = new RegExp(`((?:^|[^"'=\\w])((?:\\/(?:${pathsPattern})|\\.\\.\\/|\\.\\/|~\\/)[^\\s<>"'\`\\)]+\\.[a-zA-Z0-9]+))`, 'g');
            const escapeHtml = (text) => String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

            return html.replace(filePathPattern, (match, fullMatch, path) => {
                const prefix = fullMatch.startsWith(path) ? '' : fullMatch[0];
                return `${prefix}<a href="#" class="file-path-link" data-file-path="${escapeHtml(path)}"><i class="fa-regular fa-file-lines"></i>${escapeHtml(path)}</a>`;
            });
        };

        document.addEventListener('alpine:init', () => {
            Alpine.store('filePreview', {
                    // Stack of open files for nested navigation
                    stack: [],
                    loading: false,
                    copied: false,

                    // Edit mode state
                    editing: false,
                    editContent: '',
                    saving: false,
                    saveError: null,

                    // Track if already initialized (prevents duplicate listeners)
                    _initialized: false,
                    _popstateHandler: null,

                    // Initialize history handling for back button support
                    init() {
                        // Prevent double initialization (would add duplicate listeners)
                        if (this._initialized) {
                            if (window.debugLog) debugLog('filePreview.init() skipped - already initialized');
                            return;
                        }
                        this._initialized = true;
                        if (window.debugLog) debugLog('filePreview.init() - setting up popstate listener');

                        // Clean up orphaned history state (user navigated away and came back)
                        if (history.state?.filePreview) {
                            history.replaceState(null, '', location.href);
                        }

                        // Listen for browser back button
                        const store = this;
                        this._popstateHandler = (event) => {
                            if (window.debugLog) debugLog('popstate event', {
                                state: event.state,
                                isOpen: store.isOpen,
                                stackDepth: store.stack.length
                            });
                            // Close preview if open (any back navigation while modal is open should close it)
                            if (store.isOpen) {
                                if (window.debugLog) debugLog('popstate: closing preview via back button');
                                store._closeStack();
                            }
                        };
                        window.addEventListener('popstate', this._popstateHandler);
                    },

                    // Computed-like getters for current file
                    get isOpen() { return this.stack.length > 0; },
                    get current() { return this.stack[this.stack.length - 1] || {}; },
                    get path() { return this.current.path || ''; },
                    get filename() { return this.current.filename || ''; },
                    get content() { return this.current.content || ''; },
                    get error() { return this.current.error || null; },
                    get isMarkdown() { return this.current.isMarkdown || false; },
                    get isHtml() { return this.current.isHtml || false; },
                    get sizeFormatted() { return this.current.sizeFormatted || ''; },
                    get renderedContent() { return this.current.renderedContent || ''; },
                    get highlightedContent() { return this.current.highlightedContent || ''; },
                    get sanitizedHtml() { return this.current.sanitizedHtml || ''; },
                    get stackDepth() { return this.stack.length; },

                    async open(filePath) {
                        if (window.debugLog) debugLog('filePreview.open() called', { filePath, stackDepth: this.stack.length });
                        // Cancel editing if opening a new file
                        if (this.editing) {
                            this.cancelEditing();
                        }
                        this.loading = true;
                        this.copied = false;

                        // Generate unique ID for this entry to handle race conditions
                        // (if user opens/closes files while fetch is in-flight)
                        const entryId = crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;

                        // Create new stack entry (placeholder while loading)
                        const entry = {
                            id: entryId,
                            path: filePath,
                            filename: filePath.split('/').pop(),
                            content: '',
                            error: null,
                            isMarkdown: false,
                            isHtml: false,
                            sizeFormatted: '',
                            renderedContent: '',
                            highlightedContent: '',
                            sanitizedHtml: '',
                        };
                        this.stack.push(entry);

                        // Push history state for back button support
                        history.pushState({ filePreview: true, depth: this.stack.length }, '');

                        try {
                            if (window.debugLog) debugLog('filePreview: fetching file content');
                            const response = await fetch('/api/file/preview', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({ path: filePath }),
                            });

                            const data = await response.json();
                            if (window.debugLog) debugLog('filePreview: response received', { exists: data.exists, error: data.error, size: data.size_formatted });

                            // Find the entry by ID (handles race condition if stack changed during fetch)
                            const entryIndex = this.stack.findIndex(e => e.id === entryId);
                            if (entryIndex === -1) {
                                // Entry was removed while we were fetching (user closed it)
                                if (window.debugLog) debugLog('filePreview: entry no longer in stack, discarding result');
                                return;
                            }

                            // Build the updated entry
                            const updatedEntry = { ...entry };

                            if (!data.exists) {
                                updatedEntry.error = 'File not found';
                            } else if (data.too_large) {
                                updatedEntry.error = data.error;
                                updatedEntry.sizeFormatted = data.size_formatted;
                            } else if (data.binary) {
                                updatedEntry.error = data.error;
                            } else if (data.error) {
                                updatedEntry.error = data.error;
                            } else {
                                updatedEntry.content = data.content;
                                updatedEntry.filename = data.filename;
                                updatedEntry.sizeFormatted = data.size_formatted;
                                updatedEntry.isMarkdown = data.is_markdown;
                                updatedEntry.isHtml = data.is_html || false;

                                // Render content based on type
                                if (updatedEntry.isHtml) {
                                    // Sanitize HTML for safe rendering in iframe
                                    let sanitized = DOMPurify.sanitize(updatedEntry.content, {
                                        WHOLE_DOCUMENT: true,
                                        ADD_TAGS: ['style', 'link', 'base'],
                                        ADD_ATTR: ['target'],
                                    });
                                    // Inject <base target="_blank"> so all links open in new tabs
                                    if (sanitized.includes('<head>')) {
                                        sanitized = sanitized.replace('<head>', '<head><base target="_blank">');
                                    } else if (sanitized.includes('<head ')) {
                                        sanitized = sanitized.replace(/<head([^>]*)>/, '<head$1><base target="_blank">');
                                    } else {
                                        sanitized = '<base target="_blank">' + sanitized;
                                    }
                                    updatedEntry.sanitizedHtml = sanitized;
                                } else if (updatedEntry.isMarkdown) {
                                    let html = marked.parse(updatedEntry.content);
                                    html = window.linkifyFilePaths(html);
                                    updatedEntry.renderedContent = DOMPurify.sanitize(html);
                                } else {
                                    // Syntax highlight then linkify
                                    const ext = data.extension;
                                    let highlighted;
                                    if (ext && hljs.getLanguage(ext)) {
                                        highlighted = hljs.highlight(updatedEntry.content, { language: ext }).value;
                                    } else {
                                        highlighted = hljs.highlightAuto(updatedEntry.content).value;
                                    }
                                    updatedEntry.highlightedContent = window.linkifyFilePaths(highlighted);
                                }
                            }

                            // Replace the entry in the stack to trigger Alpine reactivity
                            this.stack.splice(entryIndex, 1, updatedEntry);

                        } catch (err) {
                            console.error('File preview error:', err);
                            // Find entry by ID and replace with error (if still exists)
                            const entryIndex = this.stack.findIndex(e => e.id === entryId);
                            if (entryIndex !== -1) {
                                this.stack.splice(entryIndex, 1, { ...entry, error: 'Failed to load file' });
                            }
                        } finally {
                            this.loading = false;
                        }
                    },

                    // Internal: pop one item from the stack
                    _closeStack() {
                        if (this.editing) {
                            this.cancelEditing();
                        }
                        this.stack.pop();
                        this.copied = false;
                        if (window.debugLog) debugLog('filePreview._closeStack()', { remainingStack: this.stack.length });
                    },

                    // Close one preview (X button, Escape key, or back button via popstate)
                    close() {
                        if (this.stack.length === 0) return;
                        this._closeStack();
                    },

                    // Close all previews (backdrop click)
                    closeAll() {
                        if (this.stack.length === 0) return;
                        if (this.editing) {
                            this.cancelEditing();
                        }
                        this.stack = [];
                        this.copied = false;
                        if (window.debugLog) debugLog('filePreview.closeAll()');
                    },

                    async copyContent() {
                        if (!this.content) return;
                        try {
                            await navigator.clipboard.writeText(this.content);
                            this.copied = true;
                            setTimeout(() => { this.copied = false; }, 1500);
                        } catch (err) {
                            console.error('Copy failed:', err);
                        }
                    },

                    startEditing() {
                        // Allow editing empty files (content can be empty string)
                        if (this.error) return;
                        this.editContent = this.content ?? '';
                        this.editing = true;
                        this.saveError = null;
                        if (window.debugLog) debugLog('filePreview.startEditing()', { path: this.path });
                    },

                    cancelEditing() {
                        this.editing = false;
                        this.editContent = '';
                        this.saveError = null;
                        if (window.debugLog) debugLog('filePreview.cancelEditing()');
                    },

                    async saveFile() {
                        if (!this.editing || this.saving) return;

                        this.saving = true;
                        this.saveError = null;

                        try {
                            if (window.debugLog) debugLog('filePreview.saveFile()', { path: this.path, contentLength: this.editContent.length });

                            const response = await fetch('/api/file/write', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                },
                                body: JSON.stringify({
                                    path: this.path,
                                    content: this.editContent,
                                }),
                            });

                            const data = await response.json();

                            if (!response.ok || !data.success) {
                                this.saveError = data.error || 'Failed to save file';
                                if (window.debugLog) debugLog('filePreview.saveFile() error', { error: this.saveError });
                                return;
                            }

                            if (window.debugLog) debugLog('filePreview.saveFile() success', { bytesWritten: data.bytes_written });

                            // Update the current stack entry with new content
                            const currentEntry = this.stack[this.stack.length - 1];
                            if (currentEntry) {
                                currentEntry.content = this.editContent;
                                currentEntry.sizeFormatted = data.size_formatted;

                                // Re-render highlighted content
                                const ext = currentEntry.path.split('.').pop()?.toLowerCase();
                                if (currentEntry.isMarkdown) {
                                    let html = marked.parse(this.editContent);
                                    html = window.linkifyFilePaths(html);
                                    currentEntry.renderedContent = DOMPurify.sanitize(html);
                                } else {
                                    let highlighted;
                                    if (ext && hljs.getLanguage(ext)) {
                                        highlighted = hljs.highlight(this.editContent, { language: ext }).value;
                                    } else {
                                        highlighted = hljs.highlightAuto(this.editContent).value;
                                    }
                                    currentEntry.highlightedContent = window.linkifyFilePaths(highlighted);
                                }

                                // Trigger reactivity by replacing the entry
                                this.stack.splice(this.stack.length - 1, 1, { ...currentEntry });
                            }

                            this.editing = false;
                            this.editContent = '';

                        } catch (err) {
                            console.error('Save file error:', err);
                            this.saveError = 'Failed to save file';
                            if (window.debugLog) debugLog('filePreview.saveFile() exception', { error: err.message });
                        } finally {
                            this.saving = false;
                        }
                    }
                });

            // Global helper for opening file preview
            window.openFilePreview = (path) => Alpine.store('filePreview').open(path);

            // Note: filePreview.init() is auto-called by Alpine when the store is registered

            // File attachments store for managing uploaded files in chat
            Alpine.store('attachments', {
                files: [],           // Array of { id, path, filename, size, sizeFormatted, uploading, error }

                get count() {
                    return this.files.filter(f => !f.error && !f.uploading).length;
                },

                get hasFiles() {
                    return this.count > 0;
                },

                get isUploading() {
                    return this.files.some(f => f.uploading);
                },

                get badgeText() {
                    if (this.count === 0) return '+';
                    if (this.count > 9) return '\u221E'; // infinity symbol
                    return this.count.toString();
                },

                async addFile(file) {
                    const id = Date.now() + '-' + Math.random().toString(36).substr(2, 9);
                    const entry = {
                        id,
                        filename: file.name,
                        size: file.size,
                        sizeFormatted: this.formatSize(file.size),
                        path: null,
                        uploading: true,
                        error: null,
                    };
                    this.files.push(entry);

                    try {
                        const formData = new FormData();
                        formData.append('file', file);

                        const response = await fetch('/api/file/upload', {
                            method: 'POST',
                            body: formData,
                        });

                        const data = await response.json();

                        if (!response.ok || !data.success) {
                            throw new Error(data.message || data.error || 'Upload failed');
                        }

                        // Update entry with server response
                        const idx = this.files.findIndex(f => f.id === id);
                        if (idx !== -1) {
                            this.files[idx] = {
                                ...this.files[idx],
                                path: data.path,
                                sizeFormatted: data.size_formatted,
                                uploading: false,
                            };
                        } else {
                            // Entry was removed during upload - clean up orphaned server file
                            try {
                                await fetch('/api/file/delete', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ path: data.path }),
                                });
                            } catch (cleanupErr) {
                                console.warn('Failed to clean up orphaned upload:', cleanupErr);
                            }
                        }
                    } catch (err) {
                        const idx = this.files.findIndex(f => f.id === id);
                        if (idx !== -1) {
                            this.files[idx] = {
                                ...this.files[idx],
                                uploading: false,
                                error: err.message,
                            };
                        }
                    }
                },

                async removeFile(id) {
                    const file = this.files.find(f => f.id === id);
                    if (file && file.path) {
                        // Delete from server
                        try {
                            await fetch('/api/file/delete', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ path: file.path }),
                            });
                        } catch (err) {
                            console.warn('Failed to delete file from server:', err);
                        }
                    }
                    this.files = this.files.filter(f => f.id !== id);
                },

                clear(deleteFromServer = true) {
                    // Optionally delete files from server (skip when sending - keep files for Claude to read)
                    if (deleteFromServer) {
                        this.files.forEach(async (file) => {
                            if (file.path) {
                                try {
                                    await fetch('/api/file/delete', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ path: file.path }),
                                    });
                                } catch (err) {
                                    console.warn('Failed to delete file:', err);
                                }
                            }
                        });
                    }
                    this.files = [];
                },

                getFilePaths() {
                    return this.files
                        .filter(f => f.path && !f.error && !f.uploading)
                        .map(f => f.path);
                },

                formatSize(bytes) {
                    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
                    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
                    return bytes + ' B';
                },
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/marked@15.0.7/marked.min.js"
            integrity="sha384-H+hy9ULve6xfxRkWIh/YOtvDdpXgV2fmAGQkIDTxIgZwNoaoBal14Di2YTMR6MzR"
            crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.3.1/dist/purify.min.js"
            integrity="sha384-80VlBZnyAwkkqtSfg5NhPyZff6nU4K/qniLBL8Jnm4KDv6jZhLiYtJbhglg/i9ww"
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
          crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        /* Hide elements until Alpine.js initializes */
        [x-cloak] { display: none !important; }

        .markdown-content { line-height: 1.6; }
        .markdown-content h1 { font-size: 1.5em; font-weight: bold; margin: 1em 0 0.5em; }
        .markdown-content h2 { font-size: 1.3em; font-weight: bold; margin: 1em 0 0.5em; }
        .markdown-content h3 { font-size: 1.1em; font-weight: bold; margin: 1em 0 0.5em; }
        .markdown-content p { margin: 0.5em 0; }
        .markdown-content ul, .markdown-content ol { margin: 0.5em 0; padding-left: 2em; }
        .markdown-content li { margin: 0.25em 0; }
        .markdown-content code { background: #374151; padding: 0.2em 0.4em; border-radius: 0.25em; font-size: 0.9em; }
        .markdown-content pre { background: #1f2937; padding: 1em; border-radius: 0.5em; overflow-x: auto; margin: 1em 0; }
        .markdown-content pre code { background: none; padding: 0; }
        .markdown-content blockquote { border-left: 4px solid #4b5563; padding-left: 1em; margin: 1em 0; color: #9ca3af; }
        .markdown-content a { color: #60a5fa; text-decoration: underline; }
        .markdown-content table { border-collapse: collapse; margin: 1em 0; width: 100%; }
        .markdown-content th, .markdown-content td { border: 1px solid #4b5563; padding: 0.5em; text-align: left; }
        .markdown-content th { background: #374151; font-weight: bold; }

        /* File path links - clickable paths that open file preview modal */
        .file-path-link {
            color: #60a5fa;
            text-decoration: none;
            background: rgba(59, 130, 246, 0.1);
            padding: 0.1em 0.4em;
            border-radius: 0.25em;
            transition: background-color 0.15s ease;
            white-space: nowrap;
        }
        .file-path-link:hover {
            background: rgba(59, 130, 246, 0.25);
            text-decoration: underline;
        }
        .file-path-link i {
            margin-right: 0.35em;
            opacity: 0.7;
        }

        .safe-area-bottom { padding-bottom: env(safe-area-inset-bottom); }

        /* Mobile: fixed layout with contained scrolling */
        @media (max-width: 767px) {
            html, body { overflow: hidden; height: 100%; }
            #messages { -webkit-overflow-scrolling: touch; }
        }

        /* Custom scrollbar for textareas - dark theme */
        textarea::-webkit-scrollbar {
            width: 6px;
        }
        textarea::-webkit-scrollbar-track {
            background: transparent;
            margin: 4px 0; /* Inset from top/bottom to respect rounded corners */
        }
        textarea::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 3px;
        }
        textarea::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }
        /* Firefox */
        textarea {
            scrollbar-width: thin;
            scrollbar-color: #4b5563 transparent;
        }

        /* Custom scrollbar for messages and conversations - dark theme */
        #messages::-webkit-scrollbar,
        #conversations-list::-webkit-scrollbar {
            width: 6px;
        }
        #messages::-webkit-scrollbar-track,
        #conversations-list::-webkit-scrollbar-track {
            background: transparent;
        }
        #messages::-webkit-scrollbar-thumb,
        #conversations-list::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 3px;
        }
        #messages::-webkit-scrollbar-thumb:hover,
        #conversations-list::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }
        /* Firefox */
        #messages,
        #conversations-list {
            scrollbar-width: thin;
            scrollbar-color: #4b5563 transparent;
        }
    </style>
</head>
<body class="antialiased bg-gray-900 text-gray-100 h-screen overflow-hidden" x-data="chatApp()">

    {{-- Main Layout Container --}}
    <div class="md:flex md:h-screen">

        {{-- Desktop Sidebar (hidden on mobile) --}}
        <div class="hidden md:block md:h-full">
            @include('partials.chat.sidebar')
        </div>

        {{-- Mobile Header (hidden on desktop) --}}
        <div class="md:hidden">
            @include('partials.chat.mobile-layout')
        </div>

        {{-- Main Content Area --}}
        <div class="flex-1 flex flex-col min-h-0">

            {{-- Desktop Header (hidden on mobile) - fixed at top to match fixed #messages --}}
            <div class="hidden md:flex md:fixed md:top-0 md:left-64 md:right-0 md:z-10 bg-gray-800 border-b border-gray-700 p-2 items-center justify-between">
                <div class="flex items-center gap-3 pl-2">
                    <button @click="openRenameModal()"
                            :disabled="!currentConversationUuid"
                            class="text-base font-semibold hover:text-blue-400 transition-colors max-w-[50ch] truncate disabled:cursor-default disabled:hover:text-white"
                            :class="{ 'cursor-pointer': currentConversationUuid }"
                            :title="currentConversationUuid ? 'Click to rename' : ''"
                            x-text="currentConversationTitle || 'New Conversation'">
                    </button>
                    <button @click="showAgentSelector = true"
                            class="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-200 cursor-pointer"
                            aria-label="Select AI agent">
                        <span class="w-1.5 h-1.5 rounded-full shrink-0"
                              :class="{
                                  'bg-orange-500': currentAgent?.provider === 'anthropic',
                                  'bg-green-500': currentAgent?.provider === 'openai',
                                  'bg-purple-500': currentAgent?.provider === 'claude_code',
                                  'bg-gray-500': !currentAgent
                              }"></span>
                        <span x-text="currentAgent?.name || 'Select Agent'" class="underline decoration-gray-600 hover:decoration-gray-400"></span>
                    </button>
                    {{-- Conversation status badge --}}
                    <span x-show="currentConversationUuid && currentConversationStatus"
                          class="inline-flex items-center justify-center w-4 h-4 rounded-sm cursor-help"
                          :class="getStatusColorClass(currentConversationStatus)"
                          :title="'Status: ' + currentConversationStatus">
                        <i class="text-white text-[10px]" :class="getStatusIconClass(currentConversationStatus)"></i>
                    </span>
                    {{-- Context window progress bar --}}
                    <x-chat.context-progress />
                </div>
                {{-- Conversation Menu Dropdown --}}
                <div class="relative">
                    <button @click="showConversationMenu = !showConversationMenu"
                            class="text-gray-300 hover:text-white p-2 cursor-pointer"
                            title="Menu">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </button>
                    {{-- Dropdown Menu --}}
                    <div x-show="showConversationMenu"
                         x-cloak
                         @click.outside="showConversationMenu = false"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="transform opacity-0 scale-95"
                         x-transition:enter-end="transform opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="transform opacity-100 scale-100"
                         x-transition:leave-end="transform opacity-0 scale-95"
                         class="absolute right-0 mt-1 w-48 bg-gray-700 rounded-lg shadow-lg border border-gray-600 py-1 z-50">
                        {{-- Workspace --}}
                        <button @click="openWorkspaceSelector(); showConversationMenu = false"
                                class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600 w-full text-left cursor-pointer">
                            <i class="fa-solid fa-folder w-4 text-center"></i>
                            <span class="flex-1">Workspace</span>
                            <span class="text-xs text-gray-400 truncate max-w-[80px]" x-text="currentWorkspace?.name || 'Default'"></span>
                        </button>
                        {{-- Settings --}}
                        <a href="{{ route('config.index') }}"
                           class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600">
                            <i class="fa-solid fa-cog w-4 text-center"></i>
                            Settings
                        </a>
                        {{-- Archive/Unarchive --}}
                        <button @click="toggleArchiveConversation(); showConversationMenu = false"
                                :disabled="!currentConversationUuid"
                                :class="!currentConversationUuid ? 'text-gray-500 cursor-not-allowed' : 'text-gray-200 hover:bg-gray-600 cursor-pointer'"
                                class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                            <i class="fa-solid fa-box-archive w-4 text-center"></i>
                            <span x-text="currentConversationStatus === 'archived' ? 'Unarchive' : 'Archive'"></span>
                        </button>
                        {{-- Delete --}}
                        <button @click="deleteConversation(); showConversationMenu = false"
                                :disabled="!currentConversationUuid"
                                :class="!currentConversationUuid ? 'text-gray-500 cursor-not-allowed' : 'text-red-400 hover:bg-gray-600 cursor-pointer'"
                                class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                            <i class="fa-solid fa-trash w-4 text-center"></i>
                            Delete
                        </button>
                    </div>
                </div>
            </div>

            {{-- Messages Container with Drag-and-Drop --}}
            {{-- Mobile: fixed position between header and input, contained scroll --}}
            {{-- Desktop: flex container scroll with overflow-y-auto --}}
            <div x-data="{ isDragging: false }"
                 @dragover.prevent="isDragging = true"
                 @dragleave.prevent="isDragging = false"
                 @drop.prevent="
                     isDragging = false;
                     const files = $event.dataTransfer.files;
                     for (const file of files) {
                         Alpine.store('attachments').addFile(file);
                     }
                 "
                 class="relative md:flex-1 md:flex md:flex-col md:min-h-0">

                {{-- Drop Overlay (Desktop only) - fixed position matching #messages --}}
                <div x-cloak
                     class="fixed left-64 right-0 bg-blue-500/20 items-center justify-center z-10 pointer-events-none rounded-lg hidden"
                     :class="isDragging ? 'md:flex' : 'md:hidden'"
                     :style="{ top: '57px', bottom: desktopInputHeight + 'px' }">
                    <div class="bg-gray-800 rounded-lg p-6 text-center shadow-xl border-2 border-dashed border-blue-400">
                        <i class="fa-solid fa-cloud-arrow-up text-4xl text-blue-400 mb-2"></i>
                        <p class="text-gray-200 font-medium">Drop files to attach</p>
                    </div>
                </div>

                {{-- Loading Conversation Overlay (Mobile) - visible below md breakpoint only --}}
                <div class="md:hidden">
                    <div x-cloak
                         x-show="loadingConversation"
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="transition ease-in duration-100"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="fixed top-[57px] left-0 right-0 z-20 bg-gray-900/90 flex items-center justify-center backdrop-blur-sm"
                         :style="{ bottom: mobileInputHeight + 'px' }">
                        <div class="flex flex-col items-center gap-3">
                            <svg class="w-8 h-8 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-gray-400 text-sm">Loading conversation...</span>
                        </div>
                    </div>
                </div>

                {{-- Loading Conversation Overlay (Desktop) - visible at md breakpoint and above only --}}
                <div class="hidden md:block">
                    <div x-cloak
                         x-show="loadingConversation"
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="transition ease-in duration-100"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="fixed left-64 right-0 z-20 bg-gray-900/90 flex items-center justify-center backdrop-blur-sm"
                         :style="{ top: '57px', bottom: desktopInputHeight + 'px' }">
                        <div class="flex flex-col items-center gap-3">
                            <svg class="w-8 h-8 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-gray-400 text-sm">Loading conversation...</span>
                        </div>
                    </div>
                </div>

                <div id="messages"
                     class="p-4 space-y-4 overflow-y-auto bg-gray-900 fixed left-0 right-0 z-0
                            md:left-64 md:pt-4 md:pb-4"
                     :class="isDragging ? 'ring-2 ring-blue-500 ring-inset' : ''"
                     :style="{ top: '57px', bottom: (windowWidth >= 768 ? desktopInputHeight : mobileInputHeight) + 'px' }"
                     @scroll="handleMessagesScroll($event)">

                {{-- Empty State --}}
                <template x-if="messages.length === 0">
                    <div class="text-center text-gray-400 mt-10 md:mt-20">
                        <h3 class="text-xl mb-2">Welcome to PocketDev</h3>
                        <p class="text-sm md:text-base">Multi-provider AI chat with direct API streaming</p>
                    </div>
                </template>

                {{-- Messages List --}}
                <template x-for="(msg, index) in messages" :key="msg.id">
                    <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'"
                         x-bind:data-turn="Number.isInteger(msg.turn_number) ? msg.turn_number : null"
                         class="transition-colors duration-500">
                        <x-chat.user-message />
                        <x-chat.assistant-message />
                        <x-chat.thinking-block />
                        <x-chat.tool-block />
                        <x-chat.compaction-block />
                        <x-chat.system-block />
                        <x-chat.interrupted-block />
                        <x-chat.error-block />
                        <x-chat.empty-response />
                    </div>
                </template>
            </div>
            </div> {{-- End drag-and-drop wrapper --}}

            {{-- Scroll to Bottom Button (mobile) - positioned above attachment FAB --}}
            <button @click="autoScrollEnabled = true; scrollToBottom()"
                    :class="(!isAtBottom && messages.length > 0) ? 'opacity-100 scale-100 pointer-events-auto' : 'opacity-0 scale-75 pointer-events-none'"
                    class="md:hidden fixed z-50 w-10 h-10 bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white rounded-full shadow-lg flex items-center justify-center transition-all duration-200 right-4"
                    :style="{ bottom: (mobileInputHeight + 64) + 'px' }"
                    title="Scroll to bottom">
                <i class="fas fa-arrow-down"></i>
            </button>

            {{-- File Attachment FAB (mobile) --}}
            @include('partials.chat.attachment-fab')

            {{-- Scroll to Bottom Button (desktop) --}}
            <button x-cloak
                    x-show="!isAtBottom && messages.length > 0"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-75"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-75"
                    @click="autoScrollEnabled = true; scrollToBottom()"
                    class="hidden md:flex fixed z-50 w-10 h-10 bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white rounded-full shadow-lg items-center justify-center transition-colors duration-200 cursor-pointer right-6"
                    :style="{ bottom: (desktopInputHeight + 8) + 'px' }"
                    title="Scroll to bottom">
                <i class="fas fa-arrow-down"></i>
            </button>

            {{-- Desktop Input (hidden on mobile) - fixed at bottom to match fixed #messages --}}
            <div x-ref="desktopInput" class="hidden md:block md:fixed md:bottom-0 md:left-64 md:right-0 md:z-10">
                @include('partials.chat.input-desktop')
            </div>
        </div>
    </div>

    {{-- Mobile Input (hidden on desktop) --}}
    <div class="md:hidden">
        @include('partials.chat.input-mobile')
    </div>

    @include('partials.chat.modals')

    <script>
        // Configure marked.js
        marked.setOptions({
            breaks: true,
            gfm: true,
            highlight: function(code, lang) {
                if (lang && hljs.getLanguage(lang)) {
                    try { return hljs.highlight(code, { language: lang }).value; } catch (err) {}
                }
                return hljs.highlightAuto(code).value;
            }
        });

        function chatApp() {
            return {
                // State
                prompt: '',
                messages: [],
                conversations: [],
                conversationsPage: 1,
                conversationsLastPage: 1,
                loadingMoreConversations: false,
                cachedLatestActivity: null, // For sidebar polling
                sidebarPollInterval: null, // Polling interval ID
                currentConversationUuid: null,
                currentConversationStatus: null, // Current conversation status (idle, processing, archived, failed)
                showConversationMenu: false, // Dropdown menu visibility
                conversationProvider: null, // Provider of current conversation (for mid-convo agent switch)
                isStreaming: false,
                _justCompletedStream: false,
                autoScrollEnabled: true, // Auto-scroll during streaming; disabled when user scrolls up manually
                isAtBottom: true, // Track if user is at bottom of messages
                ignoreScrollEvents: false, // Ignore scroll events during conversation loading
                loadingConversation: false, // Show loading overlay during conversation loading
                _loadingConversationUuid: null, // UUID of conversation being loaded (prevents duplicate loads)
                _initDone: false, // Guard against double initialization
                sessionCost: 0,
                totalTokens: 0,

                // Agents
                agents: [],
                currentAgentId: null,

                // Skills autocomplete
                skills: [],
                skillSuggestions: [],
                showSkillSuggestions: false,
                selectedSkillIndex: 0,
                activeSkill: null, // Currently selected skill (shown as chip above textarea)

                // Provider/Model - Dual architecture: agent-based (new) + direct provider/model (legacy)
                // This supports backwards compatibility while transitioning to full agent-based system
                provider: 'anthropic',
                model: 'claude-sonnet-4-5-20250929',
                providers: {},
                availableModels: {},

                // Provider-specific reasoning settings
                anthropicThinkingBudget: 0,
                openaiReasoningEffort: 'none',
                openaiCompatibleReasoningEffort: 'none',
                claudeCodeThinkingTokens: 0,
                claudeCodeAllowedTools: [], // Empty = all tools allowed

                // Response length modes
                responseLevel: 1,
                responseLevels: [], // Loaded from API

                // Modals
                showMobileDrawer: false,
                showShortcutsModal: false,
                showAgentSelector: false,
                showWorkspaceSelector: false,
                showPricingSettings: false,
                showMessageDetails: false,
                showOpenAiModal: false,
                showClaudeCodeAuthModal: false,
                showErrorModal: false,
                showSearchModal: false,
                showRenameModal: false,
                showSystemPromptPreview: false,

                // Conversation title (rename)
                currentConversationTitle: null,
                renameTitle: '',
                renameSaving: false,
                _systemPromptPreviewNonce: 0,
                systemPromptPreview: {
                    loading: false,
                    error: null,
                    agentName: '',
                    sections: [],
                    totalTokens: 0,
                    expandedSections: {},
                    rawViewSections: {},
                    copied: false,
                },
                errorMessage: '',
                openAiKeyInput: '',

                // Workspace state
                workspaces: [],
                workspacesLoading: false,
                currentWorkspace: null,
                currentWorkspaceId: null,

                // Conversation search
                showSearchInput: false,

                // Copy message state
                copiedMessageId: null,
                conversationSearchQuery: '',
                conversationSearchResults: [],
                conversationSearchLoading: false,
                showArchivedConversations: false, // Filter to include archived conversations
                pendingScrollToTurn: null, // Set when loading from search result

                // Pricing settings (per-model) - loaded from API via fetchPricing()
                modelPricing: {},
                pricingProvider: 'anthropic',
                pricingModel: 'claude-sonnet-4-5-20250929',
                // Legacy flat values (used as fallback)
                defaultPricing: { input: 3.00, output: 15.00, cacheWrite: 3.75, cacheRead: 0.30 },

                // Token tracking (for cost breakdown)
                inputTokens: 0,
                outputTokens: 0,
                cacheCreationTokens: 0,
                cacheReadTokens: 0,

                // Context window tracking
                contextWindowSize: 0,
                lastContextTokens: 0,
                contextPercentage: 0,
                contextWarningLevel: 'safe',

                // Per-message breakdown
                breakdownMessage: null,

                // Copy conversation state
                copyingConversation: false,

                // Voice recording state
                isRecording: false,
                isProcessing: false,
                mediaRecorder: null,
                audioChunks: [],
                openAiKeyConfigured: false,
                autoSendAfterTranscription: false,
                // File upload mode: true = record full audio then transcribe (better for pauses)
                // false = realtime streaming (live transcription)
                useFileUploadTranscription: true,

                // Realtime transcription state (WebSocket-based, used when useFileUploadTranscription=false)
                realtimeWs: null,
                realtimeTranscript: '',
                realtimeAudioContext: null,
                realtimeAudioWorklet: null,
                realtimeStream: null,
                waitingForFinalTranscript: false,
                stopTimeout: null,
                currentTranscriptItemId: null,

                // Input height tracking (for dynamic messages container bottom)
                mobileInputHeight: 57,
                desktopInputHeight: 65,
                windowWidth: window.innerWidth,

                // Anthropic API key state (for Claude Code)
                anthropicKeyInput: '',
                anthropicKeyConfigured: false,

                // Stream reconnection state
                lastEventIndex: 0,
                streamAbortController: null,
                _streamConnectNonce: 0,
                _streamRetryTimeoutId: null,
                _streamState: {
                    // Maps block_index -> message array index (for interleaved thinking support)
                    thinkingBlocks: {},        // { blockIndex: { msgIndex, content, complete } }
                    currentThinkingBlock: -1,  // Latest thinking block_index (for abort)
                    textMsgIndex: -1,
                    toolMsgIndex: -1,
                    textContent: '',
                    toolInput: '',
                    turnCost: 0,
                    toolInProgress: false,        // True while streaming tool parameters
                    waitingForToolResults: new Set(), // Tool IDs waiting for execution results
                    abortPending: false,
                    abortSkipSync: false,         // If true, backend should skip syncing to CLI session
                },

                async init() {
                    // Guard against double initialization
                    if (this._initDone) {
                        this.debugLog('init() SKIPPED (already done)');
                        return;
                    }
                    this._initDone = true;
                    this.debugLog('init() started');

                    // Fetch pricing from database
                    await this.fetchPricing();

                    // Fetch providers
                    await this.fetchProviders();

                    // Fetch workspaces and active workspace (must happen before agents/conversations)
                    await this.fetchWorkspaces();
                    await this.fetchActiveWorkspace();

                    // Fetch available agents (filtered by workspace)
                    await this.fetchAgents();

                    // Restore filter states from sessionStorage
                    const savedArchiveFilter = sessionStorage.getItem('pocketdev_showArchivedConversations');
                    if (savedArchiveFilter === 'true') {
                        this.showArchivedConversations = true;
                        this.showSearchInput = true; // Show filter panel so user sees active filter
                    }
                    const savedSearchQuery = sessionStorage.getItem('pocketdev_conversationSearchQuery');
                    if (savedSearchQuery) {
                        this.conversationSearchQuery = savedSearchQuery;
                        this.showSearchInput = true; // Open filter panel if search was active
                    }

                    // Load conversations list
                    await this.fetchConversations();

                    // If search query was restored, perform the search
                    if (this.conversationSearchQuery) {
                        await this.searchConversations();
                    }

                    // Watch for filter changes to persist to sessionStorage
                    this.$watch('showArchivedConversations', (value) => {
                        if (value) {
                            sessionStorage.setItem('pocketdev_showArchivedConversations', 'true');
                        } else {
                            sessionStorage.removeItem('pocketdev_showArchivedConversations');
                        }
                    });
                    this.$watch('conversationSearchQuery', (value) => {
                        if (value) {
                            sessionStorage.setItem('pocketdev_conversationSearchQuery', value);
                        } else {
                            sessionStorage.removeItem('pocketdev_conversationSearchQuery');
                        }
                    });

                    // Start polling for sidebar updates
                    this.startSidebarPolling();

                    // Check OpenAI key for voice transcription
                    await this.checkOpenAiKey();

                    // Check Anthropic key for Claude Code
                    await this.checkAnthropicKey();

                    // Check if returning from settings
                    const returningFromSettings = localStorage.getItem('pocketdev_returning_from_settings');
                    if (returningFromSettings) {
                        localStorage.removeItem('pocketdev_returning_from_settings');
                    }

                    // Check URL for conversation UUID and load if present
                    const urlConversationUuid = this.getConversationUuidFromUrl();
                    if (urlConversationUuid) {
                        // Check for ?turn= query parameter to scroll to specific turn
                        const urlParams = new URLSearchParams(window.location.search);
                        const turnParam = urlParams.get('turn');
                        if (turnParam) {
                            const turnNumber = parseInt(turnParam, 10);
                            if (!isNaN(turnNumber)) {
                                this.pendingScrollToTurn = turnNumber;
                                this.autoScrollEnabled = false;
                            }
                        }

                        await this.loadConversation(urlConversationUuid);

                        // Scroll to bottom if returning from settings (only if not scrolling to turn)
                        if (returningFromSettings && this.pendingScrollToTurn === null) {
                            this.$nextTick(() => this.scrollToBottom());
                        }
                    }

                    // Handle browser back/forward navigation
                    window.addEventListener('popstate', (event) => {
                        // Ignore filePreview history states (handled by filePreview store)
                        if (event.state?.filePreview) {
                            return;
                        }

                        // Check for turn parameter in URL
                        const urlParams = new URLSearchParams(window.location.search);
                        const turnParam = urlParams.get('turn');
                        let turnNumber = null;
                        if (turnParam) {
                            const parsed = parseInt(turnParam, 10);
                            if (!isNaN(parsed)) {
                                turnNumber = parsed;
                            }
                        }

                        if (event.state && event.state.conversationUuid) {
                            // Don't reload if we're already on this conversation
                            if (this.currentConversationUuid === event.state.conversationUuid) {
                                // But still handle turn scrolling if requested
                                if (turnNumber !== null) {
                                    this.pendingScrollToTurn = turnNumber;
                                    this.autoScrollEnabled = false;
                                    this.scrollToTurn(turnNumber);
                                }
                                return;
                            }
                            // Set pending scroll for new conversation load
                            if (turnNumber !== null) {
                                this.pendingScrollToTurn = turnNumber;
                                this.autoScrollEnabled = false;
                            }
                            this.loadConversation(event.state.conversationUuid);
                        } else {
                            // Back to new conversation state
                            this.newConversation();
                        }
                    });

                    // Track window resize for responsive layout calculations
                    window.addEventListener('resize', () => {
                        this.windowWidth = window.innerWidth;
                    });

                    // Track input heights for dynamic messages container positioning
                    this.$nextTick(() => {
                        if (this.$refs.mobileInput) {
                            const resizeObserver = new ResizeObserver((entries) => {
                                for (const entry of entries) {
                                    // Add 1px for border-top on input container
                                    this.mobileInputHeight = entry.contentRect.height + 1;
                                }
                            });
                            resizeObserver.observe(this.$refs.mobileInput);
                        }

                        if (this.$refs.desktopInput) {
                            const desktopResizeObserver = new ResizeObserver((entries) => {
                                for (const entry of entries) {
                                    // Add 1px for border-top on input container
                                    this.desktopInputHeight = entry.contentRect.height + 1;
                                }
                            });
                            desktopResizeObserver.observe(this.$refs.desktopInput);
                        }
                    });

                    // Event delegation for file path links (DOMPurify strips onclick attributes)
                    document.addEventListener('click', (e) => {
                        const link = e.target.closest('.file-path-link');
                        if (link) {
                            e.preventDefault();
                            const filePath = link.dataset.filePath;
                            if (filePath) {
                                try {
                                    const store = Alpine.store('filePreview');
                                    if (store) {
                                        store.open(filePath);
                                    }
                                } catch (err) {
                                    console.error('filePreview error:', err);
                                }
                            }
                        }
                    });
                },

                // Extract conversation UUID from URL path (strict UUID validation)
                getConversationUuidFromUrl() {
                    const path = window.location.pathname.replace(/\/+$/, ''); // tolerate trailing slash
                    const match = path.match(/^\/chat\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i);
                    return match ? match[1] : null;
                },

                // Update URL to reflect current conversation
                // Use replace: true to avoid back-button loops when clearing invalid URLs
                updateUrl(conversationUuid = null, { replace = false } = {}) {
                    const newPath = conversationUuid ? `/chat/${conversationUuid}` : '/';
                    const state = conversationUuid ? { conversationUuid } : {};

                    // Only update if path actually changed
                    if (window.location.pathname !== newPath) {
                        if (replace) {
                            window.history.replaceState(state, '', newPath);
                        } else {
                            window.history.pushState(state, '', newPath);
                        }
                    }

                    // Set session for "Back to Chat" in settings
                    if (conversationUuid) {
                        fetch(`/chat/${conversationUuid}/session`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            }
                        });
                    }
                },

                async fetchProviders() {
                    try {
                        const response = await fetch('/api/providers');
                        const data = await response.json();
                        this.providers = data.providers;

                        // Find available providers
                        const availableProviders = Object.entries(data.providers)
                            .filter(([key, p]) => p.available)
                            .map(([key]) => key);

                        // Select default provider, falling back to first available
                        const defaultProvider = data.default || 'anthropic';
                        if (availableProviders.includes(defaultProvider)) {
                            this.provider = defaultProvider;
                        } else if (availableProviders.length > 0) {
                            this.provider = availableProviders[0];
                        } else {
                            this.provider = defaultProvider; // Keep default even if unavailable
                        }

                        // Store response levels from API
                        if (data.response_levels) {
                            this.responseLevels = Object.values(data.response_levels);
                        }
                        this.updateModels();
                    } catch (err) {
                        console.error('Failed to fetch providers:', err);
                        this.showError('Failed to load providers');
                    }
                },

                async fetchAgents() {
                    try {
                        let url = '/api/agents';
                        if (this.currentWorkspaceId) {
                            url += '?workspace_id=' + this.currentWorkspaceId;
                        }
                        const response = await fetch(url);
                        const data = await response.json();
                        this.agents = data.data || [];

                        // Check if current agent still exists in fetched agents
                        const currentAgentExists = this.currentAgentId && this.agents.some(a => a.id === this.currentAgentId);

                        // If current agent doesn't exist in this workspace
                        if (this.currentAgentId && !currentAgentExists) {
                            if (this.agents.length > 0) {
                                // Auto-select default agent or first available
                                const defaultAgent = this.agents.find(a => a.is_default) || this.agents[0];
                                await this.selectAgent(defaultAgent, false, { syncBackend: false });
                            } else {
                                // No agents in this workspace - clear selection
                                this.currentAgentId = null;
                                this.claudeCodeAllowedTools = [];
                            }
                        } else if (!this.currentAgentId && this.agents.length > 0) {
                            // No agent selected but agents available - auto-select
                            const defaultAgent = this.agents.find(a => a.is_default) || this.agents[0];
                            await this.selectAgent(defaultAgent, false, { syncBackend: false });
                        }
                    } catch (err) {
                        console.error('Failed to fetch agents:', err);
                    }
                },

                async fetchSkills() {
                    if (!this.currentAgentId) {
                        this.skills = [];
                        return;
                    }
                    // Capture agent ID to detect stale responses
                    const agentId = this.currentAgentId;
                    try {
                        const response = await fetch(`/api/agents/${agentId}/skills`);
                        const data = await response.json();
                        // Ignore stale response if agent changed during fetch
                        if (this.currentAgentId !== agentId) return;
                        this.skills = data.skills || [];
                    } catch (err) {
                        console.error('Failed to fetch skills:', err);
                        // Only clear skills if still on same agent
                        if (this.currentAgentId === agentId) {
                            this.skills = [];
                        }
                    }
                },

                updateSkillSuggestions() {
                    // Don't show suggestions if a skill is already active
                    if (this.activeSkill) {
                        this.showSkillSuggestions = false;
                        this.skillSuggestions = [];
                        return;
                    }

                    if (!this.prompt.startsWith('/')) {
                        this.showSkillSuggestions = false;
                        this.skillSuggestions = [];
                        return;
                    }

                    const query = this.prompt.slice(1).toLowerCase();

                    // Filter skills matching the query (guard against null when_to_use)
                    this.skillSuggestions = this.skills.filter(skill => {
                        const name = (skill.name || '').toLowerCase();
                        const whenToUse = (skill.when_to_use || '').toLowerCase();
                        return name.includes(query) || whenToUse.includes(query);
                    }).slice(0, 8);

                    this.showSkillSuggestions = this.skillSuggestions.length > 0;
                    this.selectedSkillIndex = 0;
                },

                selectSkill(skill) {
                    // Set active skill and clear prompt (skill shown as chip above textarea)
                    this.activeSkill = skill;
                    this.prompt = '';
                    this.showSkillSuggestions = false;
                    this.$nextTick(() => {
                        this.$refs.promptInput?.focus();
                    });
                },

                clearActiveSkill() {
                    this.activeSkill = null;
                },

                findSkillByName(name) {
                    return this.skills.find(s => s.name.toLowerCase() === name.toLowerCase());
                },

                handleSkillKeydown(event) {
                    // Handle backspace on empty textarea to restore skill to prompt
                    if (event.key === 'Backspace' && this.activeSkill && this.prompt === '') {
                        event.preventDefault();
                        // Restore skill name to prompt (minus last char for natural backspace feel)
                        const skillName = '/' + this.activeSkill.name;
                        this.prompt = skillName.slice(0, -1);
                        this.activeSkill = null;
                        return;
                    }

                    // Handle space to activate skill when typing /command directly
                    if (event.key === ' ' && this.prompt.startsWith('/') && !this.activeSkill) {
                        const skillName = this.prompt.slice(1).trim();
                        const skill = this.findSkillByName(skillName);
                        if (skill) {
                            event.preventDefault();
                            this.selectSkill(skill);
                            return;
                        }
                    }

                    if (!this.showSkillSuggestions) return;

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        this.selectedSkillIndex = Math.min(this.selectedSkillIndex + 1, this.skillSuggestions.length - 1);
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        this.selectedSkillIndex = Math.max(this.selectedSkillIndex - 1, 0);
                    } else if (event.key === 'Tab' || event.key === 'Enter') {
                        if (this.skillSuggestions.length > 0) {
                            event.preventDefault();
                            this.selectSkill(this.skillSuggestions[this.selectedSkillIndex]);
                        }
                    } else if (event.key === 'Escape') {
                        this.showSkillSuggestions = false;
                    }
                },

                // Agent helper methods
                get currentAgent() {
                    return this.agents.find(a => a.id === this.currentAgentId);
                },

                get availableAgents() {
                    // If in conversation, filter to same provider
                    if (this.conversationProvider) {
                        return this.agents.filter(a => a.provider === this.conversationProvider);
                    }
                    return this.agents;
                },

                get availableProviderKeys() {
                    const providers = new Set(this.availableAgents.map(a => a.provider));
                    return ['anthropic', 'openai', 'claude_code', 'codex', 'openai_compatible'].filter(p => providers.has(p));
                },

                agentsForProvider(providerKey) {
                    return this.availableAgents.filter(a => a.provider === providerKey);
                },

                getProviderDisplayName(providerKey) {
                    const names = {
                        'anthropic': 'Anthropic',
                        'openai': 'OpenAI',
                        'claude_code': 'Claude Code',
                        'codex': 'Codex',
                        'openai_compatible': 'OpenAI Compatible'
                    };
                    return names[providerKey] || providerKey;
                },

                getProviderColorClass(providerKey) {
                    const colors = {
                        'anthropic': 'bg-orange-500',
                        'openai': 'bg-green-500',
                        'claude_code': 'bg-purple-500',
                        'codex': 'bg-blue-500',
                        'openai_compatible': 'bg-teal-500'
                    };
                    return colors[providerKey] || 'bg-gray-500';
                },

                getStatusColorClass(status) {
                    const colors = {
                        'idle': 'bg-green-600',
                        'processing': 'bg-blue-600',
                        'archived': 'bg-gray-600',
                        'failed': 'bg-red-600'
                    };
                    return colors[status] || 'bg-gray-600';
                },

                getStatusIconClass(status) {
                    const icons = {
                        'idle': 'fa-solid fa-check',
                        'processing': 'fa-solid fa-spinner fa-spin',
                        'archived': 'fa-solid fa-box-archive',
                        'failed': 'fa-solid fa-triangle-exclamation'
                    };
                    return icons[status] || 'fa-solid fa-check';
                },

                async selectAgent(agent, closeModal = true, { syncBackend = true } = {}) {
                    if (!agent) return;

                    // If we have an active conversation AND we're switching to a different agent,
                    // update the backend first. This ensures the next message uses the new agent's
                    // system prompt and tools. Skip backend sync for auto-select paths (e.g., workspace switch).
                    if (syncBackend && this.currentConversationUuid && agent.id !== this.currentAgentId) {
                        try {
                            const response = await fetch(`/api/conversations/${this.currentConversationUuid}/agent`, {
                                method: 'PATCH',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                                },
                                body: JSON.stringify({
                                    agent_id: agent.id,
                                    sync_settings: true
                                })
                            });

                            if (!response.ok) {
                                console.error('Failed to switch agent on backend:', response.status);
                                this.errorMessage = 'Failed to switch agent. Please try again.';
                                return;
                            }
                            this.debugLog('Agent switched on backend', { agentId: agent.id, conversation: this.currentConversationUuid });

                            // Update the conversations array so sidebar shows correct agent
                            const convIndex = this.conversations.findIndex(c => c.uuid === this.currentConversationUuid);
                            if (convIndex !== -1) {
                                this.conversations[convIndex].agent = { id: agent.id, name: agent.name };
                                this.conversations[convIndex].agent_id = agent.id;
                            }
                        } catch (err) {
                            console.error('Error switching agent:', err);
                            this.errorMessage = 'Failed to switch agent. Please try again.';
                            return;
                        }
                    }

                    this.currentAgentId = agent.id;
                    this.provider = agent.provider;
                    this.model = agent.model;

                    // Fetch skills for autocomplete
                    this.fetchSkills();

                    // Load agent's reasoning settings
                    this.anthropicThinkingBudget = agent.anthropic_thinking_budget || 0;
                    this.openaiReasoningEffort = agent.openai_reasoning_effort || 'none';
                    this.claudeCodeThinkingTokens = agent.claude_code_thinking_tokens || 0;
                    this.responseLevel = agent.response_level || 1;

                    // Load allowed tools
                    this.claudeCodeAllowedTools = agent.allowed_tools || [];

                    if (closeModal) {
                        this.showAgentSelector = false;
                    }
                },

                // ==================== System Prompt Preview Methods ====================

                async openSystemPromptPreview() {
                    if (!this.currentAgentId) return;

                    // Get the current agent
                    const agent = this.agents.find(a => a.id === this.currentAgentId);
                    if (!agent) return;

                    // Increment nonce to handle rapid agent switching (stale response guard)
                    const myNonce = ++this._systemPromptPreviewNonce;

                    // Reset state
                    this.systemPromptPreview = {
                        loading: true,
                        error: null,
                        agentName: agent.name,
                        sections: [],
                        totalTokens: 0,
                        expandedSections: {},
                        rawViewSections: {},
                        copied: false,
                    };
                    this.showSystemPromptPreview = true;

                    try {
                        const response = await fetch(`/api/agents/${this.currentAgentId}/system-prompt-preview`);
                        if (!response.ok) {
                            throw new Error('Failed to fetch system prompt preview');
                        }

                        // Discard stale response if user switched agents while fetching
                        if (myNonce !== this._systemPromptPreviewNonce) return;

                        const data = await response.json();
                        this.systemPromptPreview.sections = data.sections || [];
                        this.systemPromptPreview.totalTokens = data.estimated_tokens || 0;
                        this.systemPromptPreview.loading = false;
                    } catch (err) {
                        // Discard stale error if user switched agents while fetching
                        if (myNonce !== this._systemPromptPreviewNonce) return;

                        console.error('Failed to fetch system prompt preview:', err);
                        this.systemPromptPreview.error = err.message || 'Failed to load system prompt';
                        this.systemPromptPreview.loading = false;
                    }
                },

                toggleSystemPromptSection(path) {
                    if (this.systemPromptPreview.expandedSections[path]) {
                        delete this.systemPromptPreview.expandedSections[path];
                    } else {
                        this.systemPromptPreview.expandedSections[path] = true;
                    }
                },

                toggleSystemPromptRawView(path) {
                    if (this.systemPromptPreview.rawViewSections[path]) {
                        delete this.systemPromptPreview.rawViewSections[path];
                    } else {
                        this.systemPromptPreview.rawViewSections[path] = true;
                    }
                },

                toggleAllSystemPromptSections() {
                    const hasExpanded = Object.keys(this.systemPromptPreview.expandedSections).length > 0;
                    if (hasExpanded) {
                        // Collapse all
                        this.systemPromptPreview.expandedSections = {};
                    } else {
                        // Expand all sections (recursively build paths)
                        const expanded = {};
                        const buildPaths = (sections, prefix = '') => {
                            sections.forEach((section, idx) => {
                                const path = prefix ? `${prefix}.${idx}` : String(idx);
                                expanded[path] = true;
                                if (section.children && section.children.length > 0) {
                                    buildPaths(section.children, path);
                                }
                            });
                        };
                        buildPaths(this.systemPromptPreview.sections);
                        this.systemPromptPreview.expandedSections = expanded;
                    }
                },

                flattenSystemPromptSections(sections) {
                    let parts = [];
                    for (const section of sections) {
                        if (section.content) parts.push(section.content);
                        if (section.children && section.children.length > 0) {
                            parts.push(this.flattenSystemPromptSections(section.children));
                        }
                    }
                    return parts.filter(p => p).join('\n\n');
                },

                async copySystemPrompt() {
                    const fullPrompt = this.flattenSystemPromptSections(this.systemPromptPreview.sections);
                    try {
                        await navigator.clipboard.writeText(fullPrompt);
                        this.systemPromptPreview.copied = true;
                        setTimeout(() => {
                            this.systemPromptPreview.copied = false;
                        }, 2000);
                    } catch (err) {
                        console.error('Failed to copy:', err);
                    }
                },

                parseMarkdownForSystemPrompt(text) {
                    if (!text) return '';
                    // Use marked + DOMPurify for rendering
                    let html = marked.parse(text);
                    return DOMPurify.sanitize(html);
                },

                // ==================== Workspace Methods ====================

                async fetchWorkspaces() {
                    try {
                        const response = await fetch('/api/workspaces');
                        if (response.ok) {
                            this.workspaces = await response.json();
                            this.debugLog('Workspaces loaded', { count: this.workspaces.length });
                        }
                    } catch (err) {
                        console.error('Failed to fetch workspaces:', err);
                    }
                },

                async fetchActiveWorkspace() {
                    try {
                        const response = await fetch('/api/workspaces/active');
                        if (response.ok) {
                            const data = await response.json();
                            if (data.workspace) {
                                this.currentWorkspace = data.workspace;
                                this.currentWorkspaceId = data.workspace.id;
                                this.debugLog('Active workspace loaded', { workspace: data.workspace.name });
                            }
                        }
                    } catch (err) {
                        console.error('Failed to fetch active workspace:', err);
                    }
                },

                async openWorkspaceSelector() {
                    this.showWorkspaceSelector = true;
                    if (this.workspaces.length === 0) {
                        this.workspacesLoading = true;
                        await this.fetchWorkspaces();
                        this.workspacesLoading = false;
                    }
                },

                async selectWorkspace(workspace) {
                    if (!workspace || workspace.id === this.currentWorkspaceId) {
                        this.showWorkspaceSelector = false;
                        return;
                    }

                    try {
                        const response = await fetch(`/api/workspaces/active/${workspace.id}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            }
                        });

                        if (response.ok) {
                            const data = await response.json();
                            this.currentWorkspace = workspace;
                            this.currentWorkspaceId = workspace.id;
                            this.showWorkspaceSelector = false;
                            this.debugLog('Workspace switched', { workspace: workspace.name, lastConversation: data.last_conversation_uuid });

                            // Clear old workspace state before loading new data
                            this.agents = [];
                            this.currentAgentId = null;
                            this.currentConversationUuid = null;

                            // Reload conversations and agents for the new workspace
                            await this.fetchConversations();
                            await this.fetchAgents();

                            // Restore last conversation for this workspace, or start new
                            if (data.last_conversation_uuid) {
                                // Check if conversation still exists in the loaded list
                                const lastConvo = this.conversations.find(c => c.uuid === data.last_conversation_uuid);
                                if (lastConvo) {
                                    await this.loadConversation(data.last_conversation_uuid, true); // skipWorkspaceCheck=true
                                } else {
                                    this.newConversation();
                                }
                            } else {
                                this.newConversation();
                            }
                        } else {
                            console.error('Failed to switch workspace:', response.status);
                            this.errorMessage = 'Failed to switch workspace. Please try again.';
                        }
                    } catch (err) {
                        console.error('Error switching workspace:', err);
                        this.errorMessage = 'Failed to switch workspace. Please try again.';
                    }
                },

                // ==================== End Workspace Methods ====================

                async fetchSettings() {
                    try {
                        const response = await fetch('/api/settings/chat-defaults');
                        const data = await response.json();

                        // Apply saved defaults (only if provider is available)
                        if (data.provider && this.providers[data.provider]?.available) {
                            this.provider = data.provider;
                            this.updateModels();
                        }
                        if (data.model && this.availableModels[data.model]) {
                            this.model = data.model;
                        }
                        if (data.response_level !== undefined) {
                            this.responseLevel = data.response_level;
                        }
                        // Provider-specific reasoning settings
                        if (data.anthropic_thinking_budget !== undefined) {
                            this.anthropicThinkingBudget = data.anthropic_thinking_budget;
                        }
                        if (data.openai_reasoning_effort !== undefined) {
                            this.openaiReasoningEffort = data.openai_reasoning_effort;
                        }
                        if (data.openai_compatible_reasoning_effort !== undefined) {
                            this.openaiCompatibleReasoningEffort = data.openai_compatible_reasoning_effort;
                        }
                        if (data.claude_code_thinking_tokens !== undefined) {
                            this.claudeCodeThinkingTokens = data.claude_code_thinking_tokens;
                        }
                        if (data.claude_code_allowed_tools !== undefined) {
                            // If empty array, default to all tools selected
                            const savedTools = data.claude_code_allowed_tools || [];
                            this.claudeCodeAllowedTools = savedTools.length > 0
                                ? savedTools
                                : [...this.claudeCodeAvailableTools];
                        }
                    } catch (err) {
                        console.error('Failed to fetch settings:', err);
                    }
                },

                async saveDefaultSettings() {
                    // Only save if we're not in a conversation (these are defaults for new conversations)
                    if (this.currentConversationUuid) return;

                    try {
                        await fetch('/api/settings/chat-defaults', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                provider: this.provider,
                                model: this.model,
                                response_level: this.responseLevel,
                                // Provider-specific reasoning settings
                                anthropic_thinking_budget: this.anthropicThinkingBudget,
                                openai_reasoning_effort: this.openaiReasoningEffort,
                                openai_compatible_reasoning_effort: this.openaiCompatibleReasoningEffort,
                                claude_code_thinking_tokens: this.claudeCodeThinkingTokens,
                                claude_code_allowed_tools: this.claudeCodeAllowedTools,
                            })
                        });
                    } catch (err) {
                        console.error('Failed to save settings:', err);
                    }
                },

                async fetchPricing() {
                    try {
                        const response = await fetch('/api/pricing');
                        const data = await response.json();
                        // Flatten the provider-grouped structure into our flat modelPricing
                        if (data.pricing) {
                            for (const [provider, models] of Object.entries(data.pricing)) {
                                for (const [modelId, pricing] of Object.entries(models)) {
                                    this.modelPricing[modelId] = pricing;
                                }
                            }
                        }
                    } catch (err) {
                        console.error('Failed to fetch pricing:', err);
                        // Fall back to defaults already in modelPricing
                    }
                },

                updateModels() {
                    if (this.providers[this.provider]) {
                        this.availableModels = this.providers[this.provider].models || {};
                        const models = Object.keys(this.availableModels);
                        if (models.length > 0 && !this.availableModels[this.model]) {
                            this.model = models[0];
                        }
                    }
                },

                // Get short display name for a model ID
                getModelDisplayName(modelId) {
                    if (!modelId) return '';
                    // Check all providers for the model name
                    for (const [providerKey, provider] of Object.entries(this.providers)) {
                        if (provider.models?.[modelId]?.name) {
                            return provider.models[modelId].name;
                        }
                    }
                    // Fallback: extract readable name from model ID
                    return modelId.replace(/^claude-/, '').replace(/^gpt-/, 'GPT-').replace(/-\d+$/, '');
                },

                async fetchConversations() {
                    try {
                        let url = '/api/conversations';
                        const params = [];
                        if (this.currentWorkspaceId) {
                            params.push('workspace_id=' + this.currentWorkspaceId);
                        }
                        if (this.showArchivedConversations) {
                            params.push('include_archived=true');
                        }
                        if (params.length > 0) {
                            url += '?' + params.join('&');
                        }
                        const response = await fetch(url);
                        const data = await response.json();
                        this.conversations = data.data || [];
                        this.conversationsPage = data.current_page || 1;
                        this.conversationsLastPage = data.last_page || 1;

                        // Update cached latest activity from first conversation
                        if (this.conversations.length > 0) {
                            this.cachedLatestActivity = this.conversations[0].last_activity_at;
                        }
                    } catch (err) {
                        console.error('Failed to fetch conversations:', err);
                    }
                },

                // Start polling for sidebar updates (every 30s)
                startSidebarPolling() {
                    // Clear any existing interval
                    if (this.sidebarPollInterval) {
                        clearInterval(this.sidebarPollInterval);
                    }

                    this.sidebarPollInterval = setInterval(() => {
                        this.checkForSidebarUpdates();
                    }, 30000); // 30 seconds
                },

                // Stop polling (e.g., on page unload)
                stopSidebarPolling() {
                    if (this.sidebarPollInterval) {
                        clearInterval(this.sidebarPollInterval);
                        this.sidebarPollInterval = null;
                    }
                },

                // Check if sidebar needs refreshing
                async checkForSidebarUpdates() {
                    // Skip if search filter is active
                    if (this.conversationSearchQuery) {
                        return;
                    }

                    try {
                        const response = await fetch('/api/conversations/latest-activity');
                        const data = await response.json();

                        // If there's new activity, refresh the first page
                        if (data.latest_activity_at && data.latest_activity_at !== this.cachedLatestActivity) {
                            this.debugLog('Sidebar: new activity detected, refreshing');
                            await this.refreshSidebar();
                        }
                    } catch (err) {
                        console.error('Failed to check for sidebar updates:', err);
                    }
                },

                // Refresh sidebar without losing scroll position or current selection
                async refreshSidebar() {
                    try {
                        let url = '/api/conversations';
                        const params = [];
                        if (this.currentWorkspaceId) {
                            params.push('workspace_id=' + this.currentWorkspaceId);
                        }
                        if (this.showArchivedConversations) {
                            params.push('include_archived=true');
                        }
                        if (params.length > 0) {
                            url += '?' + params.join('&');
                        }
                        const response = await fetch(url);
                        const data = await response.json();
                        const newConversations = data.data || [];

                        // Update cached latest activity
                        if (newConversations.length > 0) {
                            this.cachedLatestActivity = newConversations[0].last_activity_at;
                        }

                        // Merge new conversations with existing (preserve any extra pages loaded)
                        const existingUuids = new Set(newConversations.map(c => c.uuid));
                        const extraConversations = this.conversations.filter(c => !existingUuids.has(c.uuid));

                        // Replace first page, keep any extras from infinite scroll
                        this.conversations = [...newConversations, ...extraConversations];
                    } catch (err) {
                        console.error('Failed to refresh sidebar:', err);
                    }
                },

                async fetchMoreConversations() {
                    if (this.loadingMoreConversations || this.conversationsPage >= this.conversationsLastPage) {
                        return;
                    }
                    this.loadingMoreConversations = true;
                    try {
                        const nextPage = this.conversationsPage + 1;
                        const params = [`page=${nextPage}`];
                        if (this.currentWorkspaceId) {
                            params.push('workspace_id=' + this.currentWorkspaceId);
                        }
                        if (this.showArchivedConversations) {
                            params.push('include_archived=true');
                        }
                        const url = '/api/conversations?' + params.join('&');
                        const response = await fetch(url);
                        const data = await response.json();
                        if (data.data && data.data.length > 0) {
                            this.conversations = [...this.conversations, ...data.data];
                            this.conversationsPage = data.current_page;
                            this.conversationsLastPage = data.last_page;
                        }
                    } catch (err) {
                        console.error('Failed to fetch more conversations:', err);
                    } finally {
                        this.loadingMoreConversations = false;
                    }
                },

                handleConversationsScroll(event) {
                    const el = event.target;
                    const threshold = 50; // pixels from bottom
                    if (el.scrollHeight - el.scrollTop - el.clientHeight < threshold) {
                        this.fetchMoreConversations();
                    }
                },

                // Conversation search
                async searchConversations() {
                    if (!this.conversationSearchQuery.trim()) {
                        this.conversationSearchResults = [];
                        return;
                    }

                    this.conversationSearchLoading = true;
                    try {
                        let url = `/api/conversations/search?query=${encodeURIComponent(this.conversationSearchQuery)}&limit=20`;
                        if (this.currentWorkspaceId) {
                            url += '&workspace_id=' + this.currentWorkspaceId;
                        }
                        if (this.showArchivedConversations) {
                            url += '&include_archived=true';
                        }
                        const response = await fetch(url);
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        const data = await response.json();
                        this.conversationSearchResults = data.results || [];
                    } catch (err) {
                        console.error('Failed to search conversations:', err);
                        this.conversationSearchResults = [];
                    } finally {
                        this.conversationSearchLoading = false;
                    }
                },

                clearConversationSearch() {
                    this.conversationSearchQuery = '';
                    this.conversationSearchResults = [];
                    this.showSearchInput = false;
                },

                clearAllFilters() {
                    this.conversationSearchQuery = '';
                    this.conversationSearchResults = [];
                    this.showArchivedConversations = false;
                    this.showSearchInput = false;
                    // Clear sessionStorage (also done by $watch, but explicit for clarity)
                    sessionStorage.removeItem('pocketdev_showArchivedConversations');
                    sessionStorage.removeItem('pocketdev_conversationSearchQuery');
                    this.fetchConversations(); // Refresh list without archived
                },

                async loadSearchResult(result) {
                    // Close mobile drawer but keep search input visible while search is active
                    this.showMobileDrawer = false;

                    // Set pending scroll target - loadConversation will scroll to this turn
                    this.pendingScrollToTurn = result.turn_number;

                    // Disable auto-scroll to prevent other code from scrolling to bottom
                    this.autoScrollEnabled = false;

                    // Load the conversation (will scroll to turn instead of bottom)
                    await this.loadConversation(result.conversation_uuid);
                },

                async newConversation() {
                    // Disconnect from any active stream
                    this.disconnectFromStream();

                    this.currentConversationUuid = null;
                    this.currentConversationStatus = null; // Reset status for new conversation
                    this.currentConversationTitle = null; // Reset title for new conversation
                    this.conversationProvider = null; // Reset for new conversation
                    this.messages = [];
                    this.sessionCost = 0;
                    this.totalTokens = 0;
                    this.inputTokens = 0;
                    this.outputTokens = 0;
                    this.cacheCreationTokens = 0;
                    this.cacheReadTokens = 0;
                    this.isStreaming = false;
                    this.lastEventIndex = 0;
                    this.resetContextTracking();

                    // Clear URL to base path
                    this.updateUrl(null);

                    // Re-fetch agents and reload current agent's settings
                    await this.fetchAgents();

                    // Force reload agent settings (fetchAgents skips if agent already selected)
                    if (this.currentAgentId) {
                        const agent = this.agents.find(a => a.id === this.currentAgentId);
                        if (agent) {
                            this.selectAgent(agent, false);
                        }
                    }
                },

                async toggleArchiveConversation() {
                    // Note: API routes don't use CSRF middleware - Laravel excludes it by design for stateless APIs
                    if (!this.currentConversationUuid) return;

                    const isArchived = this.currentConversationStatus === 'archived';
                    const action = isArchived ? 'unarchive' : 'archive';
                    try {
                        const response = await fetch(`/api/conversations/${this.currentConversationUuid}/${action}`, {
                            method: 'POST'
                        });
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);

                        // Update local state - 'idle' is intentional for unarchive since completed
                        // conversations naturally rest at 'idle', and 'failed' ones can be retried
                        this.currentConversationStatus = isArchived ? 'idle' : 'archived';

                        // Refresh conversation list
                        await this.fetchConversations();
                    } catch (err) {
                        console.error('Failed to toggle archive:', err);
                        this.showError('Failed to ' + action + ' conversation');
                    }
                },

                async deleteConversation() {
                    // Note: API routes don't use CSRF middleware - Laravel excludes it by design for stateless APIs
                    if (!this.currentConversationUuid) return;

                    if (!confirm('Are you sure you want to delete this conversation?\n\nThis is a soft delete - the conversation will be hidden but can be recovered directly through the database if needed.')) {
                        return;
                    }

                    try {
                        const response = await fetch(`/api/conversations/${this.currentConversationUuid}`, {
                            method: 'DELETE'
                        });
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);

                        // Reset to new conversation
                        this.newConversation();

                        // Refresh conversation list
                        await this.fetchConversations();
                    } catch (err) {
                        console.error('Failed to delete conversation:', err);
                        this.showError('Failed to delete conversation');
                    }
                },

                openRenameModal() {
                    if (!this.currentConversationUuid) return;
                    this.renameTitle = this.currentConversationTitle || '';
                    this.showRenameModal = true;
                    // Focus input after modal opens
                    this.$nextTick(() => {
                        this.$refs.renameTitleInput?.focus();
                        this.$refs.renameTitleInput?.select();
                    });
                },

                async saveConversationTitle() {
                    if (!this.currentConversationUuid || !this.renameTitle.trim()) return;

                    // Enforce max character limit
                    if (this.renameTitle.trim().length > window.TITLE_MAX_LENGTH) {
                        this.showError(`Title cannot exceed ${window.TITLE_MAX_LENGTH} characters`);
                        return;
                    }

                    this.renameSaving = true;
                    try {
                        const response = await fetch(`/api/conversations/${this.currentConversationUuid}/title`, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ title: this.renameTitle.trim() })
                        });

                        if (!response.ok) throw new Error(`HTTP ${response.status}`);

                        const data = await response.json();
                        this.currentConversationTitle = data.title;

                        // Update the conversation in the sidebar list
                        const conv = this.conversations.find(c => c.uuid === this.currentConversationUuid);
                        if (conv) {
                            conv.title = data.title;
                        }

                        this.showRenameModal = false;
                    } catch (err) {
                        console.error('Failed to rename conversation:', err);
                        this.showError('Failed to rename conversation');
                    } finally {
                        this.renameSaving = false;
                    }
                },

                async loadConversation(uuid, skipWorkspaceCheck = false) {
                    // Skip if already loading this exact conversation
                    if (this._loadingConversationUuid === uuid) {
                        return;
                    }

                    // Show loading overlay and track which conversation is loading
                    this.loadingConversation = true;
                    this._loadingConversationUuid = uuid;

                    // Disconnect from any current stream before switching
                    this.disconnectFromStream();

                    try {
                        const response = await fetch(`/api/conversations/${uuid}`);
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}`);
                        }
                        const data = await response.json();
                        if (!data?.conversation) {
                            throw new Error('Missing conversation payload');
                        }

                        // Guard: abort if user switched to different conversation during fetch
                        if (this._loadingConversationUuid !== uuid) {
                            return;
                        }

                        // Check if conversation belongs to a different workspace
                        const conversationWorkspaceId = data.conversation.workspace_id;
                        if (!skipWorkspaceCheck && conversationWorkspaceId && conversationWorkspaceId !== this.currentWorkspaceId) {
                            // Find workspace in list, or fetch it
                            let targetWorkspace = this.workspaces.find(w => w.id === conversationWorkspaceId);
                            if (!targetWorkspace) {
                                await this.fetchWorkspaces();
                                targetWorkspace = this.workspaces.find(w => w.id === conversationWorkspaceId);
                            }

                            if (!targetWorkspace) {
                                console.error('Workspace not found for conversation', { conversationWorkspaceId });
                                this.showError('Cannot load conversation: workspace not found');
                                this.loadingConversation = false;
                                this._loadingConversationUuid = null;
                                return;
                            }

                            try {
                                // Switch to the workspace (but skip loading last conversation since we want this one)
                                const switchResponse = await fetch(`/api/workspaces/active/${conversationWorkspaceId}`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                                    }
                                });

                                if (switchResponse.ok) {
                                    this.currentWorkspace = targetWorkspace;
                                    this.currentWorkspaceId = targetWorkspace.id;

                                    // Reload conversations and agents for the new workspace
                                    await this.fetchConversations();
                                    await this.fetchAgents();

                                    // Clear the loading state before recursive call
                                    // Otherwise the duplicate load guard will block it
                                    this._loadingConversationUuid = null;

                                    // Reload conversation with new workspace context
                                    return this.loadConversation(uuid, true);
                                } else {
                                    this.loadingConversation = false;
                                    this._loadingConversationUuid = null;
                                    this.showError('Failed to switch workspace');
                                    return;
                                }
                            } catch (err) {
                                console.error('Failed to switch workspace:', err);
                                this.showError('Failed to switch workspace');
                                this.loadingConversation = false;
                                this._loadingConversationUuid = null;
                                return;
                            }
                        }

                        // Only set state after validating response
                        this.currentConversationUuid = uuid;

                        // Update URL to reflect loaded conversation
                        this.updateUrl(uuid);
                        this.messages = [];
                        this.isAtBottom = true; // Hide scroll button during load
                        // Only enable auto-scroll if not coming from search result
                        if (this.pendingScrollToTurn === null) {
                            this.autoScrollEnabled = true;
                        }
                        this.ignoreScrollEvents = true; // Ignore scroll events until load complete

                        // Reset token counters
                        this.inputTokens = 0;
                        this.outputTokens = 0;
                        this.cacheCreationTokens = 0;
                        this.cacheReadTokens = 0;
                        this.sessionCost = 0;
                        this.lastEventIndex = 0;

                        // Reset stream state for potential reconnection
                        this._streamState = {
                            thinkingBlocks: {},
                            currentThinkingBlock: -1,
                            textMsgIndex: -1,
                            toolMsgIndex: -1,
                            textContent: '',
                            toolInput: '',
                            turnCost: 0,
                            turnInputTokens: 0,
                            turnOutputTokens: 0,
                            turnCacheCreationTokens: 0,
                            turnCacheReadTokens: 0,
                            toolInProgress: false,
                            waitingForToolResults: new Set(),
                            abortPending: false,
                            abortSkipSync: false,
                        };

                        // Calculate totals and sum costs from stored values
                        if (data.conversation?.messages) {
                            for (const msg of data.conversation.messages) {
                                const inputToks = msg.input_tokens || 0;
                                const outputToks = msg.output_tokens || 0;
                                const cacheCreate = msg.cache_creation_tokens || 0;
                                const cacheRead = msg.cache_read_tokens || 0;

                                this.inputTokens += inputToks;
                                this.outputTokens += outputToks;
                                this.cacheCreationTokens += cacheCreate;
                                this.cacheReadTokens += cacheRead;

                                // Use stored cost (calculated server-side)
                                if (msg.cost) {
                                    this.sessionCost += msg.cost;
                                }
                            }
                        }

                        this.totalTokens = this.inputTokens + this.outputTokens;

                        // Load context window tracking data
                        if (data.context) {
                            this.contextWindowSize = data.context.context_window_size || 0;
                            this.lastContextTokens = data.context.last_context_tokens || 0;
                            this.contextPercentage = data.context.usage_percentage || 0;
                            this.contextWarningLevel = data.context.warning_level || 'safe';
                        } else {
                            this.resetContextTracking();
                        }

                        // Parse messages for display using progressive rendering
                        if (data.conversation?.messages?.length > 0) {
                            await this.loadMessagesProgressively(
                                data.conversation.messages,
                                this.pendingScrollToTurn,
                                uuid
                            );
                        } else {
                            // No messages - clear loading overlay immediately
                            this.loadingConversation = false;
                            this._loadingConversationUuid = null;
                        }

                        // Update provider/model from conversation
                        if (data.conversation?.provider_type) {
                            this.provider = data.conversation.provider_type;
                            this.conversationProvider = data.conversation.provider_type; // Store for agent filtering
                            this.updateModels(); // Refresh available models for this provider
                        }
                        if (data.conversation?.model) {
                            this.model = data.conversation.model;
                        }

                        // Update conversation status for header badge
                        this.currentConversationStatus = data.conversation?.status || 'idle';

                        // Update conversation title for header
                        this.currentConversationTitle = data.conversation?.title || null;

                        // Set agent from conversation (don't use selectAgent which would PATCH backend)
                        if (data.conversation?.agent_id) {
                            // If agents not yet loaded, fetch them first
                            if (this.agents.length === 0) {
                                await this.fetchAgents();
                            }

                            const agent = this.agents.find(a => a.id === data.conversation.agent_id);
                            if (agent) {
                                // Set agent state directly - don't call selectAgent() as that
                                // would try to PATCH the backend (designed for user-initiated switches)
                                this.currentAgentId = agent.id;
                                this.claudeCodeAllowedTools = agent.allowed_tools || [];
                                // Clear any stale skill state and refresh for this agent
                                this.clearActiveSkill();
                                this.fetchSkills();
                            } else {
                                console.warn(`Agent ${data.conversation.agent_id} not found in available agents`);
                                this.currentAgentId = null;
                            }
                        } else {
                            // Conversation has no agent - reset agent state
                            this.currentAgentId = null;
                        }

                        // Load provider-specific reasoning settings from conversation
                        this.responseLevel = data.conversation?.response_level ?? 1;
                        this.anthropicThinkingBudget = data.conversation?.anthropic_thinking_budget ?? 0;
                        this.openaiReasoningEffort = data.conversation?.openai_reasoning_effort ?? 'none';
                        this.openaiCompatibleReasoningEffort = data.conversation?.openai_compatible_reasoning_effort ?? 'none';
                        this.claudeCodeThinkingTokens = data.conversation?.claude_code_thinking_tokens ?? 0;

                        // Note: scrolling is handled inside loadMessagesProgressively
                        // Clear pendingScrollToTurn since it was passed to loadMessagesProgressively
                        this.pendingScrollToTurn = null;

                        // Re-enable scroll event handling after scroll completes
                        this.$nextTick(() => {
                            this.ignoreScrollEvents = false;
                        });

                        // Check if there's an active stream for this conversation
                        await this.checkAndReconnectStream(uuid);

                    } catch (err) {
                        this.loadingConversation = false;
                        this._loadingConversationUuid = null;
                        console.error('Failed to load conversation:', err);
                        this.showError('Failed to load conversation');
                        // Reset local state and clear URL without creating a back-button loop
                        this.currentConversationUuid = null;
                        this.messages = [];
                        this.updateUrl(null, { replace: true });
                    }
                },

                // Check for active stream and reconnect if found
                async checkAndReconnectStream(uuid) {
                    // Don't reconnect if we just finished streaming (prevents duplicate events)
                    if (this._justCompletedStream) {
                        return;
                    }

                    try {
                        const response = await fetch(`/api/conversations/${uuid}/stream-status`);
                        const data = await response.json();

                        if (data.is_streaming) {
                            // Connect to stream events from the beginning to get buffered events
                            await this.connectToStreamEvents(0);
                        }
                    } catch (err) {
                        // No active stream, that's fine
                    }
                },

                /**
                 * Progressive message loading - renders priority messages first, then fills in the rest.
                 * For normal load: shows last N messages immediately, then prepends older ones.
                 * For search: shows messages around target turn first, then fills in.
                 * @param {string} loadUuid - UUID of conversation being loaded (for race condition guard)
                 */
                async loadMessagesProgressively(dbMessages, targetTurn = null, loadUuid = null) {
                    const INITIAL_BATCH = 100;  // Messages to show immediately
                    const PREPEND_BATCH = 50;   // Messages per prepend batch

                    // Convert all messages to UI format first
                    const allUiMessages = [];
                    // Track pending tool_results that need linking (tool_use in different db message)
                    const pendingToolResults = [];

                    for (const msg of dbMessages) {
                        const converted = this.convertDbMessageToUi(msg, pendingToolResults);
                        allUiMessages.push(...converted);
                    }

                    // Post-process: link any pending tool_results to their tool_use messages
                    // This handles cases where tool_result is in a separate db message from tool_use
                    for (const pending of pendingToolResults) {
                        const toolMsgIndex = allUiMessages.findIndex(m => m.role === 'tool' && m.toolId === pending.tool_use_id);
                        if (toolMsgIndex >= 0) {
                            allUiMessages[toolMsgIndex] = {
                                ...allUiMessages[toolMsgIndex],
                                toolResult: pending.content
                            };
                        }
                    }

                    if (allUiMessages.length === 0) {
                        this.loadingConversation = false;
                        this._loadingConversationUuid = null;
                        return;
                    }

                    // Determine which messages to render first based on context
                    let priorityStartIndex;
                    if (targetTurn !== null) {
                        // Search case: find messages around the target turn
                        const targetIndex = allUiMessages.findIndex(m => m.turn_number === targetTurn);
                        if (targetIndex !== -1) {
                            // Center the initial batch around the target
                            priorityStartIndex = Math.max(0, targetIndex - Math.floor(INITIAL_BATCH / 2));
                        } else {
                            // Target not found, fall back to end
                            priorityStartIndex = Math.max(0, allUiMessages.length - INITIAL_BATCH);
                        }
                    } else {
                        // Normal load: show last N messages first
                        priorityStartIndex = Math.max(0, allUiMessages.length - INITIAL_BATCH);
                    }

                    // Split messages into priority (render first) and before (prepend later)
                    const messagesBefore = allUiMessages.slice(0, priorityStartIndex);
                    const priorityMessages = allUiMessages.slice(priorityStartIndex);

                    // Guard: abort if user switched to different conversation during processing
                    if (loadUuid && this.currentConversationUuid !== loadUuid) {
                        return;
                    }

                    // Phase 1: Render priority messages immediately
                    this.messages = priorityMessages;

                    // Wait for initial render and scroll
                    await new Promise(resolve => {
                        this.$nextTick(() => {
                            if (targetTurn !== null) {
                                this.scrollToTurn(targetTurn);
                            } else {
                                this.scrollToBottom();
                            }
                            resolve();

                            // Hide loading overlay AFTER scroll has painted
                            requestAnimationFrame(() => {
                                // Guard: only clear if still on same conversation
                                if (!loadUuid || this.currentConversationUuid === loadUuid) {
                                    this.loadingConversation = false;
                                    this._loadingConversationUuid = null;
                                }
                            });
                        });
                    });

                    // Phase 2: Prepend older messages in batches (if any) - runs in background, don't await
                    if (messagesBefore.length > 0) {
                        this.prependMessagesInBatches(messagesBefore, PREPEND_BATCH, loadUuid);
                    }
                },

                /**
                 * Prepends messages in batches. Browser's scroll anchoring maintains scroll position.
                 * @param {string} loadUuid - UUID of conversation being loaded (for race condition guard)
                 */
                async prependMessagesInBatches(messages, batchSize, loadUuid = null) {
                    let remaining = [...messages];

                    while (remaining.length > 0) {
                        // Guard: abort if user switched to different conversation
                        if (loadUuid && this.currentConversationUuid !== loadUuid) {
                            return;
                        }

                        // Take a batch from the END (newest of the old messages)
                        const batch = remaining.slice(-batchSize);
                        remaining = remaining.slice(0, -batchSize);

                        // Prepend batch to beginning of messages array
                        this.messages.unshift(...batch);

                        // Yield to main thread between batches (lets browser render and handle scroll anchoring)
                        await new Promise(resolve => requestAnimationFrame(resolve));
                    }
                },

                /**
                 * Converts a DB message to UI format. Returns array (content blocks expand to multiple).
                 * @param {Object} dbMsg - The database message to convert
                 * @param {Array} pendingToolResults - Optional array to collect tool_results that couldn't be linked
                 *                                     (because tool_use is in a different db message)
                 */
                convertDbMessageToUi(dbMsg, pendingToolResults = null) {
                    const result = [];
                    const content = dbMsg.content;
                    const msgInputTokens = dbMsg.input_tokens || 0;
                    const msgOutputTokens = dbMsg.output_tokens || 0;
                    const msgCacheCreation = dbMsg.cache_creation_tokens || 0;
                    const msgCacheRead = dbMsg.cache_read_tokens || 0;
                    const msgModel = dbMsg.model || this.model;
                    const msgCost = dbMsg.cost || null;

                    if (typeof content === 'string') {
                        result.push({
                            id: 'msg-' + Date.now() + '-' + Math.random(),
                            role: dbMsg.role,
                            content: content,
                            timestamp: dbMsg.created_at,
                            collapsed: false,
                            cost: msgCost,
                            model: msgModel,
                            inputTokens: msgInputTokens,
                            outputTokens: msgOutputTokens,
                            cacheCreationTokens: msgCacheCreation,
                            cacheReadTokens: msgCacheRead,
                            agent: dbMsg.agent,
                            turn_number: dbMsg.turn_number
                        });
                    } else if (Array.isArray(content)) {
                        if (content.length === 0) {
                            if (msgCost && dbMsg.role === 'assistant') {
                                result.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'empty-response',
                                    content: null,
                                    timestamp: dbMsg.created_at,
                                    collapsed: false,
                                    cost: msgCost,
                                    model: msgModel,
                                    inputTokens: msgInputTokens,
                                    outputTokens: msgOutputTokens,
                                    cacheCreationTokens: msgCacheCreation,
                                    cacheReadTokens: msgCacheRead,
                                    agent: dbMsg.agent,
                                    turn_number: dbMsg.turn_number
                                });
                            }
                            return result;
                        }

                        const lastBlockIndex = content.length - 1;

                        for (let i = 0; i < content.length; i++) {
                            const block = content[i];
                            const isLast = (i === lastBlockIndex);

                            if (block.type === 'text') {
                                result.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'assistant',
                                    content: block.text,
                                    timestamp: dbMsg.created_at,
                                    collapsed: false,
                                    cost: isLast ? msgCost : null,
                                    model: isLast ? msgModel : null,
                                    inputTokens: isLast ? msgInputTokens : null,
                                    outputTokens: isLast ? msgOutputTokens : null,
                                    cacheCreationTokens: isLast ? msgCacheCreation : null,
                                    cacheReadTokens: isLast ? msgCacheRead : null,
                                    agent: isLast ? dbMsg.agent : null,
                                    turn_number: dbMsg.turn_number
                                });
                            } else if (block.type === 'thinking') {
                                result.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'thinking',
                                    content: block.thinking,
                                    timestamp: dbMsg.created_at,
                                    collapsed: true,
                                    cost: isLast ? msgCost : null,
                                    model: isLast ? msgModel : null,
                                    inputTokens: isLast ? msgInputTokens : null,
                                    outputTokens: isLast ? msgOutputTokens : null,
                                    cacheCreationTokens: isLast ? msgCacheCreation : null,
                                    cacheReadTokens: isLast ? msgCacheRead : null,
                                    agent: isLast ? dbMsg.agent : null,
                                    turn_number: dbMsg.turn_number
                                });
                            } else if (block.type === 'tool_use') {
                                result.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'tool',
                                    toolName: block.name,
                                    toolId: block.id,
                                    toolInput: block.input,
                                    toolResult: null,
                                    content: JSON.stringify(block.input, null, 2),
                                    timestamp: dbMsg.created_at,
                                    collapsed: true,
                                    cost: isLast ? msgCost : null,
                                    model: isLast ? msgModel : null,
                                    inputTokens: isLast ? msgInputTokens : null,
                                    outputTokens: isLast ? msgOutputTokens : null,
                                    cacheCreationTokens: isLast ? msgCacheCreation : null,
                                    cacheReadTokens: isLast ? msgCacheRead : null,
                                    agent: isLast ? dbMsg.agent : null,
                                    turn_number: dbMsg.turn_number
                                });
                            } else if (block.type === 'tool_result' && block.tool_use_id) {
                                // Link result to the corresponding tool message in result array
                                const toolMsgIndex = result.findIndex(m => m.role === 'tool' && m.toolId === block.tool_use_id);
                                if (toolMsgIndex >= 0) {
                                    result[toolMsgIndex] = {
                                        ...result[toolMsgIndex],
                                        toolResult: block.content
                                    };
                                } else if (pendingToolResults) {
                                    // Tool not found in this message - collect for post-processing
                                    // (tool_use is likely in a different db message)
                                    pendingToolResults.push({
                                        tool_use_id: block.tool_use_id,
                                        content: block.content
                                    });
                                }
                            } else if (block.type === 'interrupted') {
                                result.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'interrupted',
                                    content: 'Response interrupted',
                                    timestamp: dbMsg.created_at,
                                    turn_number: dbMsg.turn_number
                                });
                            } else if (block.type === 'error') {
                                result.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'error',
                                    content: block.message || 'An unexpected error occurred',
                                    timestamp: dbMsg.created_at,
                                    turn_number: dbMsg.turn_number
                                });
                            } else if (block.type === 'system') {
                                result.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'system',
                                    content: block.content,
                                    subtype: block.subtype,
                                    timestamp: dbMsg.created_at,
                                    collapsed: false,
                                    cost: isLast ? msgCost : null,
                                    model: isLast ? msgModel : null,
                                    inputTokens: isLast ? msgInputTokens : null,
                                    outputTokens: isLast ? msgOutputTokens : null,
                                    cacheCreationTokens: isLast ? msgCacheCreation : null,
                                    cacheReadTokens: isLast ? msgCacheRead : null,
                                    agent: isLast ? dbMsg.agent : null,
                                    turn_number: dbMsg.turn_number
                                });
                            }
                        }
                    }
                    return result;
                },

                addMessageFromDb(dbMsg) {
                    // Convert DB message format to UI format
                    const content = dbMsg.content;

                    // Get token counts and stored cost
                    const msgInputTokens = dbMsg.input_tokens || 0;
                    const msgOutputTokens = dbMsg.output_tokens || 0;
                    const msgCacheCreation = dbMsg.cache_creation_tokens || 0;
                    const msgCacheRead = dbMsg.cache_read_tokens || 0;
                    const msgModel = dbMsg.model || this.model;

                    // Use stored cost from server (no client-side calculation)
                    const msgCost = dbMsg.cost || null;

                    if (typeof content === 'string') {
                        this.messages.push({
                            id: 'msg-' + Date.now() + '-' + Math.random(),
                            role: dbMsg.role,
                            content: content,
                            timestamp: dbMsg.created_at,
                            collapsed: false,
                            cost: msgCost,
                            model: msgModel,
                            inputTokens: msgInputTokens,
                            outputTokens: msgOutputTokens,
                            cacheCreationTokens: msgCacheCreation,
                            cacheReadTokens: msgCacheRead,
                            agent: dbMsg.agent,
                            turn_number: dbMsg.turn_number
                        });
                    } else if (Array.isArray(content)) {
                        // Handle empty content arrays (e.g., assistant stopped after tool call)
                        // Only show if there's a cost to display
                        if (content.length === 0) {
                            if (msgCost && dbMsg.role === 'assistant') {
                                this.messages.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'empty-response',
                                    content: null,
                                    timestamp: dbMsg.created_at,
                                    collapsed: false,
                                    cost: msgCost,
                                    model: msgModel,
                                    inputTokens: msgInputTokens,
                                    outputTokens: msgOutputTokens,
                                    cacheCreationTokens: msgCacheCreation,
                                    cacheReadTokens: msgCacheRead,
                                    agent: dbMsg.agent,
                                    turn_number: dbMsg.turn_number
                                });
                            }
                            return;
                        }

                        // Cost goes on the LAST block of this turn (regardless of type)
                        const lastBlockIndex = content.length - 1;

                        // Handle content blocks - attach cost to the LAST block
                        for (let i = 0; i < content.length; i++) {
                            const block = content[i];
                            const isLast = (i === lastBlockIndex);

                            if (block.type === 'text') {
                                this.messages.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'assistant',
                                    content: block.text,
                                    timestamp: dbMsg.created_at,
                                    collapsed: false,
                                    cost: isLast ? msgCost : null,
                                    model: isLast ? msgModel : null,
                                    inputTokens: isLast ? msgInputTokens : null,
                                    outputTokens: isLast ? msgOutputTokens : null,
                                    cacheCreationTokens: isLast ? msgCacheCreation : null,
                                    cacheReadTokens: isLast ? msgCacheRead : null,
                                    agent: isLast ? dbMsg.agent : null,
                                    turn_number: dbMsg.turn_number
                                });
                            } else if (block.type === 'thinking') {
                                this.messages.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'thinking',
                                    content: block.thinking,
                                    timestamp: dbMsg.created_at,
                                    collapsed: true,
                                    cost: isLast ? msgCost : null,
                                    model: isLast ? msgModel : null,
                                    inputTokens: isLast ? msgInputTokens : null,
                                    outputTokens: isLast ? msgOutputTokens : null,
                                    cacheCreationTokens: isLast ? msgCacheCreation : null,
                                    cacheReadTokens: isLast ? msgCacheRead : null,
                                    agent: isLast ? dbMsg.agent : null,
                                    turn_number: dbMsg.turn_number
                                });
                            } else if (block.type === 'tool_use') {
                                this.messages.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'tool',
                                    toolName: block.name,
                                    toolId: block.id,
                                    toolInput: block.input,
                                    toolResult: null,
                                    content: JSON.stringify(block.input, null, 2),
                                    timestamp: dbMsg.created_at,
                                    collapsed: true,
                                    cost: isLast ? msgCost : null,
                                    model: isLast ? msgModel : null,
                                    inputTokens: isLast ? msgInputTokens : null,
                                    outputTokens: isLast ? msgOutputTokens : null,
                                    cacheCreationTokens: isLast ? msgCacheCreation : null,
                                    cacheReadTokens: isLast ? msgCacheRead : null,
                                    agent: isLast ? dbMsg.agent : null,
                                    turn_number: dbMsg.turn_number
                                });
                            } else if (block.type === 'tool_result' && block.tool_use_id) {
                                // Link result to the corresponding tool message
                                const toolMsgIndex = this.messages.findIndex(m => m.role === 'tool' && m.toolId === block.tool_use_id);
                                if (toolMsgIndex >= 0) {
                                    this.messages[toolMsgIndex] = {
                                        ...this.messages[toolMsgIndex],
                                        toolResult: block.content
                                    };
                                }
                            } else if (block.type === 'interrupted') {
                                // Interrupted marker - shown when response was aborted
                                this.messages.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'interrupted',
                                    content: 'Response interrupted',
                                    timestamp: dbMsg.created_at,
                                    turn_number: dbMsg.turn_number
                                });
                            } else if (block.type === 'error') {
                                // Error marker - shown when job failed unexpectedly
                                this.messages.push({
                                    id: 'msg-' + Date.now() + '-' + Math.random(),
                                    role: 'error',
                                    content: block.message || 'An unexpected error occurred',
                                    timestamp: dbMsg.created_at,
                                    turn_number: dbMsg.turn_number
                                });
                            }
                        }
                    }
                },

                async sendMessage() {
                    const attachments = Alpine.store('attachments');

                    // Allow sending if there's text OR files OR active skill
                    if (!this.prompt.trim() && !attachments.hasFiles && !this.activeSkill) return;
                    if (this.isStreaming) return;

                    // Block unmatched /commands - if prompt starts with / but no skill is active
                    if (this.prompt.trim().startsWith('/') && !this.activeSkill) {
                        const potentialSkillName = this.prompt.trim().slice(1).split(/\s+/)[0];
                        const matchedSkill = this.findSkillByName(potentialSkillName);
                        if (!matchedSkill) {
                            this.showError(`Unknown skill: /${potentialSkillName}. Type / to see available skills.`);
                            return;
                        }
                        // If it matches, activate it and continue
                        this.activeSkill = matchedSkill;
                        // Remove the /command from prompt, keep any text after it
                        this.prompt = this.prompt.trim().slice(1 + potentialSkillName.length).trim();
                    }

                    // Block if uploads still in progress
                    if (attachments.isUploading) {
                        this.showError('Please wait for file uploads to complete');
                        return;
                    }

                    // Build the prompt - prepend skill instructions if active
                    let userPrompt = this.prompt;
                    if (this.activeSkill) {
                        const skillHeader = `**PocketDev Skill: ${this.activeSkill.name}**\n\n${this.activeSkill.instructions}`;
                        if (userPrompt.trim()) {
                            userPrompt = skillHeader + '\n\n---\n\n' + userPrompt;
                        } else {
                            userPrompt = skillHeader;
                        }
                        this.activeSkill = null; // Clear after use
                    }
                    const filePaths = attachments.getFilePaths();

                    if (filePaths.length > 0) {
                        const fileSection = '\n\n---\n**Attached Files:**\n' +
                            filePaths.map(p => `- ${p}`).join('\n') +
                            '\n---\n\nPlease read and analyze the attached files as needed.';

                        if (userPrompt.trim()) {
                            userPrompt = userPrompt + fileSection;
                        } else {
                            userPrompt = 'Please analyze the following attached files:' + fileSection;
                        }
                    }

                    this.prompt = '';

                    // Validate agent exists in current workspace
                    const agentValid = this.currentAgentId && this.agents.some(a => a.id === this.currentAgentId);
                    if (!agentValid) {
                        if (this.agents.length > 0) {
                            // Agents available but none/invalid selected - show selector
                            this.showAgentSelector = true;
                        } else {
                            // No agents in this workspace
                            this.showError('No agents available in this workspace. Please switch workspaces or create an agent.');
                        }
                        this.prompt = userPrompt; // Restore prompt
                        return;
                    }

                    // Create conversation if needed
                    if (!this.currentConversationUuid) {

                        try {
                            const createBody = {
                                working_directory: this.currentWorkspace?.working_directory_path || '/workspace',
                                workspace_id: this.currentWorkspaceId,
                                agent_id: this.currentAgentId,
                                title: userPrompt.substring(0, 50),
                            };
                            // Note: When using agent_id, the server uses agent settings for reasoning.
                            // The explicit provider-specific settings below are only for legacy/fallback mode.

                            const response = await fetch('/api/conversations', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(createBody)
                            });
                            const data = await response.json();
                            this.currentConversationUuid = data.conversation.uuid;
                            this.currentConversationTitle = data.conversation.title || null;
                            this.conversationProvider = this.provider; // Lock provider for this conversation

                            // Update URL with new conversation UUID
                            this.updateUrl(this.currentConversationUuid);

                            await this.fetchConversations();
                        } catch (err) {
                            this.showError('Failed to create conversation: ' + err.message);
                            this.prompt = userPrompt; // Restore prompt so user can retry
                            return;
                        }
                    }

                    // Add user message to UI
                    this.messages.push({
                        id: 'msg-' + Date.now(),
                        role: 'user',
                        content: userPrompt,
                        timestamp: new Date().toISOString(),
                        collapsed: false
                    });
                    this.autoScrollEnabled = true; // Re-enable auto-scroll on new message
                    this.scrollToBottom();

                    // Reset stream state
                    this.lastEventIndex = 0;
                    this._justCompletedStream = false;
                    this._streamState = {
                        // Maps block_index -> { msgIndex, content, complete }
                        thinkingBlocks: {},
                        currentThinkingBlock: -1,
                        textMsgIndex: -1,
                        toolMsgIndex: -1,
                        textContent: '',
                        toolInput: '',
                        turnCost: 0,
                        turnInputTokens: 0,
                        turnOutputTokens: 0,
                        turnCacheCreationTokens: 0,
                        turnCacheReadTokens: 0,
                        toolInProgress: false,
                        waitingForToolResults: new Set(),
                        abortPending: false,
                        abortSkipSync: false,
                        startedAt: null, // Will be set from stream response
                    };

                    try {
                        // Build stream request body
                        // NOTE: Reasoning/thinking settings are loaded from the agent on the backend
                        const streamBody = {
                            prompt: userPrompt,
                            response_level: this.responseLevel,
                            model: this.model
                        };

                        // Start the background streaming job
                        const response = await fetch(`/api/conversations/${this.currentConversationUuid}/stream`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(streamBody)
                        });

                        const data = await response.json();

                        if (!data.success) {
                            this.showError(data.error || 'Failed to start streaming');
                            this.prompt = userPrompt; // Restore prompt so user can retry
                            return;
                        }

                        // Capture the stream start timestamp for accurate message times
                        if (data.started_at) {
                            this._streamState.startedAt = data.started_at;
                        }

                        // Clear attachments UI now that stream job has started
                        // (keep files on disk for Claude to read)
                        attachments.clear(false);

                        // Connect to stream events SSE endpoint
                        await this.connectToStreamEvents();

                    } catch (err) {
                        this.showError('Failed to start streaming: ' + err.message);
                        this.isStreaming = false;
                        this.prompt = userPrompt; // Restore prompt so user can retry
                    }
                },

                // Connect to stream events and handle reconnection
                async connectToStreamEvents(fromIndex = 0, retryCount = 0) {
                    if (!this.currentConversationUuid) {
                        return;
                    }

                    // Max retries for not_found status (job hasn't started yet)
                    const maxRetries = 15; // 15 * 200ms = 3 seconds max wait

                    // Abort any existing connection
                    this.disconnectFromStream();

                    // Increment nonce to invalidate any pending reconnection attempts
                    const myNonce = ++this._streamConnectNonce;

                    this.isStreaming = true;
                    this.currentConversationStatus = 'processing'; // Update status badge

                    // Optimistic update: immediately show 'processing' in sidebar
                    // We'll fetch the real status after receiving the first event (when backend has definitely updated)
                    const convIndex = this.conversations.findIndex(c => c.uuid === this.currentConversationUuid);
                    if (convIndex !== -1) {
                        this.conversations[convIndex].status = 'processing';
                    }
                    this._sidebarRefreshedThisStream = false;

                    this.streamAbortController = new AbortController();

                    try {
                        const url = `/api/conversations/${this.currentConversationUuid}/stream-events?from_index=${fromIndex}`;
                        const response = await fetch(url, { signal: this.streamAbortController.signal });

                        // Check if we've been superseded by a newer connection attempt
                        if (myNonce !== this._streamConnectNonce) {
                            return;
                        }

                        const reader = response.body.getReader();
                        const decoder = new TextDecoder();
                        let buffer = '';

                        while (true) {
                            const { done, value } = await reader.read();
                            if (done) break;

                            buffer += decoder.decode(value, { stream: true });
                            const lines = buffer.split('\n');
                            buffer = lines.pop();

                            for (const line of lines) {
                                if (!line.startsWith('data: ')) continue;

                                try {
                                    const event = JSON.parse(line.substring(6));

                                    // Track event index for reconnection
                                    if (event.index !== undefined) {
                                        this.lastEventIndex = event.index + 1;
                                    }

                                    // Handle stream status events
                                    if (event.type === 'stream_status') {
                                        if (event.status === 'not_found') {
                                            // Race condition: job hasn't started yet, retry
                                            if (retryCount < maxRetries) {
                                                // Check nonce before scheduling retry
                                                if (myNonce === this._streamConnectNonce) {
                                                    this._streamRetryTimeoutId = setTimeout(() => this.connectToStreamEvents(0, retryCount + 1), 200);
                                                }
                                                return;
                                            } else {
                                                this.isStreaming = false;
                                                this.currentConversationStatus = 'failed'; // Update status badge
                                                // Refresh sidebar to show failed status
                                                this.fetchConversations();
                                                this.showError('Failed to connect to stream');
                                                return;
                                            }
                                        }
                                        if (event.status === 'completed' || event.status === 'failed') {
                                            this.isStreaming = false;
                                            // Update status badge
                                            this.currentConversationStatus = event.status === 'failed' ? 'failed' : 'idle';
                                            // Prevent reconnection for a short period
                                            this._justCompletedStream = true;
                                            await this.fetchConversations();
                                            // Clear flag after a delay
                                            setTimeout(() => { this._justCompletedStream = false; }, 1000);
                                        }
                                        continue;
                                    }

                                    if (event.type === 'timeout') {
                                        // Reconnect from last known position
                                        // Check nonce before scheduling retry
                                        if (myNonce === this._streamConnectNonce) {
                                            this._streamRetryTimeoutId = setTimeout(() => this.connectToStreamEvents(this.lastEventIndex), 100);
                                        }
                                        return;
                                    }

                                    // Handle regular stream events
                                    this.handleStreamEvent(event);

                                    // On first real event, refresh sidebar (backend has now set status to 'processing')
                                    if (!this._sidebarRefreshedThisStream) {
                                        this._sidebarRefreshedThisStream = true;
                                        this.fetchConversations();
                                    }

                                } catch (parseErr) {
                                    console.error('Parse error:', parseErr, line);
                                }
                            }
                        }
                    } catch (err) {
                        if (err.name === 'AbortError') {
                            console.log('Stream connection aborted');
                        } else {
                            console.error('Stream connection error:', err);
                        }
                    } finally {
                        // Only set isStreaming false if we weren't aborted for reconnection
                        if (!this.streamAbortController?.signal.aborted) {
                            this.isStreaming = false;
                        }
                    }
                },

                // Disconnect from stream (for navigation or cleanup)
                disconnectFromStream() {
                    // Clear any pending retry timeout
                    if (this._streamRetryTimeoutId) {
                        clearTimeout(this._streamRetryTimeoutId);
                        this._streamRetryTimeoutId = null;
                    }

                    if (this.streamAbortController) {
                        this.streamAbortController.abort();
                        this.streamAbortController = null;
                    }
                },

                // Abort the current stream (user clicked stop button)
                async abortStream() {
                    if (!this.isStreaming || !this.currentConversationUuid) {
                        return;
                    }

                    const state = this._streamState;

                    // If tool parameters are being streamed, wait for tool_use_stop
                    if (state.toolInProgress) {
                        console.log('Abort requested while streaming tool parameters - deferring until tool_use_stop');
                        state.abortPending = true;
                        return;
                    }

                    // If tools are waiting for execution results, wait for all tool_results
                    // This ensures CLI providers have complete tool_use + tool_result in their session
                    if (state.waitingForToolResults.size > 0) {
                        console.log('Abort requested while waiting for tool results - deferring until all tools complete');
                        state.abortPending = true;
                        return;
                    }

                    // Determine if we should skip syncing to CLI session file
                    // skipSync=true when aborting after tools completed (CLI already has complete data)
                    const skipSync = state.abortSkipSync;

                    try {
                        const response = await fetch(`/api/conversations/${this.currentConversationUuid}/abort`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ skipSync }),
                        });

                        // Reset skipSync flag (even if request fails, don't carry over to next abort)
                        state.abortSkipSync = false;

                        if (!response.ok) {
                            console.error('Failed to abort stream:', await response.text());
                        }

                        // Clean up in-progress streaming messages
                        // (state was already captured at the start of abortStream)

                        // Remove incomplete thinking blocks (ones without signatures)
                        // Must iterate in reverse order to maintain correct indices when splicing
                        const incompleteThinkingIndices = [];
                        for (const blockIdx in state.thinkingBlocks) {
                            const block = state.thinkingBlocks[blockIdx];
                            if (block && !block.complete && block.msgIndex >= 0 && block.msgIndex < this.messages.length) {
                                incompleteThinkingIndices.push(block.msgIndex);
                            }
                        }
                        // Sort descending so we remove from end first
                        incompleteThinkingIndices.sort((a, b) => b - a);
                        for (const idx of incompleteThinkingIndices) {
                            this.messages.splice(idx, 1);
                            // Adjust text/tool indices if they come after
                            if (state.textMsgIndex > idx) state.textMsgIndex--;
                            if (state.toolMsgIndex > idx) state.toolMsgIndex--;
                        }

                        // Remove empty text blocks (keep partial text - it's still useful)
                        if (state.textMsgIndex >= 0 && state.textMsgIndex < this.messages.length) {
                            const textMsg = this.messages[state.textMsgIndex];
                            if (!textMsg.content || textMsg.content.trim() === '') {
                                this.messages.splice(state.textMsgIndex, 1);
                            }
                        }

                        // Add interrupted marker
                        this.messages.push({
                            id: 'msg-' + Date.now() + '-interrupted',
                            role: 'interrupted',
                            content: 'Response interrupted',
                            timestamp: new Date().toISOString(),
                        });

                        // Scroll to show the interrupted marker
                        this.scrollToBottom();

                        // Disconnect from SSE - the done event from abort will handle cleanup
                        this.disconnectFromStream();
                        this.isStreaming = false;

                    } catch (err) {
                        console.error('Error aborting stream:', err);
                        this.isStreaming = false;
                    }
                },

                // Handle a single stream event
                handleStreamEvent(event) {
                    // Validate event belongs to current conversation (prevents cross-talk)
                    if (event.conversation_uuid && event.conversation_uuid !== this.currentConversationUuid) {
                        console.debug('Ignoring event for different conversation:', event.conversation_uuid);
                        return;
                    }

                    const state = this._streamState;

                    // Debug: log all events except usage
                    if (event.type !== 'usage') {
                        console.log('SSE Event:', event.type, event.content ? String(event.content).substring(0, 50) : '(no content)');
                    }

                    switch (event.type) {
                        case 'thinking_start':
                            // Support interleaved thinking - track by block_index
                            const thinkingBlockIndex = event.block_index ?? 0;
                            state.currentThinkingBlock = thinkingBlockIndex;
                            state.thinkingBlocks[thinkingBlockIndex] = {
                                msgIndex: this.messages.length,
                                content: '',
                                complete: false
                            };
                            this.messages.push({
                                id: 'msg-' + Date.now() + '-thinking-' + thinkingBlockIndex,
                                role: 'thinking',
                                content: '',
                                timestamp: state.startedAt || new Date().toISOString(),
                                collapsed: false
                            });
                            this.scrollToBottom();
                            break;

                        case 'thinking_delta':
                            {
                                const blockIdx = event.block_index ?? state.currentThinkingBlock;
                                const block = state.thinkingBlocks[blockIdx];
                                if (block && event.content) {
                                    block.content += event.content;
                                    this.messages[block.msgIndex] = {
                                        ...this.messages[block.msgIndex],
                                        content: block.content
                                    };
                                    this.scrollToBottom();
                                }
                            }
                            break;

                        case 'thinking_signature':
                            {
                                // Mark thinking as complete (has signature, safe to keep on abort)
                                const blockIdx = event.block_index ?? state.currentThinkingBlock;
                                if (state.thinkingBlocks[blockIdx]) {
                                    state.thinkingBlocks[blockIdx].complete = true;
                                }
                            }
                            break;

                        case 'thinking_stop':
                            {
                                const blockIdx = event.block_index ?? state.currentThinkingBlock;
                                const block = state.thinkingBlocks[blockIdx];
                                if (block) {
                                    this.messages[block.msgIndex] = {
                                        ...this.messages[block.msgIndex],
                                        collapsed: true
                                    };
                                }
                            }
                            break;

                        case 'text_start':
                            // Collapse all thinking blocks
                            for (const blockIdx in state.thinkingBlocks) {
                                const block = state.thinkingBlocks[blockIdx];
                                if (block && block.msgIndex >= 0) {
                                    this.messages[block.msgIndex] = {
                                        ...this.messages[block.msgIndex],
                                        collapsed: true
                                    };
                                }
                            }
                            state.textMsgIndex = this.messages.length;
                            state.textContent = '';
                            state.turnCost = 0;
                            state.turnInputTokens = 0;
                            state.turnOutputTokens = 0;
                            state.turnCacheCreationTokens = 0;
                            state.turnCacheReadTokens = 0;
                            this.messages.push({
                                id: 'msg-' + Date.now() + '-text',
                                role: 'assistant',
                                content: '',
                                timestamp: state.startedAt || new Date().toISOString(),
                                collapsed: false
                            });
                            this.scrollToBottom();
                            break;

                        case 'text_delta':
                            if (state.textMsgIndex >= 0 && event.content) {
                                state.textContent += event.content;
                                this.messages[state.textMsgIndex] = {
                                    ...this.messages[state.textMsgIndex],
                                    content: state.textContent
                                };
                                this.scrollToBottom();
                            }
                            break;

                        case 'text_stop':
                            // Text block complete
                            break;

                        case 'tool_use_start':
                            // Collapse all thinking blocks
                            for (const blockIdx in state.thinkingBlocks) {
                                const block = state.thinkingBlocks[blockIdx];
                                if (block && block.msgIndex >= 0) {
                                    this.messages[block.msgIndex] = {
                                        ...this.messages[block.msgIndex],
                                        collapsed: true
                                    };
                                }
                            }
                            state.toolInProgress = true;
                            state.toolMsgIndex = this.messages.length;
                            state.toolInput = '';
                            // Track this tool as pending execution
                            const toolId = event.metadata?.tool_id;
                            if (toolId) {
                                state.waitingForToolResults.add(toolId);
                            } else {
                                console.warn('tool_use_start event missing tool_id in metadata');
                            }
                            this.messages.push({
                                id: 'msg-' + Date.now() + '-tool',
                                role: 'tool',
                                toolName: event.metadata?.tool_name || 'Tool',
                                toolId: toolId,
                                toolInput: '',
                                toolResult: null,
                                content: '',
                                timestamp: state.startedAt || new Date().toISOString(),
                                collapsed: false
                            });
                            this.scrollToBottom();
                            break;

                        case 'tool_use_delta':
                            if (state.toolMsgIndex >= 0 && event.content) {
                                state.toolInput += event.content;
                                this.messages[state.toolMsgIndex] = {
                                    ...this.messages[state.toolMsgIndex],
                                    toolInput: state.toolInput,
                                    content: state.toolInput
                                };
                                this.scrollToBottom();
                            }
                            break;

                        case 'tool_use_stop':
                            state.toolInProgress = false;
                            if (state.toolMsgIndex >= 0) {
                                this.messages[state.toolMsgIndex] = {
                                    ...this.messages[state.toolMsgIndex],
                                    collapsed: true
                                };
                                // Reset for next tool
                                state.toolMsgIndex = -1;
                                state.toolInput = '';
                            }
                            // Note: We do NOT trigger abort here anymore.
                            // We wait for tool_result so the tool execution completes.
                            // The abort will be triggered in tool_result handler.
                            break;

                        case 'tool_result':
                            // Find the tool message with matching toolId and add the result
                            const toolResultId = event.metadata?.tool_id;
                            if (toolResultId) {
                                const toolMsgIndex = this.messages.findIndex(m => m.role === 'tool' && m.toolId === toolResultId);
                                if (toolMsgIndex >= 0) {
                                    this.messages[toolMsgIndex] = {
                                        ...this.messages[toolMsgIndex],
                                        toolResult: event.content
                                    };
                                }
                                // Remove from pending set - tool execution is complete
                                state.waitingForToolResults.delete(toolResultId);
                            } else {
                                console.warn('tool_result event missing tool_id in metadata');
                            }
                            // If abort was deferred and all tools have results, trigger it now
                            // Use skipSync=true since CLI already has the complete tool_use + tool_result
                            if (state.abortPending && state.waitingForToolResults.size === 0 && !state.toolInProgress) {
                                console.log('All pending tools complete - triggering deferred abort with skipSync');
                                state.abortPending = false;
                                state.abortSkipSync = true;
                                this.abortStream();
                            }
                            break;

                        case 'system_info':
                            // System info from CLI commands like /context, /usage
                            this.messages.push({
                                id: 'msg-' + Date.now() + '-' + Math.random(),
                                role: 'system',
                                content: event.content,
                                command: event.metadata?.command || null
                            });
                            this.scrollToBottom();
                            break;

                        case 'usage':
                            if (event.metadata) {
                                const input = event.metadata.input_tokens || 0;
                                const output = event.metadata.output_tokens || 0;
                                const cacheCreation = event.metadata.cache_creation_tokens || 0;
                                const cacheRead = event.metadata.cache_read_tokens || 0;
                                const cost = event.metadata.cost || 0;

                                this.inputTokens += input;
                                this.outputTokens += output;
                                this.cacheCreationTokens += cacheCreation;
                                this.cacheReadTokens += cacheRead;
                                this.totalTokens += input + output;

                                // Use server-calculated cost
                                this.sessionCost += cost;

                                // Update context window tracking
                                if (event.metadata.context_window_size) {
                                    this.contextWindowSize = event.metadata.context_window_size;
                                }
                                if (event.metadata.context_percentage !== undefined) {
                                    this.contextPercentage = event.metadata.context_percentage;
                                    this.lastContextTokens = input + output;
                                    this.updateContextWarningLevel();
                                }

                                // Store in turn state for message update on done
                                state.turnCost = cost;
                                state.turnInputTokens = input;
                                state.turnOutputTokens = output;
                                state.turnCacheCreationTokens = cacheCreation;
                                state.turnCacheReadTokens = cacheRead;
                            }
                            break;

                        case 'context_compacted':
                            // Legacy event - now replaced by compaction_summary
                            // Keep for backwards compatibility with older conversations
                            this.debugLog('Context compacted (legacy)', event.metadata);
                            break;

                        case 'compaction_summary':
                            // Claude Code auto-compacted the conversation context
                            // This event contains the full summary that Claude continues with
                            this.debugLog('Compaction summary received', {
                                contentLength: event.content?.length,
                                preTokens: event.metadata?.pre_tokens,
                                trigger: event.metadata?.trigger
                            });
                            const compactPreTokens = event.metadata?.pre_tokens;
                            const compactPreTokensDisplay = compactPreTokens != null ? compactPreTokens.toLocaleString() : 'unknown';
                            this.messages.push({
                                id: 'msg-compaction-' + Date.now(),
                                role: 'compaction',
                                content: event.content || '',
                                preTokens: compactPreTokens,
                                preTokensDisplay: compactPreTokensDisplay,
                                trigger: event.metadata?.trigger ?? 'auto',
                                timestamp: event.timestamp || Date.now(),
                                collapsed: true // Collapsed by default
                            });
                            this.scrollToBottom();
                            break;

                        case 'done':
                            if (state.textMsgIndex >= 0) {
                                this.messages[state.textMsgIndex] = {
                                    ...this.messages[state.textMsgIndex],
                                    cost: state.turnCost,
                                    inputTokens: state.turnInputTokens,
                                    outputTokens: state.turnOutputTokens,
                                    cacheCreationTokens: state.turnCacheCreationTokens,
                                    cacheReadTokens: state.turnCacheReadTokens,
                                    model: this.model,
                                    agent: this.currentAgent
                                };
                            }
                            break;

                        case 'error':
                            // Check for Claude Code auth required error
                            if (event.content && event.content.startsWith('CLAUDE_CODE_AUTH_REQUIRED:')) {
                                this.showClaudeCodeAuthModal = true;
                            } else {
                                this.showError(event.content || 'Unknown error');
                            }
                            break;

                        case 'debug':
                            console.log('Debug from server:', event.content, event.metadata);
                            break;

                        default:
                            console.log('Unknown event type:', event.type, event);
                    }
                },

                // Get current provider's reasoning config from loaded providers data
                get currentReasoningConfig() {
                    return this.providers[this.provider]?.reasoning_config || {};
                },

                // Get Anthropic thinking levels from config
                get anthropicThinkingLevels() {
                    return this.currentReasoningConfig.levels || [
                        { name: 'Off', budget_tokens: 0 },
                        { name: 'Light', budget_tokens: 4000 },
                        { name: 'Standard', budget_tokens: 10000 },
                        { name: 'Deep', budget_tokens: 20000 },
                        { name: 'Maximum', budget_tokens: 32000 },
                    ];
                },

                // Get OpenAI effort levels from config
                get openaiEffortLevels() {
                    return this.currentReasoningConfig.effort_levels || [
                        { value: 'none', name: 'Off' },
                        { value: 'low', name: 'Light' },
                        { value: 'medium', name: 'Standard' },
                        { value: 'high', name: 'Deep' },
                    ];
                },

                // Get OpenAI Compatible effort levels from config
                get openaiCompatibleEffortLevels() {
                    // Get from provider-specific config, fallback to standard effort levels
                    const providerConfig = this.providers?.openai_compatible?.reasoning_config;
                    return providerConfig?.effort_levels || [
                        { value: 'none', name: 'Off', description: 'No reasoning' },
                        { value: 'low', name: 'Light', description: 'Basic reasoning' },
                        { value: 'medium', name: 'Standard', description: 'Balanced reasoning' },
                        { value: 'high', name: 'Deep', description: 'Maximum reasoning' },
                    ];
                },

                // Get Claude Code thinking levels from config
                get claudeCodeThinkingLevels() {
                    return this.currentReasoningConfig.levels || [
                        { name: 'Off', thinking_tokens: 0 },
                        { name: 'Light', thinking_tokens: 4000 },
                        { name: 'Standard', thinking_tokens: 10000 },
                        { name: 'Deep', thinking_tokens: 20000 },
                        { name: 'Maximum', thinking_tokens: 32000 },
                    ];
                },

                // Get available tools for Claude Code from config
                // Filters to only enabled tools (tools now come as objects with enabled status)
                get claudeCodeAvailableTools() {
                    const tools = this.providers.claude_code?.available_tools || [];
                    // Filter to only enabled tools, return just names for the multi-select
                    return tools
                        .filter(t => typeof t === 'string' || t.enabled !== false)
                        .map(t => typeof t === 'string' ? t : t.name);
                },

                // Get display name for current reasoning setting
                get currentReasoningName() {
                    if (this.provider === 'anthropic') {
                        const level = this.anthropicThinkingLevels.find(l => l.budget_tokens === this.anthropicThinkingBudget);
                        return level?.name || 'Off';
                    } else if (this.provider === 'openai') {
                        const level = this.openaiEffortLevels.find(l => l.value === this.openaiReasoningEffort);
                        return level?.name || 'Off';
                    } else if (this.provider === 'openai_compatible') {
                        const level = this.openaiCompatibleEffortLevels.find(l => l.value === this.openaiCompatibleReasoningEffort);
                        return level?.name || 'Off';
                    } else if (this.provider === 'claude_code') {
                        const level = this.claudeCodeThinkingLevels.find(l => l.thinking_tokens === this.claudeCodeThinkingTokens);
                        return level?.name || 'Off';
                    }
                    return 'Off';
                },

                // Cycle through reasoning levels (for button tap)
                cycleReasoningLevel() {
                    if (this.provider === 'anthropic') {
                        const levels = this.anthropicThinkingLevels;
                        const currentIndex = levels.findIndex(l => l.budget_tokens === this.anthropicThinkingBudget);
                        const nextIndex = (currentIndex + 1) % levels.length;
                        this.anthropicThinkingBudget = levels[nextIndex].budget_tokens;
                    } else if (this.provider === 'openai') {
                        const levels = this.openaiEffortLevels;
                        const currentIndex = levels.findIndex(l => l.value === this.openaiReasoningEffort);
                        const nextIndex = (currentIndex + 1) % levels.length;
                        this.openaiReasoningEffort = levels[nextIndex].value;
                    } else if (this.provider === 'openai_compatible') {
                        const levels = this.openaiCompatibleEffortLevels;
                        const currentIndex = levels.findIndex(l => l.value === this.openaiCompatibleReasoningEffort);
                        const nextIndex = (currentIndex + 1) % levels.length;
                        this.openaiCompatibleReasoningEffort = levels[nextIndex].value;
                    } else if (this.provider === 'claude_code') {
                        const levels = this.claudeCodeThinkingLevels;
                        const currentIndex = levels.findIndex(l => l.thinking_tokens === this.claudeCodeThinkingTokens);
                        const nextIndex = (currentIndex + 1) % levels.length;
                        this.claudeCodeThinkingTokens = levels[nextIndex].thinking_tokens;
                    }
                    this.saveDefaultSettings();
                },

                showError(message) {
                    this.errorMessage = message;
                    this.showErrorModal = true;
                },

                scrollToBottom() {
                    if (!this.autoScrollEnabled) return;
                    this.$nextTick(() => {
                        // Use requestAnimationFrame to wait for browser layout/paint completion
                        // This fixes desktop where flexbox layout calculation may not be done after $nextTick
                        requestAnimationFrame(() => {
                            const container = document.getElementById('messages');
                            if (container) {
                                container.scrollTop = container.scrollHeight + 1; // +1 to handle sub-pixel rounding
                                this.isAtBottom = true;
                            }
                        });
                    });
                },

                scrollToTurn(turnNumber) {
                    this.$nextTick(() => {
                        // Small delay to ensure DOM is fully rendered
                        setTimeout(() => {
                            const selector = `[data-turn="${turnNumber}"]`;
                            const turnElement = document.querySelector(selector);

                            if (turnElement) {
                                const container = document.getElementById('messages');
                                if (container) {
                                    // Calculate scroll position to put element at top (with small offset)
                                    const containerRect = container.getBoundingClientRect();
                                    const elementRect = turnElement.getBoundingClientRect();
                                    const currentScrollTop = container.scrollTop;
                                    const topOffset = 16; // Small padding from top
                                    const newScrollTop = currentScrollTop + (elementRect.top - containerRect.top) - topOffset;
                                    container.scrollTop = Math.max(0, newScrollTop);
                                }
                                // Brief highlight effect
                                turnElement.classList.add('bg-blue-900/30');
                                setTimeout(() => turnElement.classList.remove('bg-blue-900/30'), 2000);
                                this.isAtBottom = false;
                            } else {
                                // Fallback to bottom if turn not found
                                this.debugLog('scrollToTurn: turn not found', { turnNumber });
                                this.autoScrollEnabled = true;
                                this.scrollToBottom();
                            }
                        }, 100);
                    });
                },

                handleMessagesScroll(event) {
                    // Ignore scroll events during conversation loading
                    if (this.ignoreScrollEvents) return;

                    const container = event.target;
                    // Check if user is at bottom (within 50px threshold)
                    const diff = container.scrollHeight - container.scrollTop - container.clientHeight;
                    const atBottom = diff < 50;

                    this.isAtBottom = atBottom;

                    // Only disable auto-scroll during streaming when user scrolls up
                    if (this.isStreaming && !atBottom) {
                        this.autoScrollEnabled = false;
                    }
                },

                debugLog(message, data = null) {
                    // Use global debug logger (defined in app.js)
                    if (typeof debugLog === 'function') {
                        debugLog(message, data);
                    } else {
                        console.log(`[DEBUG] ${message}`, data ?? '');
                    }
                },

                copyDebugWithState() {
                    // Get current state
                    const state = {
                        loadingConversation: this.loadingConversation,
                        isAtBottom: this.isAtBottom,
                        autoScrollEnabled: this.autoScrollEnabled,
                        ignoreScrollEvents: this.ignoreScrollEvents,
                        isStreaming: this.isStreaming,
                        messagesCount: this.messages.length
                    };

                    // Get scroll container info
                    const container = document.getElementById('messages');
                    const scrollInfo = container ? {
                        scrollTop: Math.round(container.scrollTop),
                        scrollHeight: container.scrollHeight,
                        clientHeight: container.clientHeight,
                        diff: Math.round(container.scrollHeight - container.scrollTop - container.clientHeight)
                    } : null;

                    // Build output
                    let text = `=== Current State ===\n`;
                    text += `loadingConversation: ${state.loadingConversation}\n`;
                    text += `isAtBottom: ${state.isAtBottom}\n`;
                    text += `autoScrollEnabled: ${state.autoScrollEnabled}\n`;
                    text += `ignoreScrollEvents: ${state.ignoreScrollEvents}\n`;
                    text += `isStreaming: ${state.isStreaming}\n`;
                    text += `messagesCount: ${state.messagesCount}\n`;
                    if (scrollInfo) {
                        text += `\n=== Scroll Info ===\n`;
                        text += `scrollTop: ${scrollInfo.scrollTop}\n`;
                        text += `scrollHeight: ${scrollInfo.scrollHeight}\n`;
                        text += `clientHeight: ${scrollInfo.clientHeight}\n`;
                        text += `diff (from bottom): ${scrollInfo.diff}\n`;
                    }
                    text += `\n=== Debug Logs ===\n`;

                    // Add logs from store
                    const logs = Alpine.store('debug').logs;
                    text += logs.map(log => {
                        const dataStr = log.data !== null ? ' ' + (typeof log.data === 'object' ? JSON.stringify(log.data) : log.data) : '';
                        return `[${log.timestamp}] ${log.message}${dataStr}`;
                    }).join('\n');

                    navigator.clipboard.writeText(text);
                },

                async copyStreamLogPath() {
                    if (!this.currentConversationUuid) {
                        this.debugLog('No conversation selected');
                        return;
                    }

                    try {
                        const response = await fetch(`/api/conversations/${this.currentConversationUuid}/stream-log-path`);

                        if (!response.ok) {
                            const errorText = await response.text();
                            console.error('Failed to get stream log path:', response.status, response.statusText, errorText);
                            this.debugLog('Failed to get log path', {
                                status: response.status,
                                statusText: response.statusText,
                                body: errorText.substring(0, 200)
                            });
                            return;
                        }

                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await response.text();
                            console.error('Unexpected response type:', contentType, text);
                            this.debugLog('Unexpected response type', { contentType, body: text.substring(0, 200) });
                            return;
                        }

                        const data = await response.json();

                        if (data.path) {
                            await navigator.clipboard.writeText(data.path);
                            this.debugLog('Stream log path copied', {
                                path: data.path,
                                exists: data.exists,
                                size: data.size
                            });
                        }
                    } catch (err) {
                        console.error('Failed to get stream log path:', err);
                        this.debugLog('Failed to copy log path', { error: err.message });
                    }
                },

                renderMarkdown(text) {
                    if (!text) return '';

                    // Parse markdown, linkify file paths, then sanitize
                    let html = marked.parse(text);
                    html = window.linkifyFilePaths(html);
                    return DOMPurify.sanitize(html);
                },

                formatTimestamp(ts) {
                    if (!ts) return '';
                    const d = new Date(ts);
                    return d.toLocaleDateString('en-GB').replace(/\//g, '-') + ' ' +
                           d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                },

                formatDate(ts) {
                    if (!ts) return '';
                    const d = new Date(ts);
                    return d.toLocaleDateString('en-GB').replace(/\//g, '-') + ' ' +
                           d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                },

                // Context window tracking helpers
                getContextBarColorClass() {
                    switch (this.contextWarningLevel) {
                        case 'danger': return 'bg-red-500';
                        case 'warning': return 'bg-yellow-500';
                        default: return 'bg-blue-500';
                    }
                },

                formatContextUsage() {
                    if (!this.contextWindowSize) return 'Context: --';
                    const used = (this.lastContextTokens / 1000).toFixed(0);
                    const total = (this.contextWindowSize / 1000).toFixed(0);
                    return `${used}k / ${total}k tokens`;
                },

                updateContextWarningLevel() {
                    if (this.contextPercentage >= 90) {
                        this.contextWarningLevel = 'danger';
                    } else if (this.contextPercentage >= 75) {
                        this.contextWarningLevel = 'warning';
                    } else {
                        this.contextWarningLevel = 'safe';
                    }
                },

                resetContextTracking() {
                    this.contextWindowSize = 0;
                    this.lastContextTokens = 0;
                    this.contextPercentage = 0;
                    this.contextWarningLevel = 'safe';
                },

                copyMessageContent(msg) {
                    let content = msg.content || '';
                    if (msg.role === 'tool' && msg.toolInput) {
                        // For tools, include tool name and input
                        const input = typeof msg.toolInput === 'string' ? msg.toolInput : JSON.stringify(msg.toolInput, null, 2);
                        content = `Tool: ${msg.toolName || 'Unknown'}\n\nInput:\n${input}`;
                        if (msg.toolResult) {
                            content += `\n\nResult:\n${msg.toolResult}`;
                        }
                    }
                    navigator.clipboard.writeText(content)
                        .then(() => {
                            this.copiedMessageId = msg.id;
                            setTimeout(() => {
                                if (this.copiedMessageId === msg.id) {
                                    this.copiedMessageId = null;
                                }
                            }, 1500);
                        })
                        .catch(err => {
                            console.error('Failed to copy:', err);
                        });
                },

                formatToolContent(msg) {
                    if (!msg.toolInput) return '';
                    const showFull = msg.showFullContent || false;
                    const inputLimit = showFull ? Infinity : 100;
                    const resultLimit = showFull ? Infinity : 500;

                    try {
                        const input = typeof msg.toolInput === 'string' ? JSON.parse(msg.toolInput) : msg.toolInput;
                        let html = '';

                        if (msg.toolName === 'Bash' || msg.toolName === 'bash') {
                            html += `<div><span class="text-blue-300 font-semibold">Command:</span> <code class="text-blue-100">${this.escapeHtml(input.command || '')}</code></div>`;
                            if (input.description) {
                                html += `<div><span class="text-blue-300 font-semibold">Description:</span> ${this.escapeHtml(input.description)}</div>`;
                            }
                        } else if (msg.toolName === 'Read' || msg.toolName === 'read') {
                            html += `<div><span class="text-blue-300 font-semibold">File:</span> ${this.escapeHtml(input.file_path || '')}</div>`;
                        } else if (msg.toolName === 'Edit' || msg.toolName === 'edit') {
                            html += `<div><span class="text-blue-300 font-semibold">File:</span> ${this.escapeHtml(input.file_path || '')}</div>`;
                            if (input.old_string) {
                                const preview = input.old_string.length > inputLimit ? input.old_string.substring(0, inputLimit) + '...' : input.old_string;
                                html += `<div><span class="text-blue-300 font-semibold">Find:</span> <pre class="mt-1 text-red-200 bg-red-950/30 px-2 py-1 rounded whitespace-pre-wrap text-xs">${this.escapeHtml(preview)}</pre></div>`;
                            }
                            if (input.new_string) {
                                const preview = input.new_string.length > inputLimit ? input.new_string.substring(0, inputLimit) + '...' : input.new_string;
                                html += `<div><span class="text-blue-300 font-semibold">Replace:</span> <pre class="mt-1 text-green-200 bg-green-950/30 px-2 py-1 rounded whitespace-pre-wrap text-xs">${this.escapeHtml(preview)}</pre></div>`;
                            }
                        } else {
                            // Generic: show all params
                            for (const [key, value] of Object.entries(input)) {
                                let displayValue;
                                if (typeof value === 'string') {
                                    displayValue = value.length > inputLimit ? value.substring(0, inputLimit) + '...' : value;
                                } else {
                                    const jsonStr = JSON.stringify(value);
                                    displayValue = jsonStr.length > inputLimit ? jsonStr.substring(0, inputLimit) + '...' : jsonStr;
                                }
                                html += `<div><span class="text-blue-300 font-semibold">${this.escapeHtml(key)}:</span> ${this.escapeHtml(displayValue)}</div>`;
                            }
                        }

                        // Add result section if available
                        if (msg.toolResult) {
                            const resultText = typeof msg.toolResult === 'string' ? msg.toolResult : JSON.stringify(msg.toolResult, null, 2);
                            const resultPreview = resultText.length > resultLimit ? resultText.substring(0, resultLimit) + '...' : resultText;
                            html += `<div class="mt-3 pt-3 border-t border-blue-500/20">
                                <div class="text-blue-300 font-semibold mb-1">Result:</div>
                                <pre class="text-green-200 bg-blue-950/50 px-2 py-1 rounded whitespace-pre-wrap text-xs">${this.escapeHtml(resultPreview)}</pre>
                            </div>`;
                        }

                        return html;
                    } catch (e) {
                        return `<pre class="text-xs">${this.escapeHtml(msg.content || '')}</pre>`;
                    }
                },

                isToolContentTruncated(msg) {
                    if (!msg.toolInput) return false;
                    try {
                        const input = typeof msg.toolInput === 'string' ? JSON.parse(msg.toolInput) : msg.toolInput;

                        // Check input fields
                        if (msg.toolName === 'Edit' || msg.toolName === 'edit') {
                            if (input.old_string && input.old_string.length > 100) return true;
                            if (input.new_string && input.new_string.length > 100) return true;
                        } else if (msg.toolName !== 'Bash' && msg.toolName !== 'bash' && msg.toolName !== 'Read' && msg.toolName !== 'read') {
                            for (const value of Object.values(input)) {
                                if (typeof value === 'string' && value.length > 100) return true;
                                if (typeof value !== 'string' && JSON.stringify(value).length > 100) return true;
                            }
                        }

                        // Check result
                        if (msg.toolResult) {
                            const resultText = typeof msg.toolResult === 'string' ? msg.toolResult : JSON.stringify(msg.toolResult, null, 2);
                            if (resultText.length > 500) return true;
                        }

                        return false;
                    } catch (e) {
                        return false;
                    }
                },

                escapeHtml(text) {
                    if (!text) return '';
                    return String(text).replace(/</g, '&lt;').replace(/>/g, '&gt;');
                },

                showMessageBreakdown(msg) {
                    this.breakdownMessage = msg;
                    this.showMessageDetails = true;
                },

                // ==================== Pricing Helpers ====================

                getPricing(modelId) {
                    // Look up pricing for a specific model, fallback to default
                    return this.modelPricing[modelId] || this.defaultPricing;
                },

                calculateCost(modelId, inputTokens, outputTokens, cacheCreation = 0, cacheRead = 0) {
                    const pricing = this.getPricing(modelId);
                    return (
                        (inputTokens * pricing.input) +
                        (outputTokens * pricing.output) +
                        (cacheCreation * pricing.cacheWrite) +
                        (cacheRead * pricing.cacheRead)
                    ) / 1000000;
                },

                openPricingForModel(modelId) {
                    this.pricingModel = modelId;
                    // Determine provider from model ID
                    if (modelId.startsWith('claude-') || modelId.startsWith('anthropic')) {
                        this.pricingProvider = 'anthropic';
                    } else if (modelId.startsWith('gpt-') || modelId.startsWith('o1')) {
                        this.pricingProvider = 'openai';
                    }
                    this.showPricingSettings = true;
                },

                get currentPricing() {
                    return this.getPricing(this.pricingModel);
                },

                get pricingModelsForProvider() {
                    // Return models that match the current pricing provider
                    return Object.entries(this.modelPricing)
                        .filter(([id, _]) => {
                            if (this.pricingProvider === 'anthropic') {
                                return id.startsWith('claude-');
                            } else if (this.pricingProvider === 'openai') {
                                return id.startsWith('gpt-') || id.startsWith('o1');
                            }
                            return true;
                        })
                        .reduce((acc, [id, data]) => {
                            acc[id] = data;
                            return acc;
                        }, {});
                },

                // ==================== Voice Recording ====================

                async checkOpenAiKey() {
                    try {
                        const response = await fetch('/api/claude/openai-key/check');
                        const data = await response.json();
                        this.openAiKeyConfigured = data.configured;
                    } catch (err) {
                        console.error('OpenAI key check failed:', err);
                    }
                },

                async checkAnthropicKey() {
                    try {
                        const response = await fetch('/api/claude/anthropic-key/check');
                        const data = await response.json();
                        this.anthropicKeyConfigured = data.configured;
                    } catch (err) {
                        console.error('Anthropic key check failed:', err);
                    }
                },

                async saveAnthropicKey() {
                    if (!this.anthropicKeyInput || this.anthropicKeyInput.length < 20) {
                        this.showError('Please enter a valid Anthropic API key');
                        return;
                    }

                    try {
                        const response = await fetch('/api/claude/anthropic-key', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ api_key: this.anthropicKeyInput })
                        });

                        if (response.ok) {
                            this.anthropicKeyConfigured = true;
                            this.showClaudeCodeAuthModal = false;
                            this.anthropicKeyInput = '';
                            // Refresh providers to update availability
                            await this.fetchProviders();
                        } else {
                            this.showError('Failed to save API key');
                        }
                    } catch (err) {
                        console.error('Error saving Anthropic key:', err);
                        this.showError('Error saving API key');
                    }
                },

                async toggleVoiceRecording() {
                    if (this.isRecording) {
                        this.stopVoiceRecording();
                    } else {
                        if (!this.openAiKeyConfigured) {
                            this.showOpenAiModal = true;
                            return;
                        }
                        await this.startVoiceRecording();
                    }
                },

                async startVoiceRecording() {
                    if (this.useFileUploadTranscription) {
                        await this.startFileUploadRecording();
                    } else {
                        await this.startRealtimeRecording();
                    }
                },

                // ==================== File Upload Recording ====================
                // Records full audio, then uploads for transcription (better for pauses)

                async startFileUploadRecording() {
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({
                            audio: {
                                echoCancellation: true,
                                noiseSuppression: true,
                                autoGainControl: true
                            }
                        });

                        // Find supported MIME type
                        const mimeTypes = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
                        const selectedMimeType = mimeTypes.find(t => MediaRecorder.isTypeSupported(t)) || '';

                        this.mediaRecorder = new MediaRecorder(stream,
                            selectedMimeType ? { mimeType: selectedMimeType } : {}
                        );
                        this.audioChunks = [];

                        this.mediaRecorder.ondataavailable = (e) => {
                            if (e.data.size > 0) this.audioChunks.push(e.data);
                        };

                        this.mediaRecorder.onstop = () => this.processFileUploadRecording();

                        this.mediaRecorder.start(1000); // 1-second chunks
                        this.isRecording = true;
                    } catch (err) {
                        console.error('Error starting recording:', err);
                        this.showError('Could not access microphone. Please check permissions.');
                    }
                },

                stopFileUploadRecording() {
                    if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                        this.mediaRecorder.requestData(); // Flush any pending audio before stopping
                        this.mediaRecorder.stop();
                        this.isRecording = false;
                        this.isProcessing = true; // Show processing state while uploading
                        this.mediaRecorder.stream.getTracks().forEach(track => track.stop());
                    }
                },

                async processFileUploadRecording() {
                    try {
                        const mimeType = this.mediaRecorder?.mimeType || 'audio/webm';
                        const audioBlob = new Blob(this.audioChunks, { type: mimeType });

                        if (audioBlob.size < 1000) {
                            this.showError('Recording too short. Please speak for at least 1 second.');
                            this.isProcessing = false;
                            return;
                        }

                        if (audioBlob.size > 25 * 1024 * 1024) {
                            this.showError('Recording too large (max 25MB). Please record a shorter message.');
                            this.isProcessing = false;
                            return;
                        }

                        // Determine file extension from MIME type
                        let ext = 'webm';
                        if (mimeType.includes('mp4')) ext = 'm4a';
                        else if (mimeType.includes('ogg')) ext = 'ogg';

                        const formData = new FormData();
                        formData.append('audio', audioBlob, `recording.${ext}`);

                        const response = await fetch('/api/claude/transcribe', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: formData
                        });

                        if (response.status === 428) {
                            this.openAiKeyConfigured = false;
                            this.showOpenAiModal = true;
                            this.isProcessing = false;
                            return;
                        }

                        const data = await response.json();

                        if (data.success && data.transcription) {
                            // Append to existing prompt (preserve what user typed)
                            const existing = this.prompt.trim();
                            this.prompt = existing ? existing + '\n\n' + data.transcription : data.transcription;

                            if (this.autoSendAfterTranscription) {
                                this.autoSendAfterTranscription = false;
                                setTimeout(() => this.sendMessage(), 100);
                            }
                        } else {
                            this.showError('Transcription failed: ' + (data.error || 'Unknown error'));
                        }
                    } catch (err) {
                        console.error('Error processing recording:', err);
                        this.showError('Error processing audio: ' + err.message);
                    } finally {
                        this.isProcessing = false;
                        this.audioChunks = [];
                        this.autoSendAfterTranscription = false;
                    }
                },

                // ==================== Realtime Streaming Recording ====================
                // Streams audio in real-time for live transcription (kept for future use)

                async startRealtimeRecording() {
                    try {
                        this.isProcessing = true;
                        // Preserve existing prompt text, add newlines if needed
                        this.realtimeTranscript = this.prompt.trim() ? this.prompt.trim() + '\n\n' : '';
                        this.currentTranscriptItemId = null;

                        // Get ephemeral token from backend
                        const sessionResponse = await fetch('/api/claude/transcribe/realtime-session', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            }
                        });

                        if (sessionResponse.status === 428) {
                            this.openAiKeyConfigured = false;
                            this.showOpenAiModal = true;
                            this.isProcessing = false;
                            return;
                        }

                        const sessionData = await sessionResponse.json();
                        if (!sessionData.success || !sessionData.client_secret) {
                            throw new Error(sessionData.error || 'Failed to create transcription session');
                        }

                        // Connect to OpenAI Realtime API (GA - no beta header)
                        // TODO: Make WebSocket URL configurable to match backend's baseUrl setting
                        const wsUrl = 'wss://api.openai.com/v1/realtime?intent=transcription';
                        this.realtimeWs = new WebSocket(wsUrl, [
                            'realtime',
                            `openai-insecure-api-key.${sessionData.client_secret}`,
                        ]);

                        this.realtimeWs.onopen = async () => {
                            // Session config already applied via backend client_secrets call
                            await this.startAudioCapture();
                            this.isRecording = true;
                            this.isProcessing = false;
                        };

                        this.realtimeWs.onmessage = (event) => {
                            let msg;
                            try {
                                msg = JSON.parse(event.data);
                            } catch (e) {
                                console.error('[RT] Failed to parse message:', e);
                                return;
                            }

                            if (msg.type === 'conversation.item.input_audio_transcription.delta') {
                                if (msg.delta) {
                                    // Sync with any manual edits the user made during recording
                                    this.realtimeTranscript = this.prompt;

                                    // Add space between turns
                                    if (this.currentTranscriptItemId && this.currentTranscriptItemId !== msg.item_id) {
                                        this.realtimeTranscript += ' ';
                                    }
                                    this.currentTranscriptItemId = msg.item_id;
                                    this.realtimeTranscript += msg.delta;
                                    this.prompt = this.realtimeTranscript;
                                    this.scrollPromptToEnd();
                                }
                            } else if (msg.type === 'conversation.item.input_audio_transcription.completed') {
                                // Don't close on completed - there might be more turns pending.
                                // The timeout handles closing to ensure all deltas are received.
                                // See: https://platform.openai.com/docs/guides/realtime-transcription
                                // "ordering between completion events from different speech turns is not guaranteed"
                            } else if (msg.type === 'error') {
                                console.error('[RT] API error:', msg.error);
                                // Ignore empty buffer error when stopping
                                if (msg.error?.code === 'input_audio_buffer_commit_empty' && this.waitingForFinalTranscript) {
                                    this.finishStopRecording();
                                } else {
                                    this.showError('Transcription error: ' + (msg.error?.message || 'Unknown error'));
                                }
                            }
                        };

                        this.realtimeWs.onerror = (error) => {
                            console.error('WebSocket error:', error);
                            this.cleanupRealtimeSession();
                            this.showError('Connection error. Please try again.');
                        };

                        this.realtimeWs.onclose = () => {
                            this.cleanupRealtimeSession();
                        };

                    } catch (err) {
                        console.error('Error starting voice recording:', err);
                        this.isProcessing = false;
                        this.showError(err.message || 'Could not start voice recording.');
                    }
                },

                async startAudioCapture() {
                    try {
                        this.realtimeStream = await navigator.mediaDevices.getUserMedia({
                            audio: {
                                channelCount: 1,
                                echoCancellation: true,
                                noiseSuppression: true,
                                autoGainControl: true,
                            }
                        });

                        this.realtimeAudioContext = new (window.AudioContext || window.webkitAudioContext)();

                        if (this.realtimeAudioContext.state === 'suspended') {
                            await this.realtimeAudioContext.resume();
                        }

                        const nativeSampleRate = this.realtimeAudioContext.sampleRate;
                        const targetSampleRate = 24000;

                        const source = this.realtimeAudioContext.createMediaStreamSource(this.realtimeStream);

                        // Using ScriptProcessorNode (deprecated) instead of AudioWorkletNode because:
                        // - AudioWorklet's postMessage causes audio data loss due to async messaging latency
                        // - Proper AudioWorklet requires SharedArrayBuffer + ring buffer + COOP/COEP headers
                        // - ScriptProcessorNode works reliably and deprecation != removal (still in all browsers)
                        // Future: implement AudioWorklet with SharedArrayBuffer ring buffer pattern
                        // See: https://developer.chrome.com/blog/audio-worklet-design-pattern
                        const processor = this.realtimeAudioContext.createScriptProcessor(2048, 1, 1);

                        processor.onaudioprocess = (e) => {
                            if (!this.realtimeWs || this.realtimeWs.readyState !== WebSocket.OPEN) return;

                            const inputData = e.inputBuffer.getChannelData(0);

                            let resampledData;
                            if (nativeSampleRate !== targetSampleRate) {
                                const ratio = nativeSampleRate / targetSampleRate;
                                const newLength = Math.floor(inputData.length / ratio);
                                resampledData = new Float32Array(newLength);
                                for (let i = 0; i < newLength; i++) {
                                    const srcPos = i * ratio;
                                    const srcIndex = Math.floor(srcPos);
                                    const frac = srcPos - srcIndex;
                                    const nextIndex = Math.min(srcIndex + 1, inputData.length - 1);
                                    resampledData[i] = inputData[srcIndex] * (1 - frac) + inputData[nextIndex] * frac;
                                }
                            } else {
                                resampledData = inputData;
                            }

                            const pcm16 = new Int16Array(resampledData.length);
                            for (let i = 0; i < resampledData.length; i++) {
                                const s = Math.max(-1, Math.min(1, resampledData[i]));
                                pcm16[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
                            }

                            const base64Audio = this.arrayBufferToBase64(pcm16.buffer);
                            this.realtimeWs.send(JSON.stringify({
                                type: 'input_audio_buffer.append',
                                audio: base64Audio
                            }));
                        };

                        source.connect(processor);
                        // Connect through silent GainNode to prevent audio feedback
                        // (ScriptProcessorNode must be connected to work, but we don't want speaker output)
                        const silentGain = this.realtimeAudioContext.createGain();
                        silentGain.gain.value = 0;
                        processor.connect(silentGain);
                        silentGain.connect(this.realtimeAudioContext.destination);
                        this.realtimeAudioWorklet = processor;
                    } catch (err) {
                        console.error('Error capturing audio:', err);
                        throw new Error('Could not access microphone. Please check permissions.');
                    }
                },

                arrayBufferToBase64(buffer) {
                    const bytes = new Uint8Array(buffer);
                    let binary = '';
                    for (let i = 0; i < bytes.byteLength; i++) {
                        binary += String.fromCharCode(bytes[i]);
                    }
                    return btoa(binary);
                },

                stopVoiceRecording() {
                    if (this.useFileUploadTranscription) {
                        this.stopFileUploadRecording();
                    } else {
                        this.stopRealtimeRecording();
                    }
                },

                stopRealtimeRecording() {
                    if (!this.isRecording) return;

                    this.isRecording = false;

                    if (this.realtimeWs && this.realtimeWs.readyState === WebSocket.OPEN) {
                        // Always commit to flush any pending audio (empty buffer errors are ignored)
                        this.realtimeWs.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));

                        // Always wait for transcription to complete (or timeout after 6s)
                        this.waitingForFinalTranscript = true;
                        this.stopTimeout = setTimeout(() => {
                            this.finishStopRecording();
                        }, 6000);
                    } else {
                        this.cleanupRealtimeSession();
                    }
                },

                finishStopRecording() {
                    if (this.stopTimeout) {
                        clearTimeout(this.stopTimeout);
                        this.stopTimeout = null;
                    }
                    this.waitingForFinalTranscript = false;

                    if (this.realtimeWs) {
                        this.realtimeWs.close();
                    }
                    this.cleanupRealtimeSession();

                    // Handle auto-send
                    if (this.autoSendAfterTranscription && this.prompt.trim()) {
                        this.autoSendAfterTranscription = false;
                        setTimeout(() => this.sendMessage(), 100);
                    }
                },

                cleanupRealtimeSession() {
                    // Stop audio processing
                    if (this.realtimeAudioWorklet) {
                        this.realtimeAudioWorklet.disconnect();
                        this.realtimeAudioWorklet = null;
                    }

                    // Close audio context
                    if (this.realtimeAudioContext) {
                        this.realtimeAudioContext.close();
                        this.realtimeAudioContext = null;
                    }

                    // Stop microphone stream
                    if (this.realtimeStream) {
                        this.realtimeStream.getTracks().forEach(track => track.stop());
                        this.realtimeStream = null;
                    }

                    // Close WebSocket
                    if (this.realtimeWs) {
                        if (this.realtimeWs.readyState === WebSocket.OPEN) {
                            this.realtimeWs.close();
                        }
                        this.realtimeWs = null;
                    }

                    this.isRecording = false;
                    this.isProcessing = false;
                },

                async saveOpenAiKey() {
                    if (!this.openAiKeyInput || this.openAiKeyInput.length < 20) {
                        this.showError('Please enter a valid OpenAI API key');
                        return;
                    }

                    try {
                        const response = await fetch('/api/claude/openai-key', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ api_key: this.openAiKeyInput })
                        });

                        if (response.ok) {
                            this.openAiKeyConfigured = true;
                            this.showOpenAiModal = false;
                            this.openAiKeyInput = '';
                            await this.startVoiceRecording();
                        } else {
                            this.showError('Failed to save API key');
                        }
                    } catch (err) {
                        console.error('Error saving API key:', err);
                        this.showError('Error saving API key');
                    }
                },

                handleSendClick(event) {
                    if (this.isRecording) {
                        event.preventDefault();
                        this.autoSendAfterTranscription = true;
                        this.stopVoiceRecording();
                        return false;
                    }
                    return true;
                },

                get voiceButtonText() {
                    if (this.isProcessing) return '<i class="fa-solid fa-spinner fa-spin"></i> Connecting...';
                    if (this.waitingForFinalTranscript) return '<i class="fa-solid fa-spinner fa-spin"></i> Finishing...';
                    if (this.isRecording) return '<i class="fa-solid fa-stop"></i> Stop';
                    return '<i class="fa-solid fa-microphone"></i> Record';
                },

                get voiceButtonClass() {
                    if (this.isProcessing || this.waitingForFinalTranscript) return 'bg-gray-600/80 text-gray-300 cursor-not-allowed';
                    if (this.isRecording) return 'bg-rose-500/90 text-white hover:bg-rose-400';
                    return 'bg-violet-500/90 text-white hover:bg-violet-400';
                },

                // Auto-scroll textarea to show latest transcription
                scrollPromptToEnd() {
                    this.$nextTick(() => {
                        // Scroll all prompt textareas (both desktop and mobile exist in DOM)
                        this.$root.querySelectorAll('textarea[x-model="prompt"]').forEach(textarea => {
                            textarea.scrollTop = textarea.scrollHeight;
                        });
                    });
                },

                // ==================== Copy Conversation ====================

                async copyConversationToClipboard() {
                    if (this.messages.length === 0) {
                        this.showError('No conversation to copy');
                        return;
                    }

                    this.copyingConversation = true;

                    try {
                        const text = this.formatConversationForExport();
                        await navigator.clipboard.writeText(text);

                        // Brief visual feedback
                        setTimeout(() => {
                            this.copyingConversation = false;
                        }, 2000);
                    } catch (err) {
                        console.error('Failed to copy conversation:', err);
                        this.showError('Failed to copy conversation to clipboard');
                        this.copyingConversation = false;
                    }
                },

                formatConversationForExport() {
                    const lines = [];

                    // Header with conversation metadata
                    lines.push('# Chat Conversation Export');
                    lines.push(`**Exported:** ${new Date().toISOString()}`);
                    if (this.currentAgent) {
                        lines.push(`**Agent:** ${this.currentAgent.name}`);
                        lines.push(`**Provider:** ${this.getProviderDisplayName(this.currentAgent.provider)}`);
                        lines.push(`**Model:** ${this.model}`);
                    }
                    lines.push(`**Total Cost:** $${this.sessionCost.toFixed(4)}`);
                    lines.push(`**Total Tokens:** ${this.totalTokens.toLocaleString()}`);
                    lines.push('');
                    lines.push('---');
                    lines.push('');

                    // Format each message
                    for (const msg of this.messages) {
                        lines.push(this.formatMessageForExport(msg));
                        lines.push('');
                    }

                    return lines.join('\n');
                },

                formatMessageForExport(msg) {
                    const lines = [];
                    const timestamp = this.formatTimestamp(msg.timestamp);

                    switch (msg.role) {
                        case 'user':
                            lines.push(`## User [${timestamp}]`);
                            lines.push('');
                            lines.push(msg.content);
                            break;

                        case 'assistant':
                            lines.push(`## Assistant [${timestamp}]`);
                            if (msg.model) {
                                lines.push(`*Model: ${this.getModelDisplayName(msg.model)}*`);
                            }
                            if (msg.cost) {
                                lines.push(`*Cost: $${msg.cost.toFixed(4)}*`);
                            }
                            lines.push('');
                            lines.push(msg.content);
                            break;

                        case 'thinking':
                            lines.push(`## Thinking [${timestamp}]`);
                            if (msg.cost) {
                                lines.push(`*Cost: $${msg.cost.toFixed(4)}*`);
                            }
                            lines.push('');
                            lines.push('```');
                            lines.push(msg.content);
                            lines.push('```');
                            break;

                        case 'tool':
                            lines.push(`## Tool: ${msg.toolName} [${timestamp}]`);
                            if (msg.model) {
                                lines.push(`*Model: ${this.getModelDisplayName(msg.model)}*`);
                            }
                            if (msg.cost) {
                                lines.push(`*Cost: $${msg.cost.toFixed(4)}*`);
                            }
                            lines.push('');
                            lines.push('**Input:**');
                            lines.push('```json');
                            lines.push(this.formatToolInputForExport(msg.toolInput));
                            lines.push('```');
                            if (msg.toolResult) {
                                lines.push('');
                                lines.push('**Result:**');
                                lines.push('```');
                                lines.push(typeof msg.toolResult === 'string'
                                    ? msg.toolResult
                                    : JSON.stringify(msg.toolResult, null, 2));
                                lines.push('```');
                            }
                            break;

                        case 'system':
                            lines.push(`## System: ${msg.command || 'Info'} [${timestamp}]`);
                            lines.push('');
                            lines.push(msg.content);
                            break;

                        case 'empty-response':
                            lines.push(`## Assistant (No Content) [${timestamp}]`);
                            if (msg.cost) {
                                lines.push(`*Cost: $${msg.cost.toFixed(4)}*`);
                            }
                            break;

                        default:
                            lines.push(`## ${msg.role} [${timestamp}]`);
                            lines.push('');
                            lines.push(msg.content || '');
                    }

                    return lines.join('\n');
                },

                formatToolInputForExport(toolInput) {
                    if (!toolInput) return '';
                    try {
                        const input = typeof toolInput === 'string'
                            ? JSON.parse(toolInput)
                            : toolInput;
                        return JSON.stringify(input, null, 2);
                    } catch (e) {
                        return String(toolInput);
                    }
                }
            };
        }
    </script>
</body>
</html>
