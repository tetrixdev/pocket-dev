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
                                    // Inject target="_blank" for links - preserve existing <base href> if present
                                    if (/<base\s[^>]*href=/i.test(sanitized)) {
                                        // Existing base tag with href - add target attribute to it
                                        sanitized = sanitized.replace(/<base(\s[^>]*)(href="[^"]*")([^>]*)>/i, '<base$1$2$3 target="_blank">');
                                    } else if (sanitized.includes('<head>')) {
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

                    // Close one preview (X button, Escape key)
                    // Uses history.back() to keep browser history in sync
                    close() {
                        if (this.stack.length === 0) return;
                        if (history.state?.filePreview) {
                            // Let popstate handler do the actual close
                            history.back();
                            return;
                        }
                        this._closeStack();
                    },

                    // Close triggered by popstate (back button) - don't manipulate history
                    closeFromHistory() {
                        if (this.stack.length === 0) return;
                        this._closeStack();
                    },

                    // Close all previews (backdrop click)
                    // Uses history.go() to clear all preview history entries
                    closeAll() {
                        if (this.stack.length === 0) return;
                        const depth = this.stack.length;
                        if (this.editing) {
                            this.cancelEditing();
                        }
                        this.stack = [];
                        this.copied = false;
                        if (window.debugLog) debugLog('filePreview.closeAll()');
                        if (history.state?.filePreview) {
                            // Go back through all preview history entries
                            history.go(-depth);
                        }
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
                maxFileSizeMb: {{ config('uploads.max_size_mb', 250) }},  // From server config

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

                    // Client-side file size validation
                    const maxSizeBytes = this.maxFileSizeMb * 1024 * 1024;
                    if (file.size > maxSizeBytes) {
                        const entry = {
                            id,
                            filename: file.name,
                            size: file.size,
                            sizeFormatted: this.formatSize(file.size),
                            path: null,
                            uploading: false,
                            error: `File too large. Maximum size is ${this.maxFileSizeMb}MB.`,
                        };
                        this.files.push(entry);
                        return;
                    }

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

                        // Check response.ok BEFORE parsing JSON to handle HTML error pages
                        if (!response.ok) {
                            // Try to extract error message from response
                            const contentType = response.headers.get('content-type') || '';
                            let errorMessage = 'Upload failed';

                            if (contentType.includes('application/json')) {
                                try {
                                    const errorData = await response.json();
                                    errorMessage = errorData.message || errorData.error || errorMessage;
                                } catch (e) {
                                    // JSON parsing failed, use status text
                                    errorMessage = response.statusText || errorMessage;
                                }
                            } else if (response.status === 422) {
                                // Laravel validation error - file too large
                                errorMessage = 'File too large. Maximum size is {{ config("uploads.max_size_mb", 250) }}MB.';
                            } else if (response.status === 413) {
                                // Nginx/PHP body too large
                                errorMessage = 'File too large for server to process.';
                            } else {
                                errorMessage = `Upload failed (${response.status})`;
                            }

                            throw new Error(errorMessage);
                        }

                        const data = await response.json();

                        if (!data.success) {
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

        .markdown-content { line-height: 1.6; overflow-wrap: break-word; word-wrap: break-word; }
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
        .markdown-content table { border-collapse: collapse; margin: 1em 0; width: max-content; min-width: 100%; }
        .markdown-content th, .markdown-content td { border: 1px solid #4b5563; padding: 0.5em; text-align: left; white-space: nowrap; }
        .markdown-content th { background: #374151; font-weight: bold; }

        /* Wrapper for horizontal scroll on tables - applied via JS */
        .table-wrapper { overflow-x: auto; margin: 1em 0; -webkit-overflow-scrolling: touch; }
        .table-wrapper table { margin: 0; }

        /* File path links - clickable paths that open file preview modal */
        .file-path-link {
            color: #60a5fa;
            text-decoration: none;
            background: rgba(59, 130, 246, 0.1);
            padding: 0.1em 0.4em;
            border-radius: 0.25em;
            transition: background-color 0.15s ease;
            word-break: break-all;
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

        /* Custom scrollbar for messages, conversations, and sessions - dark theme */
        #messages::-webkit-scrollbar,
        #conversations-list::-webkit-scrollbar,
        #sessions-list::-webkit-scrollbar,
        #mobile-sessions-list::-webkit-scrollbar {
            width: 6px;
        }
        #messages::-webkit-scrollbar-track,
        #conversations-list::-webkit-scrollbar-track,
        #sessions-list::-webkit-scrollbar-track,
        #mobile-sessions-list::-webkit-scrollbar-track {
            background: transparent;
        }
        #messages::-webkit-scrollbar-thumb,
        #conversations-list::-webkit-scrollbar-thumb,
        #sessions-list::-webkit-scrollbar-thumb,
        #mobile-sessions-list::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 3px;
        }
        #messages::-webkit-scrollbar-thumb:hover,
        #conversations-list::-webkit-scrollbar-thumb:hover,
        #sessions-list::-webkit-scrollbar-thumb:hover,
        #mobile-sessions-list::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }
        /* Firefox */
        #messages,
        #conversations-list,
        #sessions-list,
        #mobile-sessions-list {
            scrollbar-width: thin;
            scrollbar-color: #4b5563 transparent;
        }

        /* Custom horizontal scrollbar for screen tabs - dark theme */
        #screen-tabs::-webkit-scrollbar,
        #screen-tabs-mobile::-webkit-scrollbar {
            height: 6px;
        }
        #screen-tabs::-webkit-scrollbar-track,
        #screen-tabs-mobile::-webkit-scrollbar-track {
            background: transparent;
        }
        #screen-tabs::-webkit-scrollbar-thumb,
        #screen-tabs-mobile::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 3px;
        }
        #screen-tabs::-webkit-scrollbar-thumb:hover,
        #screen-tabs-mobile::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }
        /* Firefox */
        #screen-tabs,
        #screen-tabs-mobile {
            scrollbar-width: thin;
            scrollbar-color: #4b5563 transparent;
        }

        /* Mobile swipe navigation */
        .swipe-active {
            /* Prevent content selection during swipe */
            user-select: none;
            -webkit-user-select: none;
        }

        /* Add subtle shadow during swipe to indicate depth */
        @media (max-width: 767px) {
            #messages,
            [x-show="isActiveScreenPanel"] {
                will-change: transform;
            }
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

        {{-- Mobile Screen Tabs (hidden on desktop) --}}
        <div class="md:hidden">
            @include('partials.chat.screen-tabs-mobile')
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
                    {{-- Dropdown Menu - Teleported to body to escape stacking context --}}
                    <template x-teleport="body">
                        <div x-show="showConversationMenu"
                             x-cloak
                             @click.outside="showConversationMenu = false"
                             @keydown.escape="showConversationMenu = false"
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="transform opacity-100 scale-100"
                             x-transition:leave-end="transform opacity-0 scale-95"
                             class="hidden md:block fixed w-48 bg-gray-700 rounded-lg shadow-lg border border-gray-600 z-[100] overflow-hidden"
                             style="top: 50px; right: 8px;">
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
                            {{-- Session Section Header --}}
                            <div class="px-4 py-1.5 text-xs text-gray-500 uppercase tracking-wide border-t border-gray-600">Session</div>
                            {{-- Rename Session --}}
                            <button @click="openRenameSessionModal(); showConversationMenu = false"
                                    :disabled="!currentSession"
                                    :class="!currentSession ? 'text-gray-500 cursor-not-allowed' : 'text-gray-200 hover:bg-gray-600 cursor-pointer'"
                                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                                <i class="fa-solid fa-pen w-4 text-center"></i>
                                Rename session
                            </button>
                            {{-- Archive/Restore Session --}}
                            <button @click="currentSession?.is_archived ? restoreSession(currentSession.id) : archiveSession(currentSession.id); showConversationMenu = false"
                                    :disabled="!currentSession"
                                    :class="!currentSession ? 'text-gray-500 cursor-not-allowed' : 'text-gray-200 hover:bg-gray-600 cursor-pointer'"
                                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                                <i class="fa-solid fa-box-archive w-4 text-center"></i>
                                <span x-text="currentSession?.is_archived ? 'Restore session' : 'Archive session'"></span>
                            </button>
                            {{-- Delete Session --}}
                            <button @click="deleteSession(currentSession?.id); showConversationMenu = false"
                                    :disabled="!currentSession"
                                    :class="!currentSession ? 'text-gray-500 cursor-not-allowed' : 'text-red-400 hover:bg-gray-600 cursor-pointer'"
                                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                                <i class="fa-solid fa-trash w-4 text-center"></i>
                                Delete session
                            </button>
                            {{-- Restore Chat (only show if session has archived conversations) --}}
                            <button x-show="hasArchivedConversations"
                                    @click="openRestoreChatModal(); showConversationMenu = false"
                                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-200 hover:bg-gray-600 cursor-pointer w-full text-left">
                                <i class="fa-solid fa-rotate-left w-4 text-center"></i>
                                Restore chat...
                            </button>
                            {{-- Conversation Section Header --}}
                            <div class="px-4 py-1.5 text-xs text-gray-500 uppercase tracking-wide border-t border-gray-600">Conversation</div>
                            {{-- Archive/Unarchive Conversation --}}
                            <button @click="toggleArchiveConversation(); showConversationMenu = false"
                                    :disabled="!currentConversationUuid"
                                    :class="!currentConversationUuid ? 'text-gray-500 cursor-not-allowed' : 'text-gray-200 hover:bg-gray-600 cursor-pointer'"
                                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                                <i class="fa-solid fa-box-archive w-4 text-center"></i>
                                <span x-text="currentConversationStatus === 'archived' ? 'Unarchive chat' : 'Archive chat'"></span>
                            </button>
                            {{-- Delete Conversation --}}
                            <button @click="deleteConversation(); showConversationMenu = false"
                                    :disabled="!currentConversationUuid"
                                    :class="!currentConversationUuid ? 'text-gray-500 cursor-not-allowed' : 'text-red-400 hover:bg-gray-600 cursor-pointer'"
                                    class="flex items-center gap-2 px-4 py-2 text-sm w-full text-left">
                                <i class="fa-solid fa-trash w-4 text-center"></i>
                                Delete chat
                            </button>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Screen Tabs (Desktop only) - fixed below header --}}
            <div class="hidden md:block md:fixed md:top-[57px] md:left-64 md:right-0 md:z-10">
                @include('partials.chat.screen-tabs')
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
                {{-- Top offset: header (57px) + tabs (38px when visible) --}}
                <div x-cloak
                     class="fixed left-64 right-0 bg-blue-500/20 items-center justify-center z-10 pointer-events-none rounded-lg hidden"
                     :class="isDragging ? 'md:flex' : 'md:hidden'"
                     :style="{ top: currentSession ? '95px' : '57px', bottom: desktopInputHeight + 'px' }">
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
                         class="fixed left-0 right-0 z-20 bg-gray-900/90 flex items-center justify-center backdrop-blur-sm"
                         :style="{ top: (currentSession ? '105px' : '57px'), bottom: mobileInputHeight + 'px' }">
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
                {{-- Top offset: header (57px) + tabs (38px when visible) --}}
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
                         :style="{ top: currentSession ? '95px' : '57px', bottom: desktopInputHeight + 'px' }">
                        <div class="flex flex-col items-center gap-3">
                            <svg class="w-8 h-8 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-gray-400 text-sm">Loading conversation...</span>
                        </div>
                    </div>
                </div>

                {{-- Messages top offset: header (57px) + tabs (38px when visible on desktop) --}}
                {{-- Only show messages container when not viewing a panel --}}
                <div x-show="!isActiveScreenPanel"
                     id="messages"
                     class="p-4 space-y-4 overflow-y-auto bg-gray-900 fixed left-0 right-0 z-0
                            md:left-64 md:pt-4 md:pb-4"
                     :class="[isDragging ? 'ring-2 ring-blue-500 ring-inset' : '', isSwiping ? 'swipe-active' : '']"
                     :style="{
                         top: (currentSession ? (windowWidth >= 768 ? '95px' : '105px') : '57px'),
                         bottom: (windowWidth >= 768 ? desktopInputHeight : mobileInputHeight) + 'px',
                         transform: isSwiping ? 'translateX(' + swipeDeltaX + 'px)' : 'translateX(0)',
                         transition: isSwiping ? 'none' : 'transform 0.3s ease-out'
                     }"
                     @scroll="handleMessagesScroll($event)"
                     @touchstart="handleSwipeStart($event)"
                     @touchmove="handleSwipeMove($event)"
                     @touchend="handleSwipeEnd($event)"
                     @touchcancel="resetSwipeState()">

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

            {{-- Panel Content Container --}}
            {{-- Shows rendered panel content when viewing a panel screen --}}
            <div x-show="isActiveScreenPanel"
                 x-cloak
                 class="fixed left-0 right-0 z-0 bg-gray-900 overflow-auto
                        md:left-64"
                 :class="isSwiping ? 'swipe-active' : ''"
                 :style="{
                     top: (currentSession ? (windowWidth >= 768 ? '95px' : '105px') : '57px'),
                     bottom: '0px',
                     transform: isSwiping ? 'translateX(' + swipeDeltaX + 'px)' : 'translateX(0)',
                     transition: isSwiping ? 'none' : 'transform 0.3s ease-out'
                 }"
                 @touchstart="handleSwipeStart($event)"
                 @touchmove="handleSwipeMove($event)"
                 @touchend="handleSwipeEnd($event)"
                 @touchcancel="resetSwipeState()">

                {{-- Loading State - use x-show to avoid destroying panel Alpine state --}}
                <div x-show="loadingPanel" class="absolute inset-0 flex items-center justify-center bg-gray-900/80 z-10">
                    <div class="flex flex-col items-center gap-3">
                        <svg class="w-8 h-8 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-gray-400 text-sm">Loading panel...</span>
                    </div>
                </div>

                {{--
                    Panel Content (rendered HTML) - Double-buffered iframes

                    Uses two iframes (A and B) to eliminate flash when switching panels:
                    - One iframe is visible (showing current panel)
                    - The other is hidden (used for preloading next panel)
                    - On panel switch, content loads into hidden iframe, then we swap visibility

                    IMPORTANT for panel developers:
                    - x-init and init() will re-run whenever panel content is refreshed
                    - Store persistent state in panelState (synced to server) rather than local Alpine state
                    - Side effects in init() should be idempotent or guarded
                --}}
                <iframe id="panel-content-container-a"
                        x-ref="iframeA"
                        x-show="activeIframeBuffer === 'A'"
                        class="w-full h-full border-0 bg-transparent"
                        sandbox="allow-scripts allow-same-origin allow-forms"
                        referrerpolicy="no-referrer"
                        title="Panel content"></iframe>
                <iframe id="panel-content-container-b"
                        x-ref="iframeB"
                        x-show="activeIframeBuffer === 'B'"
                        class="w-full h-full border-0 bg-transparent"
                        sandbox="allow-scripts allow-same-origin allow-forms"
                        referrerpolicy="no-referrer"
                        title="Panel content"></iframe>
            </div>

            {{-- Scroll to Bottom Button (mobile) - positioned above attachment FAB --}}
            <button x-show="!isActiveScreenPanel"
                    @click="autoScrollEnabled = true; scrollToBottom()"
                    :class="(!isAtBottom && messages.length > 0) ? 'opacity-100 scale-100 pointer-events-auto' : 'opacity-0 scale-75 pointer-events-none'"
                    class="md:hidden fixed z-50 w-10 h-10 bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white rounded-full shadow-lg flex items-center justify-center transition-all duration-200 right-4"
                    :style="{ bottom: (mobileInputHeight + 64) + 'px' }"
                    title="Scroll to bottom">
                <i class="fas fa-arrow-down"></i>
            </button>

            {{-- File Attachment FAB (mobile) - hidden when viewing panels --}}
            <template x-if="!isActiveScreenPanel">
                @include('partials.chat.attachment-fab')
            </template>

            {{-- Scroll to Bottom Button (desktop) --}}
            <button x-cloak
                    x-show="!isAtBottom && messages.length > 0 && !isActiveScreenPanel"
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

            {{-- Desktop Input (hidden on mobile and when viewing panels) - fixed at bottom to match fixed #messages --}}
            <div x-show="!isActiveScreenPanel" x-ref="desktopInput" class="hidden md:block md:fixed md:bottom-0 md:left-64 md:right-0 md:z-10">
                @include('partials.chat.input-desktop')
            </div>
        </div>
    </div>

    {{-- Mobile Input (hidden on desktop and when viewing panels) --}}
    <div x-show="!isActiveScreenPanel" class="md:hidden">
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
                cachedLatestActivity: null, // For sidebar polling
                sidebarPollInterval: null, // Polling interval ID
                currentConversationUuid: null,
                currentConversationStatus: null, // Current conversation status (idle, processing, archived, failed)
                showConversationMenu: false, // Dropdown menu visibility
                conversationProvider: null, // Provider of current conversation (for mid-convo agent switch)
                isStreaming: false,
                _justCompletedStream: false,
                _wasStreamingBeforeHidden: false, // True if we were streaming when tab went to background
                _isReplaying: false, // True during page refresh stream replay (prevents duplicate screen refreshes)
                autoScrollEnabled: true, // Auto-scroll during streaming; disabled when user scrolls up manually
                isAtBottom: true, // Track if user is at bottom of messages
                ignoreScrollEvents: false, // Ignore scroll events during conversation loading
                loadingConversation: false, // Show loading overlay during conversation loading
                _loadingConversationUuid: null, // UUID of conversation being loaded (prevents duplicate loads)
                _initDone: false, // Guard against double initialization
                sessionCost: 0,
                totalTokens: 0,

                // Sessions & Screens
                sessions: [], // All sessions for current workspace
                sessionsPage: 1, // Current page for pagination
                sessionsLastPage: 1, // Last page number
                loadingMoreSessions: false, // Loading state for infinite scroll
                currentSession: null, // Current session object with screens
                screens: [], // Flat array of screen objects in the current session
                activeScreenId: null, // Currently active screen ID
                availablePanels: [], // Available panel tools
                _panelsFetchPending: false, // Guard against duplicate panel fetch retries
                showArchivedSessions: false, // Filter toggle
                sessionSearchQuery: '', // Filter by name
                sidebarSearchMode: 'sessions', // 'sessions' or 'conversations'
                sessionMenuId: null, // Which session's context menu is open
                sessionMenuPos: { top: 0, left: 0 }, // Position for session context menu
                workspaceHasDefaultTemplate: false, // Whether workspace has a default session template

                // Restore chat modal
                showRestoreChatModal: false, // Visibility of restore chat modal
                archivedConversations: [], // Archived conversations for current session
                loadingArchivedConversations: false, // Loading state

                // Panel content
                panelContent: '', // HTML content of active panel
                loadingPanel: false, // Loading state for panel content
                activeIframeBuffer: 'A', // Which iframe is currently visible ('A' or 'B')

                // Screen tab drag-and-drop state
                draggedScreenId: null, // ID of screen being dragged
                dragOverScreenId: null, // ID of screen being dragged over
                dragDropPosition: null, // 'before' or 'after' drop position

                // Toast notification
                toastMessage: '',
                toastVisible: false,

                // Mobile swipe navigation
                swipeStartX: 0,
                swipeStartY: 0,
                swipeCurrentX: 0,
                swipeDeltaX: 0,
                isSwiping: false,
                swipeThreshold: 80, // Minimum distance to trigger screen change
                swipeEdgeResistance: 0.3, // How much movement at edges (30%)

                // Computed: filtered sessions based on search query
                get filteredSessions() {
                    // Return all sessions if no search query
                    if (!this.sessionSearchQuery) return this.sessions;
                    const query = this.sessionSearchQuery.toLowerCase();
                    return this.sessions.filter(s =>
                        (s.name || '').toLowerCase().includes(query)
                    );
                },

                // Computed: visible screen order (excludes screens with archived conversations)
                get visibleScreenOrder() {
                    if (!this.currentSession?.screen_order) return [];
                    return this.currentSession.screen_order.filter(screenId => {
                        const screen = this._screenMap?.[screenId] || this.screens.find(s => s.id === screenId);
                        // Show all panel screens, only show chat screens if conversation is not archived
                        if (!screen) return false;
                        if (screen.type === 'panel') return true;
                        return screen.conversation?.status !== 'archived';
                    });
                },

                // Computed: get the active screen object
                get activeScreen() {
                    if (!this.activeScreenId) return null;
                    return this._screenMap?.[this.activeScreenId] || this.screens.find(s => s.id === this.activeScreenId);
                },

                // Computed: is the active screen a panel?
                get isActiveScreenPanel() {
                    return this.activeScreen?.type === 'panel';
                },

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
                showRenameSessionModal: false,
                showSystemPromptPreview: false,

                // Conversation title (rename)
                currentConversationTitle: null,
                renameTitle: '',
                renameTabLabel: '',
                renameSaving: false,

                // Session name (rename)
                renameSessionName: '',
                renameSessionTargetId: null,
                renameSessionSaving: false,
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
                conversationSearchRequestId: 0, // For ignoring stale search responses
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
                lastEventId: null,  // Unique event ID for verification
                streamAbortController: null,
                _streamConnectNonce: 0,
                _streamRetryTimeoutId: null,
                // Connection health tracking (dead man's switch)
                _lastKeepaliveAt: null,      // Timestamp of last keepalive received
                _connectionHealthy: true,     // False if no keepalive for >45s
                _keepaliveCheckInterval: null, // Interval ID for health check timer
                // Stream phase tracking (for "processing context" indicator)
                _streamPhase: 'idle',          // 'idle' | 'waiting' | 'tool_executing' | 'streaming'
                _phaseChangedAt: null,         // Timestamp when phase last changed
                _pendingResponseWarning: false, // True when waiting >15s without streaming
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

                    // Fetch available panels for the "Add Panel" menu
                    await this.fetchAvailablePanels();

                    // Restore filter states from sessionStorage
                    const savedArchiveFilter = sessionStorage.getItem('pocketdev_showArchivedSessions');
                    if (savedArchiveFilter === 'true') {
                        this.showArchivedSessions = true;
                        this.showSearchInput = true;
                    }
                    const savedSearchMode = sessionStorage.getItem('pocketdev_sidebarSearchMode');
                    if (savedSearchMode === 'conversations') {
                        this.sidebarSearchMode = 'conversations';
                        this.showSearchInput = true;
                    }
                    const savedArchivedConversations = sessionStorage.getItem('pocketdev_showArchivedConversations');
                    if (savedArchivedConversations === 'true') {
                        this.showArchivedConversations = true;
                    }

                    // Load sessions list
                    await this.fetchSessions();

                    // Watch for filter changes to persist to sessionStorage
                    this.$watch('showArchivedSessions', (value) => {
                        if (value) {
                            sessionStorage.setItem('pocketdev_showArchivedSessions', 'true');
                        } else {
                            sessionStorage.removeItem('pocketdev_showArchivedSessions');
                        }
                    });
                    this.$watch('sidebarSearchMode', (value) => {
                        if (value === 'conversations') {
                            sessionStorage.setItem('pocketdev_sidebarSearchMode', 'conversations');
                        } else {
                            sessionStorage.removeItem('pocketdev_sidebarSearchMode');
                        }
                    });
                    this.$watch('showArchivedConversations', (value) => {
                        if (value) {
                            sessionStorage.setItem('pocketdev_showArchivedConversations', 'true');
                        } else {
                            sessionStorage.removeItem('pocketdev_showArchivedConversations');
                        }
                    });

                    // Sync active conversation status to screen data and sidebar session data
                    this.$watch('currentConversationStatus', (newStatus) => {
                        if (!newStatus) return;

                        // (a) Sync to active screen's conversation (for tab icon)
                        const activeScreen = this.getScreen(this.activeScreenId);
                        if (
                            !activeScreen ||
                            activeScreen.type !== 'chat' ||
                            activeScreen.conversation?.uuid !== this.currentConversationUuid
                        ) {
                            return;
                        }
                        activeScreen.conversation.status = newStatus;

                        // (b) Sync to session in this.sessions (for sidebar icon)
                        if (this.currentSession?.id) {
                            const sessionIdx = this.sessions.findIndex(s => s.id === this.currentSession.id);
                            if (sessionIdx !== -1) {
                                const sidebarSession = this.sessions[sessionIdx];
                                const screenInSidebar = sidebarSession.screens?.find(
                                    sc => sc.conversation?.id === activeScreen?.conversation_id
                                );
                                if (screenInSidebar?.conversation) {
                                    screenInSidebar.conversation.status = newStatus;
                                    screenInSidebar.conversation.last_activity_at = new Date().toISOString();
                                }
                            }
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

                    // Check URL for session ID and load if present
                    const urlSessionId = this.getSessionIdFromUrl();
                    if (urlSessionId) {
                        await this.loadSession(urlSessionId);

                        // Scroll to bottom if returning from settings
                        if (returningFromSettings) {
                            this.$nextTick(() => this.scrollToBottom());
                        }
                    }

                    // Handle browser back/forward navigation
                    window.addEventListener('popstate', (event) => {
                        // Ignore filePreview history states (handled by filePreview store)
                        if (event.state?.filePreview) {
                            return;
                        }

                        if (event.state && event.state.sessionId) {
                            // Don't reload if we're already on this session
                            if (this.currentSession?.id === event.state.sessionId) {
                                return;
                            }
                            this.loadSession(event.state.sessionId);
                        } else {
                            // Back to home state - clear session
                            this.currentSession = null;
                            this.screens = [];
                            this.activeScreenId = null;
                            this.messages = [];
                            this.currentConversationUuid = null;
                        }
                    });

                    // Track window resize for responsive layout calculations
                    window.addEventListener('resize', () => {
                        this.windowWidth = window.innerWidth;
                    });

                    // Handle visibility changes (phone standby, tab switching)
                    // Refresh session screens when page becomes visible again
                    document.addEventListener('visibilitychange', async () => {
                        if (document.hidden) {
                            // Track whether we were streaming when tab went to background
                            this._wasStreamingBeforeHidden = this.isStreaming;
                        } else {
                            // Reconnect stream FIRST if actively streaming.
                            // IMPORTANT: Do this BEFORE refreshSessionScreens() to prevent
                            // the screen refresh from killing a just-established stream connection
                            // (Issue #163: refreshSessionScreens can change activeScreenId which
                            // triggers disconnectFromStream via watchers).
                            if (this.currentConversationUuid && (this.isStreaming || this._wasStreamingBeforeHidden)) {
                                await this.checkAndReconnectStream(this.currentConversationUuid);
                            }

                            // Clear the flag after reconnection attempt
                            this._wasStreamingBeforeHidden = false;

                            // Only refresh screens if NOT actively streaming.
                            // During streaming, screen changes could race with the stream connection.
                            if (this.currentSession?.id && !this.isStreaming) {
                                setTimeout(() => {
                                    this.refreshSessionScreens();
                                }, 500);
                            }
                        }
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

                // Extract session ID from URL path (strict UUID validation)
                getSessionIdFromUrl() {
                    const path = window.location.pathname.replace(/\/+$/, ''); // tolerate trailing slash
                    const match = path.match(/^\/session\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i);
                    return match ? match[1] : null;
                },

                // Update URL to reflect current session
                // Use replace: true to avoid back-button loops when clearing invalid URLs
                updateSessionUrl(sessionId = null, { replace = false } = {}) {
                    const newPath = sessionId ? `/session/${sessionId}` : '/';
                    const state = sessionId ? { sessionId } : {};

                    // Only update if path actually changed
                    if (window.location.pathname !== newPath) {
                        if (replace) {
                            window.history.replaceState(state, '', newPath);
                        } else {
                            window.history.pushState(state, '', newPath);
                        }
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

                getModelDisplayLabel(providerKey, modelId) {
                    const modelEntry = this.providers?.[providerKey]?.models?.[modelId];
                    const displayName = typeof modelEntry === 'string' ? modelEntry : modelEntry?.name;
                    if (displayName && displayName !== modelId) {
                        return displayName + ' [' + modelId + ']';
                    }
                    return modelId;
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

                getConversationStatus(screenId) {
                    const screen = this.getScreen(screenId);
                    if (!screen || screen.type !== 'chat') return 'idle';
                    // For active screen, prefer currentConversationStatus (most up-to-date via SSE)
                    if (
                        screenId === this.activeScreenId &&
                        this.currentConversationStatus &&
                        screen.conversation?.uuid === this.currentConversationUuid
                    ) {
                        return this.currentConversationStatus;
                    }
                    return screen.conversation?.status || 'idle';
                },

                getActiveTabCount(session) {
                    if (!session.screens) return 0;
                    return session.screens.filter(s => {
                        if (!s) return false;
                        if (s.type === 'panel') return true;
                        return s.conversation?.status !== 'archived';
                    }).length;
                },

                getSessionStatus(session) {
                    if (session.is_archived) return 'archived';

                    const chatConversations = (session.screens || [])
                        .filter(s => s.type === 'chat' && s.conversation && s.conversation.status !== 'archived')
                        .map(s => s.conversation);

                    if (chatConversations.length === 0) return 'idle';

                    // Any conversation processing → processing takes priority
                    if (chatConversations.some(c => c.status === 'processing')) return 'processing';

                    // Otherwise: status of the latest non-archived conversation by last_activity_at
                    const sorted = [...chatConversations].sort((a, b) => {
                        const aTime = a.last_activity_at ? new Date(a.last_activity_at).getTime() : 0;
                        const bTime = b.last_activity_at ? new Date(b.last_activity_at).getTime() : 0;
                        return bTime - aTime;
                    });

                    return sorted[0].status || 'idle';
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
                                // Check if workspace has a default session template
                                this.workspaceHasDefaultTemplate = !!(data.workspace.default_session_template?.screen_order?.length);
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
                            // Update default template state for new workspace
                            this.workspaceHasDefaultTemplate = !!(workspace.default_session_template?.screen_order?.length);
                            this.showWorkspaceSelector = false;
                            this.debugLog('Workspace switched', { workspace: workspace.name, lastSession: data.last_session_id });

                            // Clear old workspace state before loading new data
                            this.agents = [];
                            this.currentAgentId = null;
                            this.currentConversationUuid = null;
                            this.currentSession = null;
                            this.screens = [];
                            this.activeScreenId = null;

                            // Reload sessions and agents for the new workspace
                            await this.fetchSessions();
                            await this.fetchAgents();

                            // Restore last session for this workspace, or start new
                            if (data.last_session_id) {
                                // Check if session still exists in the loaded list
                                const lastSession = this.sessions.find(s => s.id === data.last_session_id);
                                if (lastSession) {
                                    await this.loadSession(data.last_session_id);
                                } else {
                                    await this.newSession();
                                }
                            } else {
                                await this.newSession();
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
                    // Skip if no workspace is active or search filter is active
                    if (!this.currentWorkspaceId || this.sessionSearchQuery) {
                        return;
                    }

                    try {
                        const response = await fetch(`/api/sessions/latest-activity?workspace_id=${this.currentWorkspaceId}`);
                        const data = await response.json();

                        // If there's new activity, refresh the sessions list
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
                    // Skip if no workspace ID
                    if (!this.currentWorkspaceId) {
                        return;
                    }

                    try {
                        const params = new URLSearchParams({
                            workspace_id: this.currentWorkspaceId,
                            include_archived: this.showArchivedSessions ? '1' : '0',
                            per_page: Math.min(200, Math.max(20, this.sessionsPage * 20)).toString(),
                        });
                        const response = await fetch(`/api/sessions?${params}`);
                        if (!response.ok) {
                            console.error('Failed to refresh sidebar:', response.status);
                            return;
                        }
                        const data = await response.json();
                        const newSessions = data.data || [];

                        // Update cached latest activity
                        if (newSessions.length > 0) {
                            this.cachedLatestActivity = newSessions[0].updated_at;
                        }

                        // Merge new sessions with any additional pages already loaded
                        const newIds = new Set(newSessions.map(s => s.id));
                        const extraSessions = this.sessions.filter(s => !newIds.has(s.id));
                        this.sessions = [...newSessions, ...extraSessions];

                        // Re-sync live SSE status into freshly loaded sidebar data to prevent
                        // flicker when the backend hasn't persisted the latest status yet
                        if (this.currentConversationStatus && this.currentSession?.id) {
                            const activeScreen = this.getScreen(this.activeScreenId);
                            if (activeScreen?.type === 'chat' && activeScreen.conversation_id) {
                                const sidebarSession = this.sessions.find(s => s.id === this.currentSession.id);
                                const screenInSidebar = sidebarSession?.screens?.find(
                                    sc => sc.conversation?.id === activeScreen.conversation_id
                                );
                                if (screenInSidebar?.conversation) {
                                    screenInSidebar.conversation.status = this.currentConversationStatus;
                                }
                            }
                        }

                        // Don't reset sessionsPage — keep it at the user's scroll depth so
                        // fetchMoreSessions continues from the right position.
                        // Compute last_page relative to the standard page size (20) that
                        // fetchMoreSessions uses, not the inflated per_page we sent.
                        this.sessionsLastPage = data.total ? Math.ceil(data.total / 20) : 1;
                    } catch (err) {
                        console.error('Failed to refresh sidebar:', err);
                    }
                },

                async newConversation() {
                    // Disconnect from any active stream
                    this.disconnectFromStream();

                    this.currentConversationUuid = null;
                    this.currentConversationStatus = null; // Reset status for new conversation
                    this.currentConversationTitle = null; // Reset title for new conversation
                    this.conversationProvider = null; // Reset for new conversation
                    this.messages = [];

                    // Clear session/screen state for new conversation
                    this.currentSession = null;
                    this.screens = [];
                    this.activeScreenId = null;
                    this._screenMap = {};
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
                    this.updateSessionUrl(null);

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

                async toggleArchiveConversation(screenId = null) {
                    // Note: API routes don't use CSRF middleware - Laravel excludes it by design for stateless APIs
                    // If screenId provided, use that screen; otherwise use active screen
                    const targetScreenId = screenId || this.activeScreenId;
                    const screen = this.getScreen(targetScreenId);

                    // Panel screens have no conversation - fall back to close
                    if (screen?.type === 'panel') {
                        return this.closeScreen(targetScreenId);
                    }

                    const conversationUuid = screen?.conversation?.uuid;
                    if (!conversationUuid) return;

                    const isArchived = screen?.conversation?.status === 'archived';
                    const action = isArchived ? 'unarchive' : 'archive';

                    try {
                        const response = await fetch(`/api/conversations/${conversationUuid}/${action}`, {
                            method: 'POST'
                        });
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);

                        // Update local state - 'idle' is intentional for unarchive since completed
                        // conversations naturally rest at 'idle', and 'failed' ones can be retried
                        const newStatus = isArchived ? 'idle' : 'archived';

                        // Update currentConversationStatus if targeting the active screen
                        if (targetScreenId === this.activeScreenId) {
                            this.currentConversationStatus = newStatus;
                        }

                        // Also update the screen's conversation status in local data
                        if (screen?.conversation) {
                            screen.conversation.status = newStatus;
                        }

                        // If archiving, switch to another visible screen
                        if (!isArchived) {
                            // Find another visible screen to switch to
                            const otherVisibleScreen = this.visibleScreenOrder.find(id => id !== targetScreenId);
                            if (otherVisibleScreen) {
                                this.activateScreen(otherVisibleScreen);
                            }
                            this.showToast('Chat archived');
                        } else {
                            this.showToast('Chat restored');
                        }
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
                    } catch (err) {
                        console.error('Failed to delete conversation:', err);
                        this.showError('Failed to delete conversation');
                    }
                },

                openRenameModal() {
                    if (!this.currentConversationUuid) return;
                    this.renameTitle = this.currentConversationTitle || '';
                    // Get tab_label from current screen's conversation
                    const screen = this.getScreen(this.activeScreenId);
                    this.renameTabLabel = screen?.conversation?.tab_label || '';
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

                    // Enforce tab label max length (6 chars)
                    if (this.renameTabLabel.trim().length > 6) {
                        this.showError('Tab label cannot exceed 6 characters');
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
                            body: JSON.stringify({
                                title: this.renameTitle.trim(),
                                tab_label: this.renameTabLabel.trim() || null
                            })
                        });

                        if (!response.ok) throw new Error(`HTTP ${response.status}`);

                        const data = await response.json();
                        this.currentConversationTitle = data.title;

                        // Also update the screen in the current session if this conversation is displayed
                        if (this.activeScreenId && this.screens) {
                            const screen = this.getScreen(this.activeScreenId);
                            if (screen?.type === 'chat' && screen.conversation?.uuid === this.currentConversationUuid) {
                                screen.conversation.title = data.title;
                                screen.conversation.tab_label = data.tab_label;
                            }
                        }

                        this.showRenameModal = false;
                    } catch (err) {
                        console.error('Failed to rename conversation:', err);
                        this.showError('Failed to rename conversation');
                    } finally {
                        this.renameSaving = false;
                    }
                },

                openRenameSessionModal(sessionId = null) {
                    const targetId = sessionId || this.currentSession?.id;
                    if (!targetId) return;

                    const session = this.filteredSessions.find(s => s.id === targetId) || this.currentSession;
                    if (!session) return;

                    this.renameSessionTargetId = targetId;
                    this.renameSessionName = session.name || '';
                    this.showRenameSessionModal = true;
                    // Focus input after modal opens
                    this.$nextTick(() => {
                        this.$refs.renameSessionInput?.focus();
                        this.$refs.renameSessionInput?.select();
                    });
                },

                async saveSessionName() {
                    const targetId = this.renameSessionTargetId || this.currentSession?.id;
                    if (!targetId || !this.renameSessionName.trim()) return;

                    // Enforce max character limit
                    if (this.renameSessionName.trim().length > window.TITLE_MAX_LENGTH) {
                        this.showError(`Session name cannot exceed ${window.TITLE_MAX_LENGTH} characters`);
                        return;
                    }

                    this.renameSessionSaving = true;
                    try {
                        const response = await fetch(`/api/sessions/${targetId}`, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ name: this.renameSessionName.trim() })
                        });

                        if (!response.ok) throw new Error(`HTTP ${response.status}`);

                        const data = await response.json();

                        // Update the current session if it's the one being renamed
                        if (this.currentSession?.id === targetId) {
                            this.currentSession.name = data.name;
                        }

                        // Update the session in the sessions list for sidebar
                        const sessionInList = this.filteredSessions.find(s => s.id === targetId);
                        if (sessionInList) {
                            sessionInList.name = data.name;
                        }

                        this.showRenameSessionModal = false;
                        this.renameSessionTargetId = null;
                    } catch (err) {
                        console.error('Failed to rename session:', err);
                        this.showError('Failed to rename session');
                    } finally {
                        this.renameSessionSaving = false;
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

                                    // Reload sessions and agents for the new workspace
                                    await this.fetchSessions();
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

                        // Note: URL is session-based, so we don't update it when loading a conversation
                        // The session URL is set when loadSession() is called
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
                            // Only lock provider (for mid-conversation agent filtering) if conversation has messages
                            // New conversations should allow switching to any agent/provider
                            const hasMessages = data.conversation.messages && data.conversation.messages.length > 0;
                            this.conversationProvider = hasMessages ? data.conversation.provider_type : null;
                            this.updateModels(); // Refresh available models for this provider
                        }
                        if (data.conversation?.model) {
                            this.model = data.conversation.model;
                        }

                        // Update conversation status for header badge
                        this.currentConversationStatus = data.conversation?.status || 'idle';

                        // Update conversation title for header
                        this.currentConversationTitle = data.conversation?.title || null;

                        // Load session and screens if available
                        console.log('[DEBUG] Screen data:', data.conversation?.screen);
                        console.log('[DEBUG] Session data:', data.conversation?.screen?.session);
                        if (data.conversation?.screen?.session) {
                            console.log('[DEBUG] Loading session from conversation');
                            await this.loadSessionFromConversation(data.conversation.screen.session);
                            this.activeScreenId = data.conversation.screen.id;
                            console.log('[DEBUG] currentSession set:', this.currentSession);
                            console.log('[DEBUG] activeScreenId set:', this.activeScreenId);
                        } else {
                            console.log('[DEBUG] No screen.session found - clearing session state');
                            // Clear session state if conversation has no screen
                            this.currentSession = null;
                            this.screens = [];
                            this.activeScreenId = null;
                            this._screenMap = {};
                        }

                        // Set agent from conversation (don't use selectAgent which would PATCH backend)
                        // If agents not yet loaded, fetch them first
                        if (this.agents.length === 0) {
                            await this.fetchAgents();
                        }

                        if (data.conversation?.agent_id) {
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
                            // Conversation has no agent - use default agent for NEW conversations (no messages)
                            const hasMessages = data.conversation.messages && data.conversation.messages.length > 0;
                            if (!hasMessages && this.agents.length > 0) {
                                // New conversation: select default agent
                                const defaultAgent = this.agents.find(a => a.is_default) || this.agents[0];
                                if (defaultAgent) {
                                    this.currentAgentId = defaultAgent.id;
                                    this.claudeCodeAllowedTools = defaultAgent.allowed_tools || [];
                                    this.provider = defaultAgent.provider;
                                    this.model = defaultAgent.model;
                                    this.clearActiveSkill();
                                    this.fetchSkills();
                                    this.updateModels();
                                }
                            } else {
                                // Existing conversation without agent - leave as null
                                this.currentAgentId = null;
                            }
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
                        this.updateSessionUrl(null, { replace: true });
                    }
                },

                // ===== Sessions & Screens =====

                // Fetch sessions for current workspace (first page)
                async fetchSessions() {
                    console.log('[DEBUG] fetchSessions called, workspaceId:', this.currentWorkspaceId);
                    if (!this.currentWorkspaceId) {
                        console.log('[DEBUG] fetchSessions: No workspace ID, returning early');
                        return;
                    }

                    try {
                        const params = new URLSearchParams({
                            workspace_id: this.currentWorkspaceId,
                            include_archived: this.showArchivedSessions ? '1' : '0',
                        });
                        console.log('[DEBUG] fetchSessions: Fetching from /api/sessions?' + params.toString());
                        const response = await fetch(`/api/sessions?${params}`);
                        console.log('[DEBUG] fetchSessions: Response status:', response.status);
                        if (response.ok) {
                            const data = await response.json();
                            this.sessions = data.data || [];
                            this.sessionsPage = data.current_page || 1;
                            this.sessionsLastPage = data.last_page || 1;
                            console.log('[DEBUG] Fetched sessions:', this.sessions.length, 'page', this.sessionsPage, 'of', this.sessionsLastPage);
                        } else {
                            console.error('[DEBUG] fetchSessions: Non-OK response', response.status, await response.text());
                        }
                    } catch (err) {
                        console.error('Failed to fetch sessions:', err);
                    }
                },

                // Fetch more sessions (infinite scroll)
                async fetchMoreSessions() {
                    console.log('[DEBUG] fetchMoreSessions called, page:', this.sessionsPage, 'lastPage:', this.sessionsLastPage, 'loading:', this.loadingMoreSessions);
                    if (this.loadingMoreSessions || this.sessionsPage >= this.sessionsLastPage) {
                        console.log('[DEBUG] fetchMoreSessions skipped - already loading or at last page');
                        return;
                    }

                    this.loadingMoreSessions = true;
                    try {
                        const nextPage = this.sessionsPage + 1;
                        const params = new URLSearchParams({
                            workspace_id: this.currentWorkspaceId,
                            include_archived: this.showArchivedSessions ? '1' : '0',
                            page: nextPage.toString(),
                        });
                        console.log('[DEBUG] fetchMoreSessions fetching page:', nextPage);
                        const response = await fetch(`/api/sessions?${params}`);
                        if (response.ok) {
                            const data = await response.json();
                            const newSessions = data.data || [];

                            // Deduplicate: only add sessions not already in the array
                            // This handles race conditions with refreshSidebar which resets sessionsPage
                            const existingIds = new Set(this.sessions.map(s => s.id));
                            const uniqueNewSessions = newSessions.filter(s => !existingIds.has(s.id));

                            console.log('[DEBUG] fetchMoreSessions received:', newSessions.length, 'items,', uniqueNewSessions.length, 'unique, page:', data.current_page);
                            this.sessions = [...this.sessions, ...uniqueNewSessions];
                            this.sessionsPage = data.current_page || nextPage;
                            this.sessionsLastPage = data.last_page || this.sessionsLastPage;
                        }
                    } catch (err) {
                        console.error('Failed to fetch more sessions:', err);
                    } finally {
                        this.loadingMoreSessions = false;
                    }
                },

                // Handle sessions list scroll for infinite scroll
                handleSessionsScroll(event) {
                    const el = event.target;
                    const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 50;
                    if (nearBottom) {
                        this.fetchMoreSessions();
                    }
                },

                // Load a session by ID
                async loadSession(sessionId) {
                    console.log('[DEBUG] loadSession called:', sessionId);
                    try {
                        const response = await fetch(`/api/sessions/${sessionId}`);
                        if (!response.ok) throw new Error('Failed to load session');

                        const session = await response.json();
                        console.log('[DEBUG] Loaded session:', session.id, session.name, 'screens:', session.screens?.length);

                        // Update URL to session (only if different session)
                        if (this.currentSession?.id !== session.id) {
                            this.updateSessionUrl(session.id);
                        }

                        // Save as last session for this workspace (for returning from settings)
                        fetch(`/session/${sessionId}/last`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            }
                        }).catch(() => {}); // Fire and forget

                        // Set session state
                        this.currentSession = session;
                        this.screens = session.screens || [];

                        // Build lookup map
                        this._screenMap = {};
                        for (const screen of this.screens) {
                            this._screenMap[screen.id] = screen;
                        }

                        // Determine which screen to show (last active or first)
                        const activeScreenId = session.last_active_screen_id || session.screen_order?.[0];
                        this.activeScreenId = activeScreenId;

                        // If it's a chat screen, load the conversation
                        const activeScreen = this.getScreen(activeScreenId);
                        if (activeScreen?.type === 'chat' && activeScreen.conversation_id) {
                            const convUuid = activeScreen.conversation?.uuid;
                            if (convUuid) {
                                // Load conversation without triggering session reload
                                await this.loadConversationForScreen(convUuid);
                            }
                        }
                        // If it's a panel screen, load the panel content
                        if (activeScreen?.type === 'panel' && activeScreen.panel_id) {
                            await this.loadPanelContent(activeScreen.panel_id);
                        }

                    } catch (err) {
                        console.error('Failed to load session:', err);
                        this.showError('Failed to load session');
                    }
                },

                // Refresh screens list without reloading conversation (for when panels are opened programmatically)
                async refreshSessionScreens() {
                    if (!this.currentSession?.id) return;

                    try {
                        const response = await fetch(`/api/sessions/${this.currentSession.id}`);
                        if (!response.ok) throw new Error('Failed to refresh session');

                        const session = await response.json();

                        // Update screens list
                        this.screens = session.screens || [];

                        // Update currentSession with new screen_order (this drives the tab rendering)
                        if (this.currentSession) {
                            this.currentSession.screen_order = session.screen_order || [];
                            this.currentSession.last_active_screen_id = session.last_active_screen_id;
                        }

                        // Rebuild lookup map
                        this._screenMap = {};
                        for (const screen of this.screens) {
                            this._screenMap[screen.id] = screen;
                        }

                        // Update to the newly active screen (the one that was just opened)
                        if (session.last_active_screen_id && session.last_active_screen_id !== this.activeScreenId) {
                            this.activeScreenId = session.last_active_screen_id;
                            const activeScreen = this.getScreen(this.activeScreenId);

                            // If it's a panel, load its content
                            if (activeScreen?.type === 'panel' && activeScreen.panel_id) {
                                await this.loadPanelContent(activeScreen.panel_id);
                            }
                        }
                    } catch (err) {
                        console.error('Failed to refresh session screens:', err);
                    }
                },

                // Load conversation without reloading session (for screen switching)
                async loadConversationForScreen(uuid) {
                    this.loadingConversation = true;
                    this._loadingConversationUuid = uuid;
                    this.disconnectFromStream();

                    // Capture pendingScrollToTurn before loading (it will be cleared after use)
                    const targetTurn = this.pendingScrollToTurn;

                    try {
                        const response = await fetch(`/api/conversations/${uuid}`);
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        const data = await response.json();

                        if (this._loadingConversationUuid !== uuid) return;

                        this.currentConversationUuid = uuid;
                        // Note: Don't update URL here - URLs are session-based, not conversation-based
                        this.messages = [];
                        this.isAtBottom = true;
                        // Disable auto-scroll when targeting a specific turn (like loadConversation does)
                        this.autoScrollEnabled = targetTurn === null;
                        this.ignoreScrollEvents = true;

                        // Reset counters
                        this.inputTokens = 0;
                        this.outputTokens = 0;
                        this.cacheCreationTokens = 0;
                        this.cacheReadTokens = 0;
                        this.sessionCost = 0;

                        // Sum costs from messages
                        if (data.conversation?.messages) {
                            for (const msg of data.conversation.messages) {
                                this.inputTokens += msg.input_tokens || 0;
                                this.outputTokens += msg.output_tokens || 0;
                                this.cacheCreationTokens += msg.cache_creation_tokens || 0;
                                this.cacheReadTokens += msg.cache_read_tokens || 0;
                                if (msg.cost) this.sessionCost += msg.cost;
                            }
                        }
                        this.totalTokens = this.inputTokens + this.outputTokens;

                        // Load context tracking
                        if (data.context) {
                            this.contextWindowSize = data.context.context_window_size || 0;
                            this.lastContextTokens = data.context.last_context_tokens || 0;
                            this.contextPercentage = data.context.usage_percentage || 0;
                            this.contextWarningLevel = data.context.warning_level || 'safe';
                        }

                        // Load messages (pass targetTurn to center initial batch if searching)
                        if (data.conversation?.messages?.length > 0) {
                            await this.loadMessagesProgressively(data.conversation.messages, targetTurn, uuid);
                        } else {
                            this.loadingConversation = false;
                            this._loadingConversationUuid = null;
                        }

                        // Clear pendingScrollToTurn after applying (so later loads behave normally)
                        this.pendingScrollToTurn = null;

                        // Update provider/model
                        const hasMessages = data.conversation?.messages && data.conversation.messages.length > 0;
                        if (data.conversation?.provider_type) {
                            this.provider = data.conversation.provider_type;
                            // Only lock provider for mid-conversation agent filtering if conversation has started
                            this.conversationProvider = hasMessages ? data.conversation.provider_type : null;
                            this.updateModels();
                        }
                        if (data.conversation?.model) {
                            this.model = data.conversation.model;
                        }

                        this.currentConversationStatus = data.conversation?.status || 'idle';
                        this.currentConversationTitle = data.conversation?.title || null;

                        // Set agent
                        if (data.conversation?.agent_id) {
                            const agent = this.agents.find(a => a.id === data.conversation.agent_id);
                            if (agent) {
                                this.currentAgentId = agent.id;
                                this.claudeCodeAllowedTools = agent.allowed_tools || [];
                                this.clearActiveSkill();
                                this.fetchSkills();
                            }
                        } else if (!hasMessages && this.agents.length > 0) {
                            // New conversation without agent: use default agent
                            const defaultAgent = this.agents.find(a => a.is_default) || this.agents[0];
                            if (defaultAgent) {
                                this.currentAgentId = defaultAgent.id;
                                this.claudeCodeAllowedTools = defaultAgent.allowed_tools || [];
                                this.provider = defaultAgent.provider;
                                this.model = defaultAgent.model;
                                this.clearActiveSkill();
                                this.fetchSkills();
                                this.updateModels();
                            }
                        } else {
                            this.currentAgentId = null;
                        }

                        this.$nextTick(() => {
                            this.ignoreScrollEvents = false;
                        });

                        await this.checkAndReconnectStream(uuid);

                    } catch (err) {
                        this.loadingConversation = false;
                        this._loadingConversationUuid = null;
                        console.error('Failed to load conversation:', err);
                    }
                },

                // Create a new session
                async newSession() {
                    if (!this.currentWorkspaceId) return;

                    try {
                        const response = await fetch('/api/sessions', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                workspace_id: this.currentWorkspaceId,
                                name: 'New Session',
                                create_initial_chat: true,
                            }),
                        });

                        if (!response.ok) throw new Error('Failed to create session');

                        const session = await response.json();
                        console.log('[DEBUG] Created new session:', session.id);

                        // Add to sessions list
                        this.sessions.unshift(session);

                        // Load the new session
                        await this.loadSession(session.id);

                    } catch (err) {
                        console.error('Failed to create session:', err);
                        this.showError('Failed to create session');
                    }
                },

                // Filter sessions (triggered by search input)
                filterSessions() {
                    // The filtering is done by the computed getter `filteredSessions`
                    // This method exists for the @input handler
                },

                // Clear all filters (sessions and conversation search)
                clearAllFilters() {
                    this.showArchivedSessions = false;
                    this.sessionSearchQuery = '';
                    this.sidebarSearchMode = 'sessions';
                    this.showArchivedConversations = false;
                    this.clearConversationSearch(); // Invalidates in-flight requests
                    this.fetchSessions();
                },

                // Search conversations semantically
                async searchConversations() {
                    if (!this.conversationSearchQuery.trim()) {
                        this.clearConversationSearch(); // Invalidates in-flight requests
                        return;
                    }

                    // Increment request ID to track this specific request
                    const requestId = ++this.conversationSearchRequestId;
                    this.conversationSearchLoading = true;

                    try {
                        const params = new URLSearchParams({
                            query: this.conversationSearchQuery.trim(),
                            limit: '20',
                        });
                        if (this.showArchivedConversations) {
                            params.set('include_archived', 'true');
                        }
                        const response = await fetch(`/api/conversations/search?${params}`);
                        if (!response.ok) throw new Error(`HTTP ${response.status}`);
                        const data = await response.json();

                        // Only update if this is still the latest request
                        if (requestId === this.conversationSearchRequestId) {
                            this.conversationSearchResults = data.results || [];
                        }
                    } catch (err) {
                        console.error('Failed to search conversations:', err);
                        if (requestId === this.conversationSearchRequestId) {
                            this.conversationSearchResults = [];
                        }
                    } finally {
                        if (requestId === this.conversationSearchRequestId) {
                            this.conversationSearchLoading = false;
                        }
                    }
                },

                // Clear conversation search
                clearConversationSearch() {
                    this.conversationSearchRequestId++; // Invalidate any in-flight requests
                    this.conversationSearchQuery = '';
                    this.conversationSearchResults = [];
                    this.conversationSearchLoading = false;
                },

                // Load a conversation from search result
                async loadSearchResult(result) {
                    // Close mobile drawer
                    this.showMobileDrawer = false;

                    const targetTurn = result.turn_number;

                    // If we have session info, load the session and activate the screen
                    if (result.session_id) {
                        await this.loadSession(result.session_id);

                        // Find the screen with this conversation
                        const screen = this.screens.find(s =>
                            s.conversation?.uuid === result.conversation_uuid
                        );
                        if (screen) {
                            // Set pendingScrollToTurn so loadConversationForScreen centers around it
                            this.pendingScrollToTurn = targetTurn;
                            this.autoScrollEnabled = false;

                            // If screen is already active, activateScreen returns early without loading
                            // so we need to scroll directly instead
                            if (this.activeScreenId === screen.id) {
                                this.$nextTick(() => {
                                    this.scrollToTurn(targetTurn);
                                    this.pendingScrollToTurn = null;
                                });
                            } else {
                                await this.activateScreen(screen.id);
                            }
                            return;
                        }
                    }

                    // Fallback: load conversation directly (handles pendingScrollToTurn internally)
                    this.pendingScrollToTurn = targetTurn;
                    this.autoScrollEnabled = false;
                    await this.loadConversation(result.conversation_uuid);
                },

                // Load session data from a conversation's screen.session relationship
                async loadSessionFromConversation(session) {
                    console.log('[DEBUG] loadSessionFromConversation called with session:', session?.id, session?.name);
                    console.log('[DEBUG] Session screens:', session?.screens?.length, session?.screens?.map(s => s.id));
                    console.log('[DEBUG] Session screen_order:', session?.screen_order);
                    this.currentSession = session;
                    this.screens = session.screens || [];
                    console.log('[DEBUG] currentSession now set to:', this.currentSession?.id);

                    // Build a lookup map for quick screen access
                    this._screenMap = {};
                    for (const screen of this.screens) {
                        this._screenMap[screen.id] = screen;
                    }
                },

                // Get screen by ID
                getScreen(screenId) {
                    return this._screenMap?.[screenId] || this.screens.find(s => s.id === screenId);
                },

                // Get screen title for display (full title, used for tooltips)
                getScreenTitle(screenId) {
                    const screen = this.getScreen(screenId);
                    if (!screen) return 'Screen';
                    if (screen.type === 'chat') {
                        return screen.conversation?.title || 'Chat';
                    }
                    return screen.panel?.name || screen.panel_slug || 'Panel';
                },

                // Get screen tab label for display (short form for tabs)
                getScreenTabLabel(screenId) {
                    const screen = this.getScreen(screenId);
                    if (!screen) return 'Screen';
                    if (screen.type === 'chat') {
                        // Use tab_label if set, otherwise derive from title
                        const tabLabel = screen.conversation?.tab_label;
                        if (tabLabel && tabLabel.trim()) {
                            return tabLabel;
                        }
                        const title = screen.conversation?.title || 'Chat';
                        return title.length > 5 ? title.slice(0, 5) + '...' : title;
                    }
                    // For panels, use the full name (they're typically short already)
                    return screen.panel?.name || screen.panel_slug || 'Panel';
                },

                // Get screen type icon class
                getScreenIcon(screenId) {
                    const screen = this.getScreen(screenId);
                    if (!screen) return 'fa-solid fa-square';
                    if (screen.type === 'panel') {
                        // Look up panel's custom icon from availablePanels (system panels have custom icons)
                        const panelSlug = screen.panel_slug || screen.panel?.slug;
                        const panelInfo = this.availablePanels?.find(p => p.slug === panelSlug);
                        // If panels haven't loaded yet (API failure), trigger a background retry
                        if (!panelInfo && this.availablePanels.length === 0 && !this._panelsFetchPending) {
                            this._panelsFetchPending = true;
                            this.fetchAvailablePanels().finally(() => { this._panelsFetchPending = false; });
                        }
                        return panelInfo?.icon || 'fa-solid fa-table-columns';
                    }
                    // Chat screen: show status icon
                    return this.getStatusIconClass(this.getConversationStatus(screenId));
                },

                // Get screen type color class (badge background color)
                getScreenTypeColor(screenId) {
                    const screen = this.getScreen(screenId);
                    if (!screen) return 'bg-gray-600';
                    if (screen.type === 'panel') return 'bg-purple-600';
                    // Chat screen: show status badge color
                    return this.getStatusColorClass(this.getConversationStatus(screenId));
                },

                // Activate a screen (switch to it)
                async activateScreen(screenId) {
                    const screen = this.getScreen(screenId);
                    if (!screen) return;

                    // If clicking the already active screen, do nothing
                    if (this.activeScreenId === screenId) return;

                    // Update local state first
                    this.activeScreenId = screenId;

                    // Update server state (sets last_active_screen_id on session)
                    try {
                        await fetch(`/api/screens/${screenId}/activate`, { method: 'POST' });
                    } catch (err) {
                        console.error('Failed to activate screen:', err);
                    }

                    // For chat screens, load the conversation content
                    if (screen.type === 'chat' && screen.conversation_id) {
                        const convUuid = screen.conversation?.uuid;
                        if (convUuid) {
                            await this.loadConversationForScreen(convUuid);
                        }
                    }
                    // For panel screens, load panel content
                    if (screen.type === 'panel' && screen.panel_id) {
                        await this.loadPanelContent(screen.panel_id);
                    }
                },

                // Load panel content from server
                // Track currently loaded panel to avoid unnecessary reloads
                _loadedPanelStateId: null,
                _panelLoadToken: 0,

                async loadPanelContent(panelStateId, force = false) {
                    // Skip reload if already loaded and not forced
                    if (!force && this._loadedPanelStateId === panelStateId && this.panelContent) {
                        return;
                    }

                    this.loadingPanel = true;
                    // Generate unique token to guard against stale loads from rapid switching
                    const loadToken = ++this._panelLoadToken;

                    try {
                        const response = await fetch(`/api/panel/${panelStateId}/render`);
                        if (!response.ok) {
                            throw new Error('Failed to load panel');
                        }
                        const content = await response.text();

                        // Discard stale result if a newer load was started
                        if (loadToken !== this._panelLoadToken) return;

                        this.panelContent = content;
                        this._loadedPanelStateId = panelStateId;

                        // Double-buffer: load into hidden iframe, then swap visibility
                        const inactiveBuffer = this.activeIframeBuffer === 'A' ? 'B' : 'A';
                        const inactiveIframe = this.$refs[`iframe${inactiveBuffer}`];
                        const activeIframe = this.$refs[`iframe${this.activeIframeBuffer}`];

                        if (inactiveIframe) {
                            // Create a promise that resolves when the iframe loads (with 30s timeout)
                            const loadPromise = new Promise((resolve) => {
                                const onLoad = () => {
                                    inactiveIframe.removeEventListener('load', onLoad);
                                    resolve('loaded');
                                };
                                inactiveIframe.addEventListener('load', onLoad);
                                inactiveIframe.srcdoc = content;
                            });
                            const timeoutPromise = new Promise((resolve) => {
                                setTimeout(() => resolve('timeout'), 30000);
                            });
                            await Promise.race([loadPromise, timeoutPromise]);

                            // Discard if a newer load was started during iframe load
                            if (loadToken !== this._panelLoadToken) return;

                            // Swap visibility instantly
                            this.activeIframeBuffer = inactiveBuffer;

                            // Clear the now-hidden iframe to free resources
                            if (activeIframe) {
                                activeIframe.srcdoc = '';
                            }
                        }
                    } catch (err) {
                        // Discard stale error if a newer load was started
                        if (loadToken !== this._panelLoadToken) return;

                        console.error('Failed to load panel content:', err);
                        this.panelContent = '<div class="p-4 text-red-500">Failed to load panel content</div>';
                        // On error, load directly into current iframe
                        const currentIframe = this.$refs[`iframe${this.activeIframeBuffer}`];
                        if (currentIframe) {
                            currentIframe.srcdoc = this.panelContent;
                        }
                    } finally {
                        // Only clear loading state if this is still the current load
                        if (loadToken === this._panelLoadToken) {
                            this.loadingPanel = false;
                        }
                    }
                },

                // Mobile swipe navigation handlers
                // Check if an element or its parents have horizontal scroll available
                isInHorizontalScrollArea(element) {
                    let el = element;
                    while (el && el !== document.body) {
                        // Check if this element can scroll horizontally
                        if (el.scrollWidth > el.clientWidth) {
                            const style = window.getComputedStyle(el);
                            if (style.overflowX === 'auto' || style.overflowX === 'scroll') {
                                return true;
                            }
                        }
                        el = el.parentElement;
                    }
                    return false;
                },

                handleSwipeStart(e) {
                    // Only enable swipe on mobile and when we have multiple screens
                    if (this.windowWidth >= 768 || this.screens.length <= 1) return;

                    // Don't activate swipe if touch started in a horizontally scrollable area
                    if (this.isInHorizontalScrollArea(e.target)) return;

                    this.swipeStartX = e.touches[0].clientX;
                    this.swipeStartY = e.touches[0].clientY;
                    this.swipeCurrentX = this.swipeStartX;
                    this.swipeDeltaX = 0;
                    this.isSwiping = false;
                },

                handleSwipeMove(e) {
                    if (this.windowWidth >= 768 || this.screens.length <= 1) return;
                    if (this.swipeStartX === 0) return;

                    const currentX = e.touches[0].clientX;
                    const currentY = e.touches[0].clientY;
                    const deltaX = currentX - this.swipeStartX;
                    const deltaY = currentY - this.swipeStartY;

                    // Only start swiping if horizontal movement is greater than vertical
                    if (!this.isSwiping) {
                        if (Math.abs(deltaX) > 10 && Math.abs(deltaX) > Math.abs(deltaY)) {
                            this.isSwiping = true;
                        } else if (Math.abs(deltaY) > 10) {
                            // User is scrolling vertically, abort swipe
                            this.swipeStartX = 0;
                            return;
                        }
                    }

                    if (!this.isSwiping) return;

                    // Prevent vertical scrolling while swiping
                    e.preventDefault();

                    const screenOrder = this.currentSession?.screen_order || [];
                    const currentIndex = screenOrder.indexOf(this.activeScreenId);
                    const isAtStart = currentIndex === 0;
                    const isAtEnd = currentIndex === screenOrder.length - 1;

                    // Apply edge resistance when at boundaries
                    let adjustedDelta = deltaX;
                    if ((deltaX > 0 && isAtStart) || (deltaX < 0 && isAtEnd)) {
                        adjustedDelta = deltaX * this.swipeEdgeResistance;
                    }

                    this.swipeDeltaX = adjustedDelta;
                    this.swipeCurrentX = currentX;
                },

                handleSwipeEnd(e) {
                    if (this.windowWidth >= 768 || !this.isSwiping) {
                        this.resetSwipeState();
                        return;
                    }

                    const screenOrder = this.currentSession?.screen_order || [];
                    const currentIndex = screenOrder.indexOf(this.activeScreenId);

                    // Check if swipe exceeded threshold
                    if (Math.abs(this.swipeDeltaX) > this.swipeThreshold) {
                        if (this.swipeDeltaX > 0 && currentIndex > 0) {
                            // Swipe right → previous screen
                            this.activateScreen(screenOrder[currentIndex - 1]);
                        } else if (this.swipeDeltaX < 0 && currentIndex < screenOrder.length - 1) {
                            // Swipe left → next screen
                            this.activateScreen(screenOrder[currentIndex + 1]);
                        }
                    }

                    this.resetSwipeState();
                },

                resetSwipeState() {
                    this.swipeStartX = 0;
                    this.swipeStartY = 0;
                    this.swipeCurrentX = 0;
                    this.swipeDeltaX = 0;
                    this.isSwiping = false;
                },

                // Close/remove a screen
                async closeScreen(screenId) {
                    if (this.screens.length <= 1) return; // Don't close last screen

                    const screen = this.getScreen(screenId);
                    if (!screen) return;

                    try {
                        await fetch(`/api/screens/${screenId}`, { method: 'DELETE' });

                        // Clear loaded panel cache if closing the currently loaded panel
                        if (screen.type === 'panel' && screen.panel_id === this._loadedPanelStateId) {
                            this._loadedPanelStateId = null;
                            this.panelContent = '';
                        }

                        // Remove from local state
                        this.screens = this.screens.filter(s => s.id !== screenId);
                        if (this.currentSession?.screen_order) {
                            this.currentSession.screen_order = this.currentSession.screen_order.filter(id => id !== screenId);
                        }
                        delete this._screenMap[screenId];

                        // If we closed the active screen, switch to another
                        if (this.activeScreenId === screenId) {
                            const nextScreen = this.screens[0];
                            if (nextScreen) {
                                await this.activateScreen(nextScreen.id);
                            }
                        }
                    } catch (err) {
                        console.error('Failed to close screen:', err);
                        this.showError('Failed to close screen');
                    }
                },

                // Add a new chat screen
                async addChatScreen() {
                    // If no session exists yet, create one first
                    if (!this.currentSession) {
                        try {
                            const response = await fetch('/api/sessions', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    name: 'New Session',
                                    workspace_id: this.currentWorkspaceId
                                })
                            });
                            if (!response.ok) throw new Error('Failed to create session');
                            const session = await response.json();
                            this.currentSession = session;
                            this.sessions.unshift(session);
                            this.screens = session.screens || [];
                            this._screenMap = {};
                            for (const screen of this.screens) {
                                this._screenMap[screen.id] = screen;
                            }
                            console.log('[DEBUG] Created new session for chat:', session.id);
                        } catch (err) {
                            console.error('Failed to create session for chat:', err);
                            this.showError('Failed to create session');
                            return;
                        }
                    }

                    try {
                        // Get default agent for new conversations (same logic as loadConversation)
                        const defaultAgent = this.agents.find(a => a.is_default) || this.agents[0];

                        const response = await fetch(`/api/sessions/${this.currentSession.id}/screens/chat`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                activate: true,
                                agent_id: defaultAgent?.id || null
                            })
                        });

                        if (!response.ok) throw new Error('Failed to create chat screen');

                        const screen = await response.json();
                        this.screens.push(screen);
                        this._screenMap[screen.id] = screen;
                        this.currentSession.screen_order = [...(this.currentSession.screen_order || []), screen.id];
                        this.activeScreenId = screen.id;

                        // Load the new conversation
                        if (screen.conversation?.uuid) {
                            await this.loadConversation(screen.conversation.uuid);

                            // Fallback: ensure conversation is set even if loadConversation returned early
                            // This handles race conditions where _loadingConversationUuid changed
                            // Note: URL is session-based, so we don't update it when switching screens
                            if (this.currentConversationUuid !== screen.conversation.uuid) {
                                this.currentConversationUuid = screen.conversation.uuid;
                            }
                        }

                        // Dispatch event to scroll tabs
                        this.$dispatch('screen-added');
                    } catch (err) {
                        console.error('Failed to add chat screen:', err);
                        this.showError('Failed to add new chat');
                    }
                },

                // Add a new panel screen
                async addPanelScreen(panelSlug) {
                    // If no session exists yet, create one first
                    if (!this.currentSession) {
                        try {
                            const response = await fetch('/api/sessions', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    name: 'New Session',
                                    workspace_id: this.currentWorkspaceId
                                })
                            });
                            if (!response.ok) throw new Error('Failed to create session');
                            const session = await response.json();
                            this.currentSession = session;
                            this.sessions.unshift(session);
                            this.screens = session.screens || [];
                            this._screenMap = {};
                            for (const screen of this.screens) {
                                this._screenMap[screen.id] = screen;
                            }
                            console.log('[DEBUG] Created new session for panel:', session.id);
                        } catch (err) {
                            console.error('Failed to create session for panel:', err);
                            this.showError('Failed to create session');
                            return;
                        }
                    }

                    try {
                        const response = await fetch(`/api/sessions/${this.currentSession.id}/screens/panel`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ panel_slug: panelSlug, activate: true })
                        });

                        if (!response.ok) {
                            const errorText = await response.text();
                            console.error('Panel creation failed:', response.status, errorText);
                            throw new Error('Failed to create panel screen');
                        }

                        const data = await response.json();
                        console.log('[DEBUG] Panel screen created:', data);
                        const screen = data.screen;
                        // Store panel info in screen for tab display
                        if (data.panel) {
                            screen.panel = data.panel;
                        }
                        this.screens.push(screen);
                        this._screenMap[screen.id] = screen;
                        this.currentSession.screen_order = [...(this.currentSession.screen_order || []), screen.id];
                        this.activeScreenId = screen.id;
                        console.log('[DEBUG] Active screen set:', screen.id, 'type:', screen.type, 'panel_id:', screen.panel_id);
                        console.log('[DEBUG] isActiveScreenPanel:', this.isActiveScreenPanel);

                        // Load panel content
                        if (screen.panel_id) {
                            console.log('[DEBUG] Loading panel content for:', screen.panel_id);
                            await this.loadPanelContent(screen.panel_id);
                            console.log('[DEBUG] Panel content loaded, length:', this.panelContent?.length);
                        } else {
                            console.warn('[DEBUG] No panel_id on screen:', screen);
                        }

                        // Dispatch event to scroll tabs
                        this.$dispatch('screen-added');
                    } catch (err) {
                        console.error('Failed to add panel screen:', err);
                        this.showError('Failed to add panel');
                    }
                },

                // Scroll to the active tab in the tabs container
                scrollToActiveTab() {
                    const container = this.$refs.screenTabsContainer;
                    if (!container) return;
                    const activeTab = container.querySelector(`button[class*="bg-gray-700"]`);
                    if (activeTab) {
                        activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    }
                },

                // Drag-and-drop handlers for screen tab reordering
                handleDragStart(event, screenId) {
                    this.draggedScreenId = screenId;
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', screenId);
                    // Add a slight delay to allow the drag image to form before adding styling
                    setTimeout(() => {
                        if (event.target) {
                            event.target.closest('[data-screen-tab]')?.classList.add('opacity-50');
                        }
                    }, 0);
                },

                handleDragOver(event, screenId) {
                    if (!this.draggedScreenId || this.draggedScreenId === screenId) {
                        this.dragOverScreenId = null;
                        this.dragDropPosition = null;
                        return;
                    }

                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';

                    // Determine if dropping before or after based on mouse position
                    const rect = event.currentTarget.getBoundingClientRect();
                    const midpoint = rect.left + rect.width / 2;
                    this.dragOverScreenId = screenId;
                    this.dragDropPosition = event.clientX < midpoint ? 'before' : 'after';
                },

                handleDragLeave(event) {
                    // Only clear if we're leaving the tab entirely (not entering a child)
                    if (!event.currentTarget.contains(event.relatedTarget)) {
                        this.dragOverScreenId = null;
                        this.dragDropPosition = null;
                    }
                },

                handleDrop(event, targetScreenId) {
                    event.preventDefault();

                    if (!this.draggedScreenId || this.draggedScreenId === targetScreenId) {
                        this.resetDragState();
                        return;
                    }

                    const currentOrder = [...(this.currentSession?.screen_order || [])];
                    const draggedIndex = currentOrder.indexOf(this.draggedScreenId);
                    const targetIndex = currentOrder.indexOf(targetScreenId);

                    if (draggedIndex === -1 || targetIndex === -1) {
                        this.resetDragState();
                        return;
                    }

                    // Remove dragged item from current position
                    currentOrder.splice(draggedIndex, 1);

                    // Calculate new position
                    let newIndex = currentOrder.indexOf(targetScreenId);
                    if (this.dragDropPosition === 'after') {
                        newIndex += 1;
                    }

                    // Insert at new position
                    currentOrder.splice(newIndex, 0, this.draggedScreenId);

                    // Reorder screens
                    this.reorderScreens(currentOrder);
                    this.resetDragState();
                },

                handleDragEnd(event) {
                    // Remove styling from dragged element
                    event.target.closest('[data-screen-tab]')?.classList.remove('opacity-50');
                    this.resetDragState();
                },

                resetDragState() {
                    this.draggedScreenId = null;
                    this.dragOverScreenId = null;
                    this.dragDropPosition = null;
                },

                // Persist screen order to server
                async reorderScreens(newOrder) {
                    if (!this.currentSession) return;

                    // Update local state immediately for responsiveness
                    this.currentSession.screen_order = newOrder;

                    try {
                        // Persist to server
                        const response = await fetch(`/api/sessions/${this.currentSession.id}/screens/reorder`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ screen_order: newOrder })
                        });

                        if (!response.ok) {
                            throw new Error('Failed to reorder screens');
                        }
                    } catch (err) {
                        console.error('Failed to reorder screens:', err);
                        // Optionally: revert local state or show error
                        this.showError('Failed to save screen order');
                    }
                },

                // Fetch available panels
                async fetchAvailablePanels() {
                    try {
                        const response = await fetch('/api/panels');
                        if (response.ok) {
                            this.availablePanels = await response.json();
                        }
                    } catch (err) {
                        console.error('Failed to fetch panels:', err);
                    }
                },

                // Save session layout as workspace default template
                async saveSessionAsDefault(session) {
                    if (!session?.id) return;

                    try {
                        const response = await fetch(`/api/sessions/${session.id}/save-as-default`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                        });

                        if (!response.ok) throw new Error('Failed to save as default');

                        const data = await response.json();
                        this.workspaceHasDefaultTemplate = true;
                        this.showToast('Session layout saved as default template');
                        this.sessionMenuId = null;
                    } catch (err) {
                        console.error('Failed to save session as default:', err);
                        this.showError('Failed to save session as default template');
                    }
                },

                // Clear the workspace default session template
                async clearDefaultTemplate() {
                    if (!this.currentSession?.id) return;

                    try {
                        const response = await fetch(`/api/sessions/${this.currentSession.id}/clear-default`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                        });

                        if (!response.ok) throw new Error('Failed to clear default');

                        this.workspaceHasDefaultTemplate = false;
                        this.showToast('Default template cleared');
                        this.sessionMenuId = null;
                    } catch (err) {
                        console.error('Failed to clear default template:', err);
                        this.showError('Failed to clear default template');
                    }
                },

                // Show a toast notification
                showToast(message) {
                    this.toastMessage = message;
                    this.toastVisible = true;
                    setTimeout(() => {
                        this.toastVisible = false;
                    }, 3000);
                },

                // Find the next session by position (below first, then above as fallback)
                // Used when archiving/deleting to select the session "below" in the sidebar
                findNextSessionByPosition(excludeSessionId, options = {}) {
                    const { excludeArchived = true, sessionsArray = null } = options;
                    const sessions = sessionsArray || this.filteredSessions;

                    // Find current index
                    const currentIndex = sessions.findIndex(s => s.id === excludeSessionId);
                    if (currentIndex === -1) return null;

                    // Look below first (older sessions - higher index since sorted by updated_at DESC)
                    for (let i = currentIndex + 1; i < sessions.length; i++) {
                        const s = sessions[i];
                        if (excludeArchived && s.is_archived) continue;
                        return s;
                    }

                    // Fallback: look above (newer sessions - lower index)
                    for (let i = currentIndex - 1; i >= 0; i--) {
                        const s = sessions[i];
                        if (s.id === excludeSessionId) continue;
                        if (excludeArchived && s.is_archived) continue;
                        return s;
                    }

                    return null;
                },

                // Archive a session
                async archiveSession(sessionId) {
                    if (!sessionId) return;

                    try {
                        const response = await fetch(`/api/sessions/${sessionId}/archive`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                        });

                        if (!response.ok) throw new Error('Failed to archive session');

                        // Find next session BEFORE modifying array (to preserve position calculation)
                        const wasCurrentSession = this.currentSession?.id === sessionId;
                        const nextSession = wasCurrentSession
                            ? this.findNextSessionByPosition(sessionId, { excludeArchived: true })
                            : null;

                        // Update local state - modify sessions array (filteredSessions is a computed getter)
                        const session = this.sessions.find(s => s.id === sessionId);
                        if (session) {
                            session.is_archived = true;
                        }

                        // If not showing archived, remove from list
                        if (!this.showArchivedSessions) {
                            this.sessions = this.sessions.filter(s => s.id !== sessionId);
                        }

                        // If this was the current session, load the next one below (older) or create new
                        if (wasCurrentSession) {
                            if (nextSession) {
                                await this.loadSession(nextSession.id);
                            } else {
                                await this.newSession();
                            }
                        }

                        this.showToast('Session archived');
                        this.sessionMenuId = null;
                    } catch (err) {
                        console.error('Failed to archive session:', err);
                        this.showError('Failed to archive session');
                    }
                },

                // Restore a session from archive
                async restoreSession(sessionId) {
                    if (!sessionId) return;

                    try {
                        const response = await fetch(`/api/sessions/${sessionId}/restore`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                        });

                        if (!response.ok) throw new Error('Failed to restore session');

                        // Update local state - modify sessions array (filteredSessions is a computed getter)
                        const session = this.sessions.find(s => s.id === sessionId);
                        if (session) {
                            session.is_archived = false;
                        }

                        this.showToast('Session restored');
                        this.sessionMenuId = null;
                    } catch (err) {
                        console.error('Failed to restore session:', err);
                        this.showError('Failed to restore session');
                    }
                },

                // Delete a session (with confirmation)
                async deleteSession(sessionId) {
                    if (!sessionId) return;

                    const session = this.sessions.find(s => s.id === sessionId);
                    const sessionName = session?.name || 'this session';

                    if (!confirm(`Delete "${sessionName}"? This will permanently remove the session and all its tabs. Conversations inside will be archived, not deleted.`)) {
                        this.sessionMenuId = null;
                        return;
                    }

                    try {
                        const response = await fetch(`/api/sessions/${sessionId}`, {
                            method: 'DELETE',
                            headers: { 'Content-Type': 'application/json' },
                        });

                        if (!response.ok) throw new Error('Failed to delete session');

                        // Find next session BEFORE removing (to preserve position calculation)
                        const wasCurrentSession = this.currentSession?.id === sessionId;
                        const nextSession = wasCurrentSession
                            ? this.findNextSessionByPosition(sessionId, { excludeArchived: true })
                            : null;

                        // Remove from local list - modify sessions array (filteredSessions is a computed getter)
                        this.sessions = this.sessions.filter(s => s.id !== sessionId);

                        // If this was the current session, load the next one below (older) or create new
                        if (wasCurrentSession) {
                            if (nextSession) {
                                await this.loadSession(nextSession.id);
                            } else {
                                await this.newSession();
                            }
                        }

                        this.showToast('Session deleted');
                        this.sessionMenuId = null;
                    } catch (err) {
                        console.error('Failed to delete session:', err);
                        this.showError('Failed to delete session');
                    }
                },

                // Open session context menu
                openSessionMenu(event, session) {
                    event.stopPropagation();
                    const rect = event.currentTarget.getBoundingClientRect();
                    this.sessionMenuPos = {
                        top: rect.bottom + 4,
                        left: rect.left,
                        right: rect.right
                    };
                    this.sessionMenuId = session.id;
                },

                // Close session context menu
                closeSessionMenu() {
                    this.sessionMenuId = null;
                },

                // Open restore chat modal and fetch archived conversations
                async openRestoreChatModal() {
                    if (!this.currentSession?.id) return;

                    this.showRestoreChatModal = true;
                    this.loadingArchivedConversations = true;
                    this.archivedConversations = [];

                    try {
                        const response = await fetch(`/api/sessions/${this.currentSession.id}/archived-conversations`);
                        if (!response.ok) throw new Error('Failed to fetch archived conversations');

                        const data = await response.json();
                        this.archivedConversations = data.conversations || [];
                    } catch (err) {
                        console.error('Failed to fetch archived conversations:', err);
                        this.showError('Failed to load archived conversations');
                    } finally {
                        this.loadingArchivedConversations = false;
                    }
                },

                // Close restore chat modal
                closeRestoreChatModal() {
                    this.showRestoreChatModal = false;
                    this.archivedConversations = [];
                },

                // Restore an archived conversation (unarchive it)
                async restoreArchivedConversation(conversationId) {
                    if (!conversationId) return;

                    try {
                        const response = await fetch(`/api/conversations/${conversationId}/unarchive`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}`);
                        }

                        // Remove from archived list
                        this.archivedConversations = this.archivedConversations.filter(c => c.id !== conversationId);

                        // Reload the session to show the restored conversation in tabs
                        await this.loadSession(this.currentSession.id);

                        this.showToast('Conversation restored');

                        // Close modal if no more archived conversations
                        if (this.archivedConversations.length === 0) {
                            this.closeRestoreChatModal();
                        }
                    } catch (err) {
                        console.error('Failed to restore conversation:', err);
                        this.showError('Failed to restore conversation');
                    }
                },

                // Check if current session has archived conversations (for showing/hiding menu item)
                get hasArchivedConversations() {
                    // This is populated when session is loaded - check screens for archived conversations
                    return this.screens.some(s => s.type === 'chat' && s.conversation?.status === 'archived');
                },

                // ===== Panel State Sync =====

                // Store for debounce timers per panel
                _panelSyncTimers: {},

                /**
                 * Sync panel state to server with debouncing.
                 * Call this from panel templates when state changes.
                 *
                 * Usage in panel Blade template:
                 *   <div x-data="{ expanded: [], ... }"
                 *        x-effect="$root.syncPanelState('@{{ $panelState->id }}', { expanded })">
                 *
                 * @param {string} panelStateId - The UUID of the panel state
                 * @param {object} state - The state object to sync
                 * @param {boolean} merge - If true, merge with existing state; otherwise replace
                 */
                syncPanelState(panelStateId, state, merge = true) {
                    // Clear any existing timer for this panel
                    if (this._panelSyncTimers[panelStateId]) {
                        clearTimeout(this._panelSyncTimers[panelStateId]);
                    }

                    // Set a new debounced sync (500ms delay)
                    this._panelSyncTimers[panelStateId] = setTimeout(async () => {
                        try {
                            const response = await fetch(`/api/panel/${panelStateId}/state`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ state, merge })
                            });

                            if (!response.ok) {
                                console.warn('Failed to sync panel state:', response.status);
                            }
                        } catch (err) {
                            console.error('Error syncing panel state:', err);
                        }
                    }, 500);
                },

                /**
                 * Immediately sync panel state (no debounce).
                 * Use for critical state changes like before navigation.
                 */
                async syncPanelStateImmediate(panelStateId, state, merge = true) {
                    // Clear any pending debounced sync
                    if (this._panelSyncTimers[panelStateId]) {
                        clearTimeout(this._panelSyncTimers[panelStateId]);
                        delete this._panelSyncTimers[panelStateId];
                    }

                    try {
                        const response = await fetch(`/api/panel/${panelStateId}/state`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ state, merge })
                        });

                        if (!response.ok) {
                            console.warn('Failed to sync panel state:', response.status);
                        }
                    } catch (err) {
                        console.error('Error syncing panel state:', err);
                    }
                },

                // ===== End Sessions & Screens =====

                // Check for active stream and reconnect if found
                async checkAndReconnectStream(uuid) {
                    // Don't reconnect if we just finished streaming (prevents duplicate events)
                    if (this._justCompletedStream) {
                        return;
                    }

                    // Don't reconnect if existing connection is healthy
                    // (prevents nonce superseding a working connection -- Issue #163 root cause #1)
                    // Bypass guard on tab return: health checks skip while hidden, so
                    // _connectionHealthy may be stale. Always probe the server after tab return.
                    if (this.isStreaming && this.streamAbortController && !this.streamAbortController.signal.aborted && this._connectionHealthy && !this._wasStreamingBeforeHidden) {
                        console.log('[Stream] Skipping reconnect - existing connection appears healthy');
                        return;
                    }

                    try {
                        const response = await fetch(`/api/conversations/${uuid}/stream-status`);
                        const data = await response.json();

                        if (data.is_streaming) {
                            // Detect: page refresh vs timeout/tab-switch reconnect
                            // Page refresh: messages array has no streaming content (only DB messages)
                            // Timeout/tab reconnect: messages array still has streaming content in memory
                            const isPageRefresh = !this._hasActiveStreamingContent();

                            // Guard: distinguish actual page refresh from tab-switch return.
                            // Tab-switch: we had streaming content before the tab went to background,
                            // now _hasActiveStreamingContent() returns false because Alpine state
                            // was preserved but we're checking it during the same session.
                            // Key insight: on actual page refresh, sessionStorage will have stream state
                            // but the in-memory messages array will NOT have streaming content IDs.
                            // On tab return, the messages array still has the streaming content.
                            const isTabReturn = this._wasStreamingBeforeHidden;

                            let fromIndex;
                            if (isPageRefresh && !isTabReturn) {
                                // PAGE REFRESH: Fresh page load during active stream
                                // Reset state and replay ALL events from 0 to rebuild UI
                                this._resetStreamStateForReplay();
                                this._isReplaying = true; // Prevent screen_created events from triggering refreshes during replay
                                fromIndex = 0;

                                // Strip DB-loaded messages from the current streaming run to prevent
                                // duplication. The SSE replay from index 0 will reconstruct them.
                                // Without this, completed turns saved to DB appear TWICE: once from
                                // the DB load and again from the SSE replay.
                                this._stripCurrentStreamMessages();

                                console.log('[Stream] Page refresh reconnect - replaying all events from 0');
                            } else {
                                // TIMEOUT RECONNECT or TAB RETURN: Same session, connection dropped or tab switched
                                // Restore state and continue from last index
                                this._restoreStreamState(uuid);
                                const savedIndex = sessionStorage.getItem(`stream_index_${uuid}`);
                                fromIndex = savedIndex ? parseInt(savedIndex, 10) : this.lastEventIndex;
                                console.log('[Stream] Timeout/tab reconnect:', { uuid, fromIndex, isTabReturn, eventCount: data.event_count });
                            }

                            // Seed in-memory tracker so timeout retries use the correct index
                            this.lastEventIndex = fromIndex;

                            // Connect to stream events
                            await this.connectToStreamEvents(fromIndex);
                        } else {
                            // Stream is not active, clear any stale sessionStorage
                            this._clearStreamStorage(uuid);
                        }
                    } catch (err) {
                        // No active stream, that's fine
                        console.debug('[Stream] No active stream for reconnection:', err.message);
                    }
                },

                // Save stream state to sessionStorage for persistence across refresh
                _saveStreamState(uuid) {
                    if (!uuid) return;
                    try {
                        const state = {
                            thinkingBlocks: Object.fromEntries(
                                Object.entries(this._streamState.thinkingBlocks).map(([k, v]) => [k, {
                                    msgIndex: v.msgIndex,
                                    content: v.content,
                                    complete: v.complete
                                }])
                            ),
                            currentThinkingBlock: this._streamState.currentThinkingBlock,
                            textMsgIndex: this._streamState.textMsgIndex,
                            toolMsgIndex: this._streamState.toolMsgIndex,
                            textContent: this._streamState.textContent,
                            // Tool state for mid-tool refresh recovery
                            toolInput: this._streamState.toolInput,
                            toolInProgress: this._streamState.toolInProgress,
                            waitingForToolResults: Array.from(this._streamState.waitingForToolResults || []), // Set → Array for JSON
                            abortPending: this._streamState.abortPending,
                            abortSkipSync: this._streamState.abortSkipSync,
                            // Per-turn cost/token counters for usage display
                            turnCost: this._streamState.turnCost,
                            turnInputTokens: this._streamState.turnInputTokens,
                            turnOutputTokens: this._streamState.turnOutputTokens,
                            turnCacheCreationTokens: this._streamState.turnCacheCreationTokens,
                            turnCacheReadTokens: this._streamState.turnCacheReadTokens,
                            startedAt: this._streamState.startedAt,
                        };
                        sessionStorage.setItem(`stream_state_${uuid}`, JSON.stringify(state));
                    } catch (e) {
                        console.warn('[Stream] Failed to save stream state:', e);
                    }
                },

                // Restore stream state from sessionStorage
                _restoreStreamState(uuid) {
                    if (!uuid) return;
                    try {
                        const saved = sessionStorage.getItem(`stream_state_${uuid}`);
                        if (saved) {
                            const state = JSON.parse(saved);
                            // Restore relevant state properties
                            if (state.thinkingBlocks) {
                                this._streamState.thinkingBlocks = state.thinkingBlocks;
                            }
                            if (state.currentThinkingBlock !== undefined) {
                                this._streamState.currentThinkingBlock = state.currentThinkingBlock;
                            }
                            if (state.textMsgIndex !== undefined) {
                                this._streamState.textMsgIndex = state.textMsgIndex;
                            }
                            if (state.toolMsgIndex !== undefined) {
                                this._streamState.toolMsgIndex = state.toolMsgIndex;
                            }
                            if (state.textContent !== undefined) {
                                this._streamState.textContent = state.textContent;
                            }
                            // Restore tool state for mid-tool refresh recovery
                            if (state.toolInput !== undefined) {
                                this._streamState.toolInput = state.toolInput;
                            }
                            if (state.toolInProgress !== undefined) {
                                this._streamState.toolInProgress = state.toolInProgress;
                            }
                            if (Array.isArray(state.waitingForToolResults)) {
                                this._streamState.waitingForToolResults = new Set(state.waitingForToolResults);
                            }
                            if (state.abortPending !== undefined) {
                                this._streamState.abortPending = state.abortPending;
                            }
                            if (state.abortSkipSync !== undefined) {
                                this._streamState.abortSkipSync = state.abortSkipSync;
                            }
                            // Restore per-turn cost/token counters
                            if (state.turnCost !== undefined) {
                                this._streamState.turnCost = state.turnCost;
                            }
                            if (state.turnInputTokens !== undefined) {
                                this._streamState.turnInputTokens = state.turnInputTokens;
                            }
                            if (state.turnOutputTokens !== undefined) {
                                this._streamState.turnOutputTokens = state.turnOutputTokens;
                            }
                            if (state.turnCacheCreationTokens !== undefined) {
                                this._streamState.turnCacheCreationTokens = state.turnCacheCreationTokens;
                            }
                            if (state.turnCacheReadTokens !== undefined) {
                                this._streamState.turnCacheReadTokens = state.turnCacheReadTokens;
                            }
                            if (state.startedAt) {
                                this._streamState.startedAt = state.startedAt;
                            }
                            console.log('[Stream] Restored stream state from sessionStorage:', state);
                        }
                    } catch (e) {
                        console.warn('[Stream] Failed to restore stream state:', e);
                    }
                },

                // Clear stream-related sessionStorage for a conversation
                _clearStreamStorage(uuid) {
                    if (!uuid) return;
                    try {
                        sessionStorage.removeItem(`stream_index_${uuid}`);
                        sessionStorage.removeItem(`stream_state_${uuid}`);
                    } catch (e) {
                        // sessionStorage might not be available
                    }
                },

                // Check if messages array has content from active streaming
                // Used to distinguish page refresh (no streaming content) from timeout reconnect (has streaming content)
                _hasActiveStreamingContent() {
                    // Streaming messages have client-generated IDs: msg-{timestamp}-thinking, msg-{timestamp}-text, msg-{timestamp}-tool
                    // DB-loaded messages have different ID patterns (numeric or UUID from database)
                    return this.messages.some(msg => {
                        if (msg.id && typeof msg.id === 'string') {
                            return /^msg-\d+-(thinking|text|tool)/.test(msg.id);
                        }
                        return false;
                    });
                },

                // Reset stream state for clean replay on page refresh
                // Used when replaying all events from index 0 after a page refresh
                _resetStreamStateForReplay() {
                    // Preserve startedAt for accurate elapsed time display
                    // On page refresh, memory is fresh (no startedAt), so read from sessionStorage
                    const savedStartedAt = this._streamState.startedAt ?? (() => {
                        if (!this.currentConversationUuid) return null;
                        try {
                            const saved = sessionStorage.getItem(`stream_state_${this.currentConversationUuid}`);
                            return saved ? JSON.parse(saved).startedAt : null;
                        } catch (_) {
                            return null;
                        }
                    })();

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
                        startedAt: savedStartedAt,
                    };

                    // Reset event tracking for fresh replay
                    this.lastEventIndex = 0;
                    this.lastEventId = null;
                },

                // Strip messages created by the current streaming job from DB-loaded messages.
                // On page refresh, the DB may contain completed turns from the active stream
                // (the job saves each turn's assistant message + tool results progressively).
                // Since we replay ALL SSE events from index 0, these DB messages would be
                // duplicated. We remove them so only the SSE replay populates them.
                //
                // Strategy: Find the last user message with plain string content (the user's
                // typed prompt). Everything after it was created by the streaming job.
                // This works because:
                // - User typed prompts are saved as strings via saveUserMessage()
                // - Tool result "user" messages have array content ([{type: 'tool_result', ...}])
                // - convertDbMessageToUi preserves these content types
                _stripCurrentStreamMessages() {
                    let lastUserPromptIndex = -1;
                    for (let i = this.messages.length - 1; i >= 0; i--) {
                        const msg = this.messages[i];
                        if (msg.role === 'user' && typeof msg.content === 'string') {
                            lastUserPromptIndex = i;
                            break;
                        }
                    }

                    if (lastUserPromptIndex >= 0 && lastUserPromptIndex < this.messages.length - 1) {
                        // Collect messages to strip and subtract their token/cost contributions.
                        // We subtract rather than recounting from this.messages because older messages
                        // may still be asynchronously prepending (from loadMessagesProgressively).
                        // The initial counters (set in loadConversation/loadConversationForScreen)
                        // already include ALL DB messages, so subtracting the stripped ones is correct.
                        const strippedMessages = this.messages.slice(lastUserPromptIndex + 1);
                        const removedCount = strippedMessages.length;
                        this.messages.splice(lastUserPromptIndex + 1);
                        console.log('[Stream] Stripped', removedCount, 'DB messages after user prompt for SSE replay');

                        // Subtract token/cost contributions of stripped messages to prevent
                        // double-counting when SSE replay sends usage events for these turns.
                        for (const msg of strippedMessages) {
                            if (msg.inputTokens) this.inputTokens -= msg.inputTokens;
                            if (msg.outputTokens) this.outputTokens -= msg.outputTokens;
                            if (msg.cacheCreationTokens) this.cacheCreationTokens -= msg.cacheCreationTokens;
                            if (msg.cacheReadTokens) this.cacheReadTokens -= msg.cacheReadTokens;
                            if (msg.cost) this.sessionCost -= msg.cost;
                        }
                        // Floor at zero to prevent negative display values from timing mismatches
                        this.inputTokens = Math.max(0, this.inputTokens);
                        this.outputTokens = Math.max(0, this.outputTokens);
                        this.cacheCreationTokens = Math.max(0, this.cacheCreationTokens);
                        this.cacheReadTokens = Math.max(0, this.cacheReadTokens);
                        this.sessionCost = Math.max(0, this.sessionCost);
                        this.totalTokens = this.inputTokens + this.outputTokens;
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

                    // Handle compaction messages specially
                    if (dbMsg.role === 'compaction') {
                        const preTokens = content?.pre_tokens;
                        const preTokensDisplay = preTokens != null ? preTokens.toLocaleString() : 'unknown';
                        result.push({
                            id: 'msg-' + Date.now() + '-' + Math.random(),
                            role: 'compaction',
                            content: content?.summary || '',
                            preTokens: preTokens,
                            preTokensDisplay: preTokensDisplay,
                            trigger: content?.trigger ?? 'auto',
                            timestamp: dbMsg.created_at,
                            collapsed: true,
                            turn_number: dbMsg.turn_number
                        });
                        return result;
                    }

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

                    // Handle compaction messages specially
                    if (dbMsg.role === 'compaction') {
                        const preTokens = content?.pre_tokens;
                        const preTokensDisplay = preTokens != null ? preTokens.toLocaleString() : 'unknown';
                        this.messages.push({
                            id: 'msg-' + Date.now() + '-' + Math.random(),
                            role: 'compaction',
                            content: content?.summary || '',
                            preTokens: preTokens,
                            preTokensDisplay: preTokensDisplay,
                            trigger: content?.trigger ?? 'auto',
                            timestamp: dbMsg.created_at,
                            collapsed: true,
                            turn_number: dbMsg.turn_number
                        });
                        return;
                    }

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

                    // Block sending while conversation is being loaded (prevents race conditions)
                    if (this.loadingConversation) {
                        this.showError('Please wait for the conversation to load');
                        return;
                    }

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

                            // Load session and screens if returned from the API
                            if (data.conversation?.screen?.session) {
                                console.log('[DEBUG] New conversation has session:', data.conversation.screen.session.id);
                                await this.loadSessionFromConversation(data.conversation.screen.session);
                                this.activeScreenId = data.conversation.screen.id;
                                // Refresh sessions list to include the new session
                                await this.fetchSessions();
                                // Update URL with new session ID
                                this.updateSessionUrl(data.conversation.screen.session.id);
                            }
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
                    // Stream phase is set in connectToStreamEvents() after disconnectFromStream()
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
                async connectToStreamEvents(fromIndex = 0, startupRetryCount = 0, networkRetryCount = 0) {
                    if (!this.currentConversationUuid) {
                        return;
                    }

                    // Max retries for not_found status (job hasn't started yet)
                    const maxStartupRetries = 15; // 15 * 200ms = 3 seconds max wait
                    // Max retries for network errors (exponential backoff)
                    const maxNetworkRetries = 5;

                    // Abort any existing connection
                    this.disconnectFromStream();

                    // Increment nonce to invalidate any pending reconnection attempts
                    const myNonce = ++this._streamConnectNonce;

                    // Debug logging for connection state tracking
                    console.log('[Stream] connectToStreamEvents:', {
                        uuid: this.currentConversationUuid,
                        fromIndex,
                        startupRetryCount,
                        networkRetryCount,
                        nonce: myNonce,
                        previousNonce: myNonce - 1,
                        lastEventIndex: this.lastEventIndex,
                        lastEventId: this.lastEventId,
                    });

                    this.isStreaming = true;
                    this.currentConversationStatus = 'processing'; // Update status badge

                    // Restore stream phase after disconnectFromStream() reset it.
                    // 'waiting' = waiting for first response (triggers amber dot after 15s)
                    if (this._streamPhase === 'idle') {
                        this._streamPhase = 'waiting';
                        this._phaseChangedAt = Date.now();
                        this._pendingResponseWarning = false;
                    }

                    // Start connection health check (dead man's switch)
                    this._lastKeepaliveAt = Date.now();
                    this._connectionHealthy = true;
                    if (this._keepaliveCheckInterval) clearInterval(this._keepaliveCheckInterval);
                    this._keepaliveCheckInterval = setInterval(() => {
                        // Skip health check when tab is hidden (browser throttles SSE
                        // processing in background tabs, causing false amber indicators)
                        if (document.hidden) return;

                        // Connection health: no keepalive for >45s
                        if (this._lastKeepaliveAt && Date.now() - this._lastKeepaliveAt > 45000) {
                            this._connectionHealthy = false;
                        }
                        // Pending response warning: waiting >15s after tool results (likely compacting)
                        if (this._streamPhase === 'waiting' && this._phaseChangedAt &&
                            Date.now() - this._phaseChangedAt > 15000) {
                            this._pendingResponseWarning = true;
                        } else {
                            this._pendingResponseWarning = false;
                        }
                    }, 5000);

                    // Reset keepalive timer when tab becomes visible again to prevent
                    // false amber indicator (browser doesn't process SSE while hidden)
                    this._visibilityHandler = () => {
                        if (!document.hidden && this._lastKeepaliveAt) {
                            this._lastKeepaliveAt = Date.now();
                            this._connectionHealthy = true;
                        }
                    };
                    document.addEventListener('visibilitychange', this._visibilityHandler);

                    this.streamAbortController = new AbortController();
                    let pendingRetry = false;

                    try {
                        const url = `/api/conversations/${this.currentConversationUuid}/stream-events?from_index=${fromIndex}`;
                        const response = await fetch(url, { signal: this.streamAbortController.signal });

                        // Check if we've been superseded by a newer connection attempt
                        if (myNonce !== this._streamConnectNonce) {
                            console.log('[Stream] Connection superseded by newer attempt:', {
                                myNonce,
                                currentNonce: this._streamConnectNonce,
                            });
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

                                    // Track event index and ID for reconnection (persist to sessionStorage for refresh survival)
                                    if (event.index !== undefined) {
                                        this.lastEventIndex = event.index + 1;
                                        try {
                                            sessionStorage.setItem(`stream_index_${this.currentConversationUuid}`, this.lastEventIndex);
                                        } catch (e) {
                                            // sessionStorage might not be available
                                        }
                                    }
                                    // Track unique event ID for verification
                                    if (event.event_id) {
                                        this.lastEventId = event.event_id;
                                    }

                                    // Handle stream status events
                                    if (event.type === 'stream_status') {
                                        if (event.status === 'not_found') {
                                            // Race condition: job hasn't started yet, retry
                                            if (startupRetryCount < maxStartupRetries) {
                                                // Check nonce before scheduling retry
                                                if (myNonce === this._streamConnectNonce) {
                                                    pendingRetry = true;
                                                    this._streamRetryTimeoutId = setTimeout(() => this.connectToStreamEvents(0, startupRetryCount + 1, 0), 200);
                                                }
                                                return;
                                            } else {
                                                this.isStreaming = false;
                                                this.currentConversationStatus = 'failed'; // Update status badge
                                                this.showError('Failed to connect to stream');
                                                return;
                                            }
                                        }
                                        if (event.status === 'completed' || event.status === 'failed') {
                                            this.isStreaming = false;
                                            this._isReplaying = false; // Clear replay flag
                                            // Update status badge
                                            this.currentConversationStatus = event.status === 'failed' ? 'failed' : 'idle';
                                            // Prevent reconnection for a short period
                                            this._justCompletedStream = true;
                                            // Clear sessionStorage - stream is done, no longer need reconnection state
                                            this._clearStreamStorage(this.currentConversationUuid);
                                            // Clear flag after a delay (increased from 1s to 3s for safety)
                                            setTimeout(() => { this._justCompletedStream = false; }, 3000);
                                        }
                                        continue;
                                    }

                                    if (event.type === 'timeout') {
                                        // Reconnect from last known position (fresh connection, reset counters)
                                        // Check nonce before scheduling retry
                                        if (myNonce === this._streamConnectNonce) {
                                            pendingRetry = true;
                                            this._streamRetryTimeoutId = setTimeout(() => this.connectToStreamEvents(this.lastEventIndex, 0, 0), 100);
                                        }
                                        return;
                                    }

                                    // Track keepalive for connection health (don't pass to handleStreamEvent)
                                    if (event.type === 'keepalive') {
                                        this._lastKeepaliveAt = Date.now();
                                        this._connectionHealthy = true;
                                        continue;
                                    }

                                    // Handle regular stream events
                                    // Also update keepalive timer on any data event
                                    this._lastKeepaliveAt = Date.now();
                                    this._connectionHealthy = true;
                                    this.handleStreamEvent(event);

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
                            // Network error retry with exponential backoff
                            // Only retry if this connection hasn't been superseded
                            if (networkRetryCount < maxNetworkRetries && myNonce === this._streamConnectNonce) {
                                const delay = Math.min(1000 * Math.pow(2, networkRetryCount), 8000);
                                console.log(`[Stream] Network error, retrying in ${delay}ms (attempt ${networkRetryCount + 1}/${maxNetworkRetries})`);
                                this._streamRetryTimeoutId = setTimeout(
                                    () => this.connectToStreamEvents(this.lastEventIndex, 0, networkRetryCount + 1),
                                    delay
                                );
                                pendingRetry = true;
                                return; // Don't set isStreaming = false -- we're retrying
                            }
                        }
                    } finally {
                        // Only set isStreaming false if:
                        // - no pending retry scheduled
                        // - we weren't aborted for reconnection
                        // - this connection hasn't been superseded by a newer one (nonce check)
                        if (!pendingRetry && !this.streamAbortController?.signal.aborted && myNonce === this._streamConnectNonce) {
                            this.isStreaming = false;

                            // Clean up keepalive health check to prevent stale intervals
                            if (this._keepaliveCheckInterval) {
                                clearInterval(this._keepaliveCheckInterval);
                                this._keepaliveCheckInterval = null;
                            }
                            if (this._visibilityHandler) {
                                document.removeEventListener('visibilitychange', this._visibilityHandler);
                                this._visibilityHandler = null;
                            }
                            this._connectionHealthy = true;
                            this._lastKeepaliveAt = null;
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

                    // Stop keepalive health check
                    if (this._keepaliveCheckInterval) {
                        clearInterval(this._keepaliveCheckInterval);
                        this._keepaliveCheckInterval = null;
                    }
                    if (this._visibilityHandler) {
                        document.removeEventListener('visibilitychange', this._visibilityHandler);
                        this._visibilityHandler = null;
                    }
                    this._connectionHealthy = true;
                    this._lastKeepaliveAt = null;
                    // Reset stream phase tracking
                    this._streamPhase = 'idle';
                    this._phaseChangedAt = null;
                    this._pendingResponseWarning = false;

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
                            // Phase: response content arriving
                            this._streamPhase = 'streaming';
                            this._phaseChangedAt = Date.now();
                            this._pendingResponseWarning = false;
                            // Support interleaved thinking - track by block_index
                            const thinkingBlockIndex = event.block_index ?? 0;
                            state.currentThinkingBlock = thinkingBlockIndex;
                            state.thinkingBlocks[thinkingBlockIndex] = {
                                msgIndex: this.messages.length,
                                content: '',
                                complete: false
                            };
                            this.messages.push({
                                id: 'msg-' + Date.now() + '-thinking-' + thinkingBlockIndex + '-' + Math.random(),
                                role: 'thinking',
                                content: '',
                                timestamp: state.startedAt || new Date().toISOString(),
                                collapsed: false
                            });
                            this.scrollToBottom();
                            // Persist stream state for reconnection
                            this._saveStreamState(this.currentConversationUuid);
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
                                    // Persist accumulated content for reconnection
                                    this._saveStreamState(this.currentConversationUuid);
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
                            // Phase: response content arriving
                            this._streamPhase = 'streaming';
                            this._phaseChangedAt = Date.now();
                            this._pendingResponseWarning = false;
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
                                id: 'msg-' + Date.now() + '-text-' + Math.random(),
                                role: 'assistant',
                                content: '',
                                timestamp: state.startedAt || new Date().toISOString(),
                                collapsed: false
                            });
                            this.scrollToBottom();
                            // Persist stream state for reconnection
                            this._saveStreamState(this.currentConversationUuid);
                            break;

                        case 'text_delta':
                            if (state.textMsgIndex >= 0 && event.content) {
                                state.textContent += event.content;
                                this.messages[state.textMsgIndex] = {
                                    ...this.messages[state.textMsgIndex],
                                    content: state.textContent
                                };
                                this.scrollToBottom();
                                // Persist accumulated content for reconnection
                                this._saveStreamState(this.currentConversationUuid);
                            }
                            break;

                        case 'text_stop':
                            // Text block complete
                            break;

                        case 'tool_use_start':
                            // Phase: tool executing (stays green)
                            this._streamPhase = 'tool_executing';
                            this._phaseChangedAt = Date.now();
                            this._pendingResponseWarning = false;
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
                                id: 'msg-' + Date.now() + '-tool-' + Math.random(),
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
                            // Persist stream state for reconnection
                            this._saveStreamState(this.currentConversationUuid);
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
                                // Persist accumulated content for reconnection
                                this._saveStreamState(this.currentConversationUuid);
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
                            // Phase: tools done, waiting for next response (may compact)
                            this._streamPhase = 'waiting';
                            this._phaseChangedAt = Date.now();
                            this._pendingResponseWarning = false;
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

                                // CLI PROVIDER PANEL DETECTION (Claude Code, Codex)
                                // ================================================
                                // CLI providers execute tools as subprocesses (php artisan tool:run),
                                // which creates an ExecutionContext WITHOUT streamManager/conversationUuid.
                                // This means ToolRunTool::openPanel() can never emit a screen_created
                                // SSE event for CLI providers — the guard in openPanel() (line ~279)
                                // requires both streamManager and conversationUuid to be set.
                                //
                                // This string-matching fallback is the ONLY mechanism that detects
                                // new panels for CLI providers. Do NOT remove this without first
                                // ensuring screen_created events work for CLI tool execution.
                                //
                                // For API providers (Anthropic API), the screen_created event handler
                                // below handles panel detection — both may fire but refreshSessionScreens()
                                // is idempotent so this is safe.
                                if (!this._isReplaying) {
                                    let panelOutput = event.content;
                                    if (typeof panelOutput === 'string') {
                                        try {
                                            const parsed = JSON.parse(panelOutput);
                                            if (parsed.output) {
                                                panelOutput = parsed.output;
                                            }
                                        } catch (e) {
                                            // Not JSON, use as-is
                                        }
                                    }
                                    const outputStr = typeof panelOutput === 'string' ? panelOutput : '';
                                    if (outputStr.startsWith("Opened panel '")) {
                                        this.refreshSessionScreens();
                                        this.$dispatch('screen-added');
                                    }
                                }
                            } else {
                                console.warn('tool_result event missing tool_id in metadata');
                            }
                            // Persist state after tool result (captures waitingForToolResults change)
                            this._saveStreamState(this.currentConversationUuid);
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
                            // Phase: compaction just completed, waiting for post-compaction response
                            this._streamPhase = 'waiting';
                            this._phaseChangedAt = Date.now();
                            this._pendingResponseWarning = false;
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

                        case 'screen_created':
                            // API PROVIDER PANEL DETECTION (Anthropic API)
                            // =============================================
                            // When API providers execute tools, ProcessConversationStream::executeTools()
                            // creates an ExecutionContext WITH streamManager and conversationUuid.
                            // ToolRunTool::openPanel() emits this screen_created event via SSE.
                            //
                            // NOTE: This event is NEVER emitted for CLI providers (Claude Code, Codex)
                            // because CLI tools run as subprocesses without stream context.
                            // CLI panel detection is handled by string-matching in the tool_result
                            // handler above — do NOT remove that fallback.
                            if (!this._isReplaying) {
                                this.refreshSessionScreens();
                                this.$dispatch('screen-added');
                            }
                            break;

                        case 'done':
                            // Phase: stream complete
                            this._streamPhase = 'idle';
                            this._phaseChangedAt = null;
                            this._pendingResponseWarning = false;
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

                // Connection indicator state: 'hidden' | 'connected' | 'processing' | 'disconnected'
                getIndicatorState() {
                    if (!this.isStreaming) return 'hidden';
                    if (!this._connectionHealthy) return 'disconnected';
                    if (this._pendingResponseWarning) return 'processing';
                    return 'connected';
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

                    // Wrap tables in scrollable container for mobile
                    html = html.replace(/<table>/g, '<div class="table-wrapper"><table>');
                    html = html.replace(/<\/table>/g, '</table></div>');

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
