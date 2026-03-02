<div x-data="{
    panelStateId: {{ json_encode($panelStateId ?? null) }},
    accounts: {{ json_encode(array_map(fn($a) => ['name' => $a['name'], 'email' => $a['email']], $accounts)) }},
    selectedAccount: {{ json_encode($selectedAccount ?? ($accounts[0]['name'] ?? null)) }},

    // Folders
    folders: [],
    selectedFolderId: 'inbox',
    selectedFolderName: 'Inbox',
    foldersLoading: false,
    wellKnownMap: {},  // folder ID → well-known name (inbox, sentitems, etc.)

    // Messages
    messages: [],
    messagesLoading: false,
    totalCount: 0,
    currentSkip: 0,
    pageSize: 25,
    hasMore: false,

    // Search
    searchQuery: '',
    activeSearch: '',
    isSearching: false,

    // Selected message
    selectedMessage: null,
    messageLoading: false,

    // Mobile view
    mobileView: 'list',

    // Compose
    showCompose: false,
    composeMode: null,
    composeTo: [],
    composeCc: [],
    composeBcc: [],
    composeToInput: '',
    composeCcInput: '',
    composeBccInput: '',
    composeSubject: '',
    composeBody: '',
    composeSending: false,
    composeShowCcBcc: false,
    replyToMessageId: null,

    // UI state
    showSidebar: window.innerWidth >= 1024,
    showMobileSearch: false,
    showAttachments: false,
    allowRemoteImages: false,
    actionLoading: {},
    toast: null,
    toastTimeout: null,
    error: null,
    showDeleteConfirm: false,
    deleteTargetId: null,

    // Quill editor
    quillInstance: null,
    composeOriginalHtml: '',
    editorHeight: 0,
    composeDragging: false,

    // Permissions (detected from JWT token roles)
    permissions: [],

    // AbortController for cancellable fetches
    messageAbort: null,
    listAbort: null,

    init() {
        if (!this.selectedAccount && this.accounts.length > 0) {
            this.selectedAccount = this.accounts[0].name;
        }
        if (this.selectedAccount) {
            this.fetchFolders();
            this.fetchMessages();
        }
    },

    // =========================================================================
    // API helper
    // =========================================================================

    async doAction(action, params = {}, signal = null) {
        const opts = {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, params: { account: this.selectedAccount, ...params } }),
        };
        if (signal) opts.signal = signal;
        const response = await fetch(`/api/panel/${this.panelStateId}/action`, opts);
        const result = await response.json();
        if (!result.ok || result.error) {
            throw new Error(result.error || 'Action failed');
        }
        return result.data;
    },

    showToast(message, type = 'success') {
        if (this.toastTimeout) clearTimeout(this.toastTimeout);
        this.toast = { message, type };
        this.toastTimeout = setTimeout(() => { this.toast = null; }, 3000);
    },

    // =========================================================================
    // Account switching
    // =========================================================================

    async switchAccount(name) {
        this.selectedAccount = name;
        this.selectedFolderId = 'inbox';
        this.selectedFolderName = 'Inbox';
        this.selectedMessage = null;
        this.messages = [];
        this.searchQuery = '';
        this.activeSearch = '';
        this.isSearching = false;
        this.mobileView = 'list';
        this.wellKnownMap = {};
        this.permissions = [];
        await Promise.all([this.fetchFolders(), this.fetchMessages()]);
    },

    canWrite() { return this.permissions.some(p => p === 'Mail.ReadWrite' || p === 'Mail.ReadWrite.All'); },
    canSend() { return this.permissions.some(p => p === 'Mail.Send' || p === 'Mail.Send.All'); },

    // =========================================================================
    // Folders
    // =========================================================================

    async fetchFolders() {
        this.foldersLoading = true;
        try {
            const data = await this.doAction('listFolders');
            this.folders = data.folders || [];
            this.wellKnownMap = data.wellKnownMap || {};
            if (data.permissions) this.permissions = data.permissions;

            // The backend resolves the well-known inbox folder ID so we can
            // match it in the sidebar regardless of locale (e.g., Postvak IN).
            if (this.selectedFolderId === 'inbox' && data.inboxId) {
                this.selectedFolderId = data.inboxId;
                const inbox = this.folders.find(f => f.id === data.inboxId);
                if (inbox) this.selectedFolderName = inbox.displayName;
            }
        } catch (e) {
            this.error = 'Failed to load folders: ' + e.message;
        } finally {
            this.foldersLoading = false;
        }
    },

    async selectFolder(folder) {
        this.selectedFolderId = folder.id;
        this.selectedFolderName = folder.displayName;
        this.selectedMessage = null;
        this.messages = [];
        this.currentSkip = 0;
        this.mobileView = 'list';
        await this.fetchMessages();
    },

    getFolderIcon(folder) {
        // Use well-known folder type (locale-independent) for icon mapping
        const wkn = this.wellKnownMap[folder.id];
        const map = {
            'inbox': 'fa-inbox',
            'sentitems': 'fa-paper-plane',
            'drafts': 'fa-file-pen',
            'archive': 'fa-box-archive',
            'deleteditems': 'fa-trash-can',
            'junkemail': 'fa-shield-halved',
        };
        return (wkn && map[wkn]) || 'fa-folder';
    },

    // =========================================================================
    // Messages
    // =========================================================================

    async fetchMessages(append = false) {
        if (this.listAbort) this.listAbort.abort();
        this.listAbort = new AbortController();

        if (!append) {
            this.messagesLoading = true;
            this.currentSkip = 0;
        }

        try {
            const params = {
                folderId: this.selectedFolderId,
                top: this.pageSize,
                skip: this.currentSkip,
            };
            if (this.searchQuery) {
                params.search = this.searchQuery;
            }

            const data = await this.doAction('listMessages', params, this.listAbort.signal);
            const newMessages = data.messages || [];

            if (append) {
                this.messages = [...this.messages, ...newMessages];
            } else {
                this.messages = newMessages;
            }

            this.totalCount = data.totalCount ?? this.messages.length;
            this.hasMore = newMessages.length >= this.pageSize;
        } catch (e) {
            if (e.name === 'AbortError') return;
            this.error = 'Failed to load messages: ' + e.message;
        } finally {
            this.messagesLoading = false;
        }
    },

    async loadMore() {
        this.currentSkip += this.pageSize;
        await this.fetchMessages(true);
    },

    async searchMessages() {
        this.activeSearch = this.searchQuery;
        this.isSearching = !!this.searchQuery;
        this.selectedMessage = null;
        this.messages = [];
        this.currentSkip = 0;
        await this.fetchMessages();
    },

    clearSearch() {
        this.searchQuery = '';
        this.activeSearch = '';
        this.isSearching = false;
        this.selectedMessage = null;
        this.fetchMessages();
    },

    // =========================================================================
    // Message detail
    // =========================================================================

    async selectMessage(msg) {
        if (this.messageAbort) this.messageAbort.abort();
        this.messageAbort = new AbortController();

        this.selectedMessage = msg;
        this.messageLoading = true;
        this.allowRemoteImages = false;
        this.mobileView = 'detail';
        this.showAttachments = false;

        try {
            const data = await this.doAction('getMessage', { messageId: msg.id }, this.messageAbort.signal);
            this.selectedMessage = data.message;

            // Backend already marks as read in handleGetMessage, just update the list UI
            if (!msg.isRead) {
                const idx = this.messages.findIndex(m => m.id === msg.id);
                if (idx !== -1) this.messages[idx].isRead = true;
            }
        } catch (e) {
            if (e.name === 'AbortError') return;
            this.showToast('Failed to load message', 'error');
        } finally {
            this.messageLoading = false;
        }
    },

    getBodyHtml() {
        if (!this.selectedMessage) return '';
        const body = this.selectedMessage.body;
        if (!body) return '';

        if (body.contentType === 'text') {
            return '<pre style=\'white-space:pre-wrap;word-wrap:break-word;font-family:inherit;margin:0;\'>' +
                this.escapeHtml(body.content || '') + '</pre>';
        }

        let html = body.content || '';

        // Inject CSP to block remote images when not explicitly allowed.
        // This prevents tracking pixels and improves privacy.
        // Note: tag strings are split with concatenation to avoid breaking HTML parsing
        // of the x-data attribute (browser would interpret literal <head> as a real tag).
        if (!this.allowRemoteImages && html) {
            const csp = '<' + 'meta http-equiv="Content-Security-Policy" content="img-src data: cid:; style-src \'unsafe-inline\'; default-src \'none\';"' + '>';
            const headOpen = '<' + 'head>';
            const headClose = '<' + '/head>';
            if (html.includes(headOpen)) {
                html = html.replace(headOpen, headOpen + csp);
            } else if (html.includes('<' + 'html')) {
                html = html.replace(/(<html[^>]*>)/, '$1' + headOpen + csp + headClose);
            } else {
                html = csp + html;
            }
        }

        return html;
    },

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    formatSender(msg) {
        if (!msg || !msg.from) return '?';
        const ea = msg.from.emailAddress;
        return ea?.name || ea?.address || '?';
    },

    formatSenderShort(msg) {
        const full = this.formatSender(msg);
        return full.length > 28 ? full.substring(0, 26) + '...' : full;
    },

    formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        const now = new Date();
        const isToday = d.toDateString() === now.toDateString();
        if (isToday) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        const isThisYear = d.getFullYear() === now.getFullYear();
        if (isThisYear) {
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
        }
        return d.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' });
    },

    formatRecipients(recipients) {
        if (!recipients || !recipients.length) return '';
        return recipients.map(r => r.emailAddress?.name || r.emailAddress?.address || '?').join(', ');
    },

    formatRecipientsEmail(recipients) {
        if (!recipients || !recipients.length) return '';
        return recipients.map(r => {
            const name = r.emailAddress?.name;
            const addr = r.emailAddress?.address;
            return name ? `${name} <${addr}>` : addr;
        }).join(', ');
    },

    // =========================================================================
    // Actions
    // =========================================================================

    async archiveMessage(messageId) {
        this.actionLoading[messageId] = true;
        try {
            await this.doAction('archiveMessage', { messageId });
            this.messages = this.messages.filter(m => m.id !== messageId);
            if (this.selectedMessage?.id === messageId) this.selectedMessage = null;
            this.showToast('Archived');
        } catch (e) {
            this.showToast('Archive failed: ' + e.message, 'error');
        } finally {
            delete this.actionLoading[messageId];
        }
    },

    requestDelete(messageId) {
        this.deleteTargetId = messageId;
        this.showDeleteConfirm = true;
    },

    cancelDelete() {
        this.showDeleteConfirm = false;
        this.deleteTargetId = null;
    },

    async confirmDelete() {
        const messageId = this.deleteTargetId;
        if (!messageId) return;
        this.showDeleteConfirm = false;
        this.deleteTargetId = null;
        this.actionLoading[messageId] = true;
        try {
            await this.doAction('deleteMessage', { messageId });
            this.messages = this.messages.filter(m => m.id !== messageId);
            if (this.selectedMessage?.id === messageId) this.selectedMessage = null;
            this.showToast('Deleted');
        } catch (e) {
            this.showToast('Delete failed: ' + e.message, 'error');
        } finally {
            delete this.actionLoading[messageId];
        }
    },

    async toggleRead(messageId) {
        const msg = this.messages.find(m => m.id === messageId);
        if (!msg) return;

        const newRead = !msg.isRead;
        const action = newRead ? 'markRead' : 'markUnread';

        msg.isRead = newRead;
        try {
            await this.doAction(action, { messageId });
        } catch (e) {
            msg.isRead = !newRead;
            this.showToast('Failed to update read status', 'error');
        }
    },

    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // Fallback for iframes without clipboard-write permission
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;left:-9999px';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        }
    },

    async copyMessagePath(messageId) {
        try {
            const data = await this.doAction('exportMessage', { messageId });
            if (data.path) {
                const copied = await this.copyToClipboard(data.path);
                this.showToast(copied ? 'Path copied: ' + data.path : 'Exported to: ' + data.path);
            }
        } catch (e) {
            this.showToast('Export failed: ' + e.message, 'error');
        }
    },

    async downloadAttachment(messageId, attachmentId, fileName) {
        try {
            const data = await this.doAction('downloadAttachment', { messageId, attachmentId });
            if (data.contentBytes) {
                const byteChars = atob(data.contentBytes);
                const byteNumbers = new Array(byteChars.length);
                for (let i = 0; i < byteChars.length; i++) byteNumbers[i] = byteChars.charCodeAt(i);
                const byteArray = new Uint8Array(byteNumbers);
                const blob = new Blob([byteArray], { type: data.contentType || 'application/octet-stream' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = fileName || data.name || 'download';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }
        } catch (e) {
            this.showToast('Download failed: ' + e.message, 'error');
        }
    },

    async downloadAttachmentToTmp(messageId, attachmentId) {
        try {
            const data = await this.doAction('downloadToTmp', { messageId, attachmentId });
            if (data.path) {
                const copied = await this.copyToClipboard(data.path);
                this.showToast(copied ? 'Saved & path copied: ' + data.path : 'Saved to: ' + data.path);
            }
        } catch (e) {
            this.showToast('Download failed: ' + e.message, 'error');
        }
    },

    // =========================================================================
    // Compose
    // =========================================================================

    openCompose(mode = 'new', message = null) {
        this.composeMode = mode;
        this.replyToMessageId = message?.id || null;
        this.composeShowCcBcc = false;
        this.composeOriginalHtml = '';
        this.composeToInput = '';
        this.composeCcInput = '';
        this.composeBccInput = '';

        if (mode === 'new') {
            this.composeTo = [];
            this.composeCc = [];
            this.composeBcc = [];
            this.composeSubject = '';
            this.composeBody = '';
        } else if (mode === 'reply' || mode === 'replyAll') {
            const from = message?.from?.emailAddress?.address || '';
            this.composeTo = from ? [from] : [];
            if (mode === 'replyAll') {
                const others = (message?.toRecipients || [])
                    .map(r => r.emailAddress?.address)
                    .filter(a => a && a !== this.getCurrentEmail())
                    .concat((message?.ccRecipients || []).map(r => r.emailAddress?.address).filter(Boolean));
                if (others.length > 0) {
                    this.composeCc = others;
                    this.composeShowCcBcc = true;
                }
            } else {
                this.composeCc = [];
            }
            this.composeBcc = [];
            this.composeSubject = (message?.subject || '').startsWith('Re:') ? message.subject : 'Re: ' + (message?.subject || '');
            this.composeBody = '';
            this.composeOriginalHtml = message?.body?.content || '';
        } else if (mode === 'forward') {
            this.composeTo = [];
            this.composeCc = [];
            this.composeBcc = [];
            this.composeSubject = (message?.subject || '').startsWith('Fwd:') ? message.subject : 'Fwd: ' + (message?.subject || '');
            this.composeBody = '';
            this.composeOriginalHtml = message?.body?.content || '';
        }

        this.showCompose = true;
    },

    getCurrentEmail() {
        const acc = this.accounts.find(a => a.name === this.selectedAccount);
        return acc?.email || '';
    },

    isValidEmail(s) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
    },

    addTag(field, inputField) {
        const val = this[inputField].trim();
        if (!val) return;
        // Split on comma/semicolon/space to support pasting multiple
        const parts = val.split(/[,;\s]+/).map(e => e.trim()).filter(Boolean);
        let invalid = [];
        parts.forEach(email => {
            if (this.isValidEmail(email)) {
                if (!this[field].includes(email)) {
                    this[field].push(email);
                }
            } else {
                invalid.push(email);
            }
        });
        // Keep invalid text in the input so the user can fix it
        this[inputField] = invalid.join(', ');
        if (invalid.length > 0) {
            this.showToast('Invalid email: ' + invalid.join(', '), 'error');
        }
    },

    removeTag(field, index) {
        this[field].splice(index, 1);
    },

    handleTagKeydown(e, field, inputField) {
        if (e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === 'Tab') {
            if (this[inputField].trim()) {
                e.preventDefault();
                this.addTag(field, inputField);
            }
        } else if (e.key === 'Backspace' && !this[inputField] && this[field].length > 0) {
            this[field].pop();
        }
    },

    handleTagBlur(field, inputField) {
        if (this[inputField].trim()) {
            this.addTag(field, inputField);
        }
    },

    async sendCompose() {
        // Flush any typed-but-not-entered text into tags
        this.handleTagBlur('composeTo', 'composeToInput');
        this.handleTagBlur('composeCc', 'composeCcInput');
        this.handleTagBlur('composeBcc', 'composeBccInput');

        if (this.composeTo.length === 0) {
            this.showToast('Recipient (To) is required', 'error');
            return;
        }

        const body = this.getComposeHtml();
        this.composeSending = true;
        try {
            if (this.composeMode === 'reply' || this.composeMode === 'replyAll') {
                const fullHtml = this.buildReplyHtml(body);
                await this.doAction('replyMessage', {
                    messageId: this.replyToMessageId,
                    bodyHtml: fullHtml,
                    replyAll: this.composeMode === 'replyAll',
                });
            } else if (this.composeMode === 'forward') {
                const fullHtml = this.buildReplyHtml(body);
                await this.doAction('forwardMessage', {
                    messageId: this.replyToMessageId,
                    to: this.composeTo,
                    bodyHtml: fullHtml,
                });
            } else {
                await this.doAction('sendMail', {
                    to: this.composeTo,
                    cc: this.composeCc.length > 0 ? this.composeCc : undefined,
                    bcc: this.composeBcc.length > 0 ? this.composeBcc : undefined,
                    subject: this.composeSubject,
                    body: body,
                    contentType: 'HTML',
                });
            }
            this.quillInstance = null;
            this.composeOriginalHtml = '';
            this.showCompose = false;
            this.showToast('Sent!');
            // Refresh if in sent folder
            if (this.selectedFolderName === 'Sent Items') {
                this.fetchMessages();
            }
        } catch (e) {
            this.showToast('Send failed: ' + e.message, 'error');
        } finally {
            this.composeSending = false;
        }
    },

    cancelCompose() {
        this.quillInstance = null;
        this.composeOriginalHtml = '';
        this.showCompose = false;
    },

    initQuill() {
        this.quillInstance = null;
        this.$nextTick(() => {
            const container = document.getElementById('quill-compose');
            if (!container || typeof Quill === 'undefined') return;

            // Calculate initial editor height: 40% of the content area
            const formArea = container.closest('[data-compose-content]');
            if (formArea && this.composeMode !== 'new' && this.composeOriginalHtml) {
                const addressFields = formArea.querySelector('[data-compose-fields]');
                const available = formArea.clientHeight - (addressFields?.offsetHeight || 0);
                this.editorHeight = Math.max(120, Math.round(available * 0.4));
            }

            this.quillInstance = new Quill('#quill-compose', {
                theme: 'snow',
                placeholder: 'Write your message...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['link'],
                        ['clean'],
                    ],
                },
            });
        });
    },

    startComposeResize(e) {
        this.composeDragging = true;
        const isTouch = e.type === 'touchstart';
        const startY = isTouch ? e.touches[0].clientY : e.clientY;
        const startHeight = this.editorHeight;
        const formArea = e.target.closest('[data-compose-content]');
        const addressFields = formArea?.querySelector('[data-compose-fields]');
        const maxHeight = (formArea?.clientHeight || 600) - (addressFields?.offsetHeight || 0) - 80;

        const onMove = (ev) => {
            const y = ev.type === 'touchmove' ? ev.touches[0].clientY : ev.clientY;
            this.editorHeight = Math.max(80, Math.min(maxHeight, startHeight + (y - startY)));
        };
        const onEnd = () => {
            this.composeDragging = false;
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onEnd);
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', onEnd);
    },

    getComposeHtml() {
        if (this.quillInstance) {
            return this.quillInstance.root.innerHTML;
        }
        return '';
    },

    buildReplyHtml(replyContent) {
        const msg = this.selectedMessage;
        if (!msg) return replyContent;

        const from = msg.from?.emailAddress;
        const fromStr = from?.name
            ? `${this.escapeHtml(from.name)} &lt;${this.escapeHtml(from.address)}&gt;`
            : this.escapeHtml(from?.address || '');
        const to = this.escapeHtml(this.formatRecipients(msg.toRecipients));
        const cc = msg.ccRecipients?.length ? this.escapeHtml(this.formatRecipients(msg.ccRecipients)) : '';
        const date = this.formatDate(msg.receivedDateTime);
        const subject = this.escapeHtml(msg.subject || '');
        const originalBody = msg.body?.content || '';

        let header = `<b>From:</b> ${fromStr}<br><b>Sent:</b> ${date}<br><b>To:</b> ${to}<br>`;
        if (cc) header += `<b>CC:</b> ${cc}<br>`;
        header += `<b>Subject:</b> ${subject}`;

        return `${replyContent}<br><hr style='border:none;border-top:1px solid #cccccc;margin:16px 0;'><div style='color:#666666;font-size:12px;margin-bottom:12px;'>${header}</div><div>${originalBody}</div>`;
    },

    formatFileSize(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    },
}"
class="h-full flex flex-col text-sm relative"
>
    {{-- ===================================================================== --}}
    {{-- HEADER BAR --}}
    {{-- ===================================================================== --}}
    <div class="shrink-0 border-b border-white/10 bg-white/[0.02]"
         x-show="mobileView !== 'detail' || window.innerWidth >= 1024">
        {{-- Main header row --}}
        <div class="flex items-center gap-2 px-3 py-2">
            {{-- Hamburger (mobile) --}}
            <button @click="showSidebar = !showSidebar" class="lg:hidden text-gray-400 hover:text-white p-1 cursor-pointer">
                <i class="fa-solid fa-bars"></i>
            </button>

            {{-- Account selector (desktop only) --}}
            <template x-if="accounts.length > 1">
                <select x-model="selectedAccount" @change="switchAccount($event.target.value)"
                    class="hidden lg:inline-block bg-white/5 border border-white/10 rounded px-2 py-1 text-xs text-gray-200 outline-none cursor-pointer">
                    <template x-for="acc in accounts" :key="acc.name">
                        <option :value="acc.name" x-text="acc.email" class="bg-gray-800"></option>
                    </template>
                </select>
            </template>
            <template x-if="accounts.length === 1">
                <span class="hidden lg:inline text-xs text-gray-400" x-text="accounts[0]?.email"></span>
            </template>

            {{-- Folder name --}}
            <span class="text-gray-200 font-medium text-xs" x-text="selectedFolderName"></span>

            {{-- Spacer --}}
            <div class="flex-1"></div>

            {{-- Search (desktop: inline input, mobile: toggle icon) --}}
            <div class="hidden lg:flex items-center">
                <div class="relative">
                    <input type="text" x-model="searchQuery" @keydown.enter="searchMessages()"
                        placeholder="Search..."
                        class="bg-white/5 border border-white/10 rounded px-2 py-1 text-xs text-gray-200 w-36 sm:w-48 outline-none focus:border-blue-500/50 placeholder-gray-500"
                        :class="searchQuery ? 'pr-5' : ''">
                    <template x-if="searchQuery">
                        <button @click="clearSearch()" class="absolute right-1.5 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300 cursor-pointer">
                            <i class="fa-solid fa-xmark text-[10px]"></i>
                        </button>
                    </template>
                </div>
                <button @click="searchMessages()" class="ml-1 text-gray-400 hover:text-white p-1 cursor-pointer" title="Search">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </button>
            </div>
            <button @click="showMobileSearch = !showMobileSearch; if (showMobileSearch) $nextTick(() => $refs.mobileSearchInput?.focus())" class="lg:hidden text-gray-400 hover:text-white p-1 cursor-pointer" title="Search">
                <i class="fa-solid fa-magnifying-glass text-xs"></i>
            </button>

            {{-- Refresh --}}
            <button @click="fetchMessages(); fetchFolders();" class="text-gray-400 hover:text-white p-1 cursor-pointer" title="Refresh">
                <i class="fa-solid fa-arrows-rotate text-xs"></i>
            </button>

            {{-- Compose (desktop: full button, mobile: icon only) --}}
            <button @click="openCompose('new')"
                class="px-2.5 py-1 rounded text-xs font-medium transition-colors cursor-pointer"
                :class="canSend() ? 'bg-blue-600 hover:bg-blue-500 text-white' : 'bg-gray-700 text-gray-500 !cursor-not-allowed'"
                x-bind:disabled="!canSend()"
                :title="canSend() ? '' : 'Requires Mail.Send permission'">
                <i class="fa-solid fa-pen-to-square"></i><span class="hidden lg:inline ml-1">Compose</span>
            </button>
        </div>

        {{-- Mobile search bar (expandable) --}}
        <div x-show="showMobileSearch" x-collapse class="lg:hidden px-3 pb-2">
            <div class="flex items-center gap-1">
                <div class="relative flex-1">
                    <input type="text" x-model="searchQuery" @keydown.enter="searchMessages(); showMobileSearch = false;"
                        placeholder="Search..."
                        class="w-full bg-white/5 border border-white/10 rounded px-2 py-1 text-xs text-gray-200 outline-none focus:border-blue-500/50 placeholder-gray-500"
                        :class="searchQuery ? 'pr-5' : ''"
                        x-ref="mobileSearchInput">
                    <template x-if="searchQuery">
                        <button @click="clearSearch(); showMobileSearch = false;" class="absolute right-1.5 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300 cursor-pointer">
                            <i class="fa-solid fa-xmark text-[10px]"></i>
                        </button>
                    </template>
                </div>
                <button @click="searchMessages(); showMobileSearch = false;" class="text-gray-400 hover:text-white p-1 cursor-pointer" title="Search">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </button>
            </div>
        </div>
    </div>

    {{-- ===================================================================== --}}
    {{-- NO ACCOUNTS STATE --}}
    {{-- ===================================================================== --}}
    <template x-if="accounts.length === 0">
        <div class="flex-1 flex items-center justify-center p-8">
            <div class="text-center text-gray-400 max-w-sm">
                <i class="fa-solid fa-envelope text-4xl mb-4 block text-gray-600"></i>
                <p class="font-medium text-gray-300 mb-2">No email accounts configured</p>
                <p class="text-xs">Add credentials in Settings &gt; Credentials:</p>
                <div class="mt-3 text-left bg-white/5 rounded p-3 text-xs font-mono">
                    <div>AZURE_{NAME}_CLIENT_ID</div>
                    <div>AZURE_{NAME}_CLIENT_SECRET</div>
                    <div>AZURE_{NAME}_TENANT_ID</div>
                    <div>AZURE_{NAME}_EMAIL</div>
                </div>
            </div>
        </div>
    </template>

    {{-- ===================================================================== --}}
    {{-- MAIN LAYOUT --}}
    {{-- ===================================================================== --}}
    <template x-if="accounts.length > 0">
        <div class="flex-1 flex overflow-hidden min-h-0">

            {{-- ============================================================= --}}
            {{-- FOLDER SIDEBAR --}}
            {{-- ============================================================= --}}
            <div class="w-44 shrink-0 border-r border-white/10 overflow-y-auto bg-white/[0.01]"
                 :class="{ 'hidden lg:block': !showSidebar, 'block': showSidebar }"
                 x-show="mobileView !== 'detail' || window.innerWidth >= 1024">

                {{-- Account selector (mobile only, inside sidebar) --}}
                <template x-if="accounts.length > 1">
                    <div class="lg:hidden px-3 py-1.5 border-b border-white/10">
                        <select x-model="selectedAccount" @change="switchAccount($event.target.value)"
                            class="w-full bg-white/5 border border-white/10 rounded px-2 py-1 text-xs text-gray-200 outline-none cursor-pointer">
                            <template x-for="acc in accounts" :key="acc.name">
                                <option :value="acc.name" x-text="acc.email" class="bg-gray-800"></option>
                            </template>
                        </select>
                    </div>
                </template>
                <template x-if="accounts.length === 1">
                    <div class="lg:hidden px-3 py-1.5 border-b border-white/10 text-xs text-gray-400 truncate" x-text="accounts[0]?.email"></div>
                </template>

                <div>
                    <template x-if="foldersLoading">
                        <div class="px-3 py-4 text-center">
                            <svg class="animate-spin inline-block text-gray-500" style="width:1em;height:1em" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                        </div>
                    </template>
                    <template x-for="folder in folders" :key="folder.id">
                        <button @click="selectFolder(folder)"
                            class="w-full flex items-center gap-2 px-3 py-1.5 text-left text-xs hover:bg-white/5 transition-colors cursor-pointer"
                            :class="selectedFolderId === folder.id ? 'bg-blue-600/20 text-blue-300' : 'text-gray-300'">
                            <i class="fa-solid text-[10px] w-3.5 text-center opacity-60" :class="getFolderIcon(folder)"></i>
                            <span class="truncate flex-1" x-text="folder.displayName"></span>
                            <span x-show="folder.unreadItemCount > 0"
                                  class="text-[10px] bg-blue-600/30 text-blue-300 rounded-full px-1.5 min-w-[18px] text-center"
                                  x-text="folder.unreadItemCount"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- ============================================================= --}}
            {{-- MESSAGE LIST --}}
            {{-- ============================================================= --}}
            <div class="flex-1 min-w-0 flex flex-col border-r border-white/10 lg:max-w-[340px]"
                 x-show="mobileView !== 'detail' || window.innerWidth >= 1024">

                {{-- Search indicator --}}
                <template x-if="isSearching">
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-yellow-500/10 border-b border-white/10 text-xs text-yellow-300">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <span>Searching: "<span x-text="activeSearch"></span>"</span>
                        <button @click="clearSearch()" class="ml-auto hover:text-white cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </template>

                {{-- Message list --}}
                <div class="flex-1 overflow-y-auto">
                    <template x-if="messagesLoading && messages.length === 0">
                        <div class="flex items-center justify-center py-12">
                            <svg class="animate-spin text-gray-500" style="width:1.5em;height:1.5em" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                        </div>
                    </template>

                    <template x-if="!messagesLoading && messages.length === 0">
                        <div class="text-center py-12 text-gray-500 text-xs">
                            <i class="fa-solid fa-inbox text-2xl mb-2 block"></i>
                            <span x-text="isSearching ? 'No results found' : 'No messages'"></span>
                        </div>
                    </template>

                    <template x-for="msg in messages" :key="msg.id">
                        <button @click="selectMessage(msg)"
                            class="w-full text-left px-3 py-2.5 border-b border-white/5 hover:bg-white/[0.04] transition-colors cursor-pointer"
                            :class="{
                                'bg-blue-600/15': selectedMessage?.id === msg.id,
                                'bg-white/[0.02]': selectedMessage?.id !== msg.id
                            }">
                            <div class="flex items-start gap-2">
                                {{-- Unread dot --}}
                                <div class="w-2 pt-1.5 shrink-0">
                                    <div x-show="!msg.isRead" class="w-2 h-2 rounded-full bg-blue-500"></div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-1">
                                        <span class="truncate text-xs"
                                              :class="msg.isRead ? 'text-gray-400' : 'text-gray-100 font-semibold'"
                                              x-text="formatSenderShort(msg)"></span>
                                        <span class="text-[10px] text-gray-500 shrink-0 ml-auto" x-text="formatDate(msg.receivedDateTime)"></span>
                                    </div>
                                    <div class="flex items-center gap-1 mt-0.5">
                                        <span class="truncate text-xs"
                                              :class="msg.isRead ? 'text-gray-500' : 'text-gray-300 font-medium'"
                                              x-text="msg.subject || '(no subject)'"></span>
                                        <i x-show="msg.hasAttachments" class="fa-solid fa-paperclip text-[9px] text-gray-500 shrink-0"></i>
                                    </div>
                                    <div class="text-[11px] text-gray-600 truncate mt-0.5" x-text="msg.bodyPreview || ''"></div>
                                </div>
                            </div>
                        </button>
                    </template>

                    {{-- Load more (hidden during search — Graph API doesn't support $skip with $search) --}}
                    <template x-if="hasMore && !isSearching">
                        <div class="p-3 text-center">
                            <button @click="loadMore()"
                                class="text-xs text-blue-400 hover:text-blue-300 cursor-pointer"
                                x-bind:disabled="messagesLoading">
                                <span x-show="!messagesLoading">Load more</span>
                                <span x-show="messagesLoading">Loading...</span>
                            </button>
                        </div>
                    </template>
                </div>
            </div>

            {{-- ============================================================= --}}
            {{-- MESSAGE PREVIEW PANE --}}
            {{-- ============================================================= --}}
            <div class="flex-1 min-w-0 flex flex-col overflow-hidden"
                 x-show="mobileView === 'detail' || window.innerWidth >= 1024">

                {{-- Empty state --}}
                <template x-if="!selectedMessage">
                    <div class="flex-1 flex items-center justify-center text-gray-600">
                        <div class="text-center">
                            <i class="fa-solid fa-envelope-open text-3xl mb-2 block"></i>
                            <p class="text-xs">Select a message to read</p>
                        </div>
                    </div>
                </template>

                <template x-if="selectedMessage">
                    <div class="flex-1 flex flex-col overflow-hidden">
                        {{-- Back button (mobile) --}}
                        <div class="lg:hidden px-3 py-2 border-b border-white/10">
                            <button @click="mobileView = 'list'; selectedMessage = null;"
                                class="text-xs text-blue-400 hover:text-blue-300 cursor-pointer">
                                <i class="fa-solid fa-chevron-left mr-1"></i>Back
                            </button>
                        </div>

                        {{-- Message header --}}
                        <div class="px-4 py-3 border-b border-white/10 shrink-0">
                            {{-- Subject --}}
                            <h2 class="text-sm font-semibold text-gray-100 mb-2" x-text="selectedMessage.subject || '(no subject)'"></h2>

                            {{-- From --}}
                            <div class="flex items-start gap-2 mb-1">
                                <div class="w-8 h-8 rounded-full bg-blue-600/30 flex items-center justify-center text-blue-300 text-xs font-bold shrink-0"
                                     x-text="(formatSender(selectedMessage) || '?')[0].toUpperCase()"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-xs font-medium text-gray-200" x-text="formatSender(selectedMessage)"></span>
                                        <span class="text-[10px] text-gray-500" x-text="selectedMessage.from?.emailAddress?.address"></span>
                                    </div>
                                    <div class="text-[10px] text-gray-500 mt-0.5">
                                        <span>To: <span x-text="formatRecipientsEmail(selectedMessage.toRecipients)"></span></span>
                                        <template x-if="selectedMessage.ccRecipients?.length > 0">
                                            <span class="ml-2">CC: <span x-text="formatRecipientsEmail(selectedMessage.ccRecipients)"></span></span>
                                        </template>
                                    </div>
                                    <div class="text-[10px] text-gray-500" x-text="formatDate(selectedMessage.receivedDateTime)"></div>
                                </div>
                            </div>

                            {{-- Action buttons (icon-only on mobile, icon+text on desktop) --}}
                            <div class="flex items-center gap-1 mt-2">
                                <button @click="openCompose('reply', selectedMessage)"
                                    class="px-2 py-1 text-[11px] bg-white/5 rounded transition-colors cursor-pointer"
                                    :class="canSend() ? 'hover:bg-white/10 text-gray-300' : 'text-gray-600 !cursor-not-allowed'"
                                    x-bind:disabled="!canSend()"
                                    title="Reply">
                                    <i class="fa-solid fa-reply"></i><span class="hidden lg:inline ml-1">Reply</span>
                                </button>
                                <button @click="openCompose('replyAll', selectedMessage)"
                                    class="px-2 py-1 text-[11px] bg-white/5 rounded transition-colors cursor-pointer"
                                    :class="canSend() ? 'hover:bg-white/10 text-gray-300' : 'text-gray-600 !cursor-not-allowed'"
                                    x-bind:disabled="!canSend()"
                                    title="Reply All">
                                    <i class="fa-solid fa-reply-all"></i><span class="hidden lg:inline ml-1">Reply All</span>
                                </button>
                                <button @click="openCompose('forward', selectedMessage)"
                                    class="px-2 py-1 text-[11px] bg-white/5 rounded transition-colors cursor-pointer"
                                    :class="canSend() ? 'hover:bg-white/10 text-gray-300' : 'text-gray-600 !cursor-not-allowed'"
                                    x-bind:disabled="!canSend()"
                                    title="Forward">
                                    <i class="fa-solid fa-share"></i><span class="hidden lg:inline ml-1">Forward</span>
                                </button>

                                <div class="w-px h-4 bg-white/10 mx-1 hidden lg:block"></div>

                                <button @click="archiveMessage(selectedMessage.id)"
                                    class="px-2 py-1 text-[11px] bg-white/5 rounded transition-colors cursor-pointer"
                                    x-bind:class="canWrite() ? 'hover:bg-white/10 text-gray-300' : 'text-gray-600 !cursor-not-allowed'"
                                    x-bind:disabled="!!(!canWrite() || actionLoading[selectedMessage.id])"
                                    title="Archive">
                                    <i class="fa-solid fa-box-archive"></i><span class="hidden lg:inline ml-1">Archive</span>
                                </button>
                                <button @click="requestDelete(selectedMessage.id)"
                                    class="px-2 py-1 text-[11px] bg-white/5 hover:bg-red-600/20 text-gray-300 hover:text-red-300 rounded transition-colors cursor-pointer"
                                    x-bind:disabled="!!(!canWrite() || actionLoading[selectedMessage.id])"
                                    title="Delete">
                                    <i class="fa-solid fa-trash-can"></i><span class="hidden lg:inline ml-1">Delete</span>
                                </button>

                                <div class="w-px h-4 bg-white/10 mx-1 hidden lg:block"></div>

                                <button @click="copyMessagePath(selectedMessage.id)"
                                    class="px-2 py-1 text-[11px] bg-white/5 hover:bg-purple-600/20 rounded text-gray-300 hover:text-purple-300 transition-colors cursor-pointer"
                                    title="Export as .eml to /tmp and copy path to clipboard">
                                    <i class="fa-solid fa-clipboard"></i><span class="hidden lg:inline ml-1">Copy .eml</span>
                                </button>

                                {{-- Attachments toggle (mobile: inline button, right-aligned) --}}
                                <template x-if="selectedMessage?._attachments?.filter(a => !a.isInline).length > 0">
                                    <button @click="showAttachments = !showAttachments"
                                        class="lg:hidden ml-auto px-2 py-1 text-[11px] bg-white/5 hover:bg-white/10 rounded text-gray-300 transition-colors cursor-pointer"
                                        title="Attachments">
                                        <i class="fa-solid fa-paperclip"></i>
                                        (<span x-text="selectedMessage._attachments.filter(a => !a.isInline).length"></span>)
                                    </button>
                                </template>
                            </div>

                            {{-- Permission warning --}}
                            <template x-if="permissions.length > 0 && (!canWrite() || !canSend())">
                                <div class="mt-1.5 text-[10px] text-amber-500/70">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                    <span x-text="!canWrite() && !canSend()
                                        ? 'Missing Mail.ReadWrite + Mail.Send permissions (read-only mode)'
                                        : !canWrite()
                                            ? 'Missing Mail.ReadWrite permission (cannot archive/delete/mark read)'
                                            : 'Missing Mail.Send permission (cannot reply/forward/compose)'"></span>
                                </div>
                            </template>

                            {{-- Attachments: collapsible on mobile, always visible on desktop --}}
                            <template x-if="selectedMessage?._attachments?.filter(a => !a.isInline).length > 0">
                                <div class="mt-2 pt-2 border-t border-white/5"
                                     x-show="showAttachments || window.innerWidth >= 1024" x-collapse>
                                    <h4 class="text-[11px] text-gray-400 font-medium mb-1.5 hidden lg:block">
                                        <i class="fa-solid fa-paperclip mr-1"></i>
                                        Attachments (<span x-text="selectedMessage._attachments.filter(a => !a.isInline).length"></span>)
                                    </h4>
                                    <div class="flex flex-col lg:flex-row lg:flex-wrap gap-1.5">
                                        <template x-for="att in selectedMessage._attachments.filter(a => !a.isInline)" :key="att.id">
                                            <div class="flex items-center gap-1.5 bg-white/5 border border-white/10 rounded px-2 py-1 text-[11px] group">
                                                <i class="fa-solid fa-file text-gray-500 text-[10px]"></i>
                                                <span class="text-gray-300 truncate flex-1 lg:flex-none lg:max-w-[200px]" x-text="att.name"></span>
                                                <span class="text-gray-600 text-[10px]" x-text="formatFileSize(att.size)"></span>
                                                <div class="flex gap-1 ml-auto lg:ml-0.5">
                                                    <button @click="downloadAttachment(selectedMessage.id, att.id, att.name)"
                                                        class="text-blue-400 hover:text-blue-300 cursor-pointer" title="Download">
                                                        <i class="fa-solid fa-download text-[10px]"></i>
                                                    </button>
                                                    <button @click="downloadAttachmentToTmp(selectedMessage.id, att.id)"
                                                        class="text-purple-400 hover:text-purple-300 cursor-pointer" title="Save to /tmp & copy path">
                                                        <i class="fa-solid fa-clipboard text-[10px]"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Message body --}}
                        <div class="flex-1 overflow-y-auto flex flex-col">
                            {{-- Loading state --}}
                            <template x-if="messageLoading">
                                <div class="flex items-center justify-center py-12">
                                    <svg class="animate-spin text-gray-500" style="width:1.5em;height:1.5em" viewBox="0 0 24 24" fill="none">
                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                    </svg>
                                </div>
                            </template>

                            {{-- "Load remote images" banner --}}
                            <template x-if="!messageLoading && selectedMessage?.body && !allowRemoteImages">
                                <div class="flex items-center gap-2 px-3 py-1.5 bg-yellow-900/20 border-b border-yellow-700/30 text-xs text-yellow-300/80 shrink-0">
                                    <i class="fa-solid fa-shield-halved"></i>
                                    <span>Remote images are blocked for privacy.</span>
                                    <button @click="allowRemoteImages = true"
                                        class="ml-auto text-yellow-300 hover:text-yellow-200 underline cursor-pointer">
                                        Load images
                                    </button>
                                </div>
                            </template>

                            {{-- Email body iframe --}}
                            <template x-if="!messageLoading && selectedMessage?.body">
                                <iframe
                                    :srcdoc="getBodyHtml()"
                                    sandbox=""
                                    class="w-full flex-1 border-0"
                                    style="min-height: 300px; background: white;"
                                    x-init="$nextTick(() => {
                                        const iframe = $el;
                                        const resize = () => {
                                            try {
                                                const h = iframe.contentDocument?.body?.scrollHeight || iframe.contentDocument?.documentElement?.scrollHeight;
                                                if (h) iframe.style.height = Math.min(h + 20, 2000) + 'px';
                                            } catch(e) {}
                                        };
                                        iframe.addEventListener('load', resize);
                                    })"
                                ></iframe>
                            </template>

                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>

    {{-- ===================================================================== --}}
    {{-- COMPOSE OVERLAY --}}
    {{-- ===================================================================== --}}
    <template x-if="showCompose">
        <div class="absolute inset-0 z-50 flex flex-col bg-[#1a1a2e]/98 backdrop-blur-sm" x-init="$nextTick(() => initQuill())">
            {{-- Compose header --}}
            <div class="flex items-center gap-2 px-3 py-1.5 lg:px-4 lg:py-2.5 border-b border-white/10 shrink-0">
                <span class="text-sm font-medium text-gray-200"
                    x-text="composeMode === 'new' ? 'New Message' : composeMode === 'forward' ? 'Forward' : composeMode === 'replyAll' ? 'Reply All' : 'Reply'"></span>
                <div class="flex-1"></div>
                <button @click="cancelCompose()" class="text-gray-400 hover:text-white p-1 cursor-pointer">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            {{-- Drag overlay: captures mouse events above iframes during resize --}}
            <div x-show="composeDragging" class="absolute inset-0 z-[60] cursor-row-resize"></div>

            {{-- Compose content area --}}
            <div class="flex-1 flex flex-col overflow-hidden px-3 py-2 lg:px-4 lg:py-3 min-h-0" data-compose-content>
                {{-- Address fields (fixed, never flex) --}}
                <div class="shrink-0" data-compose-fields>
                    {{-- From (display only, desktop) --}}
                    <div class="hidden lg:flex items-center gap-2 mb-2">
                        <label class="text-xs text-gray-500 w-10 shrink-0">From</label>
                        <span class="text-xs text-gray-300" x-text="getCurrentEmail()"></span>
                    </div>

                    {{-- To --}}
                    <div class="flex items-start gap-2 mb-1 lg:mb-2">
                        <label class="text-xs text-gray-500 w-10 shrink-0 mt-1.5">To</label>
                        <div class="flex-1 flex flex-wrap items-center gap-1 bg-white/5 border border-white/10 rounded px-1.5 py-1 min-h-[28px] focus-within:border-blue-500/50 cursor-text"
                             @click="$refs.toInput.focus()">
                            <template x-for="(email, i) in composeTo" :key="i">
                                <span class="inline-flex items-center gap-0.5 bg-blue-600/25 text-blue-300 rounded px-1.5 py-0.5 text-[11px] max-w-[180px]">
                                    <span class="truncate" x-text="email"></span>
                                    <button @click.stop="removeTag('composeTo', i)" class="text-blue-400 hover:text-white cursor-pointer ml-0.5">&times;</button>
                                </span>
                            </template>
                            <input type="text" x-model="composeToInput" x-ref="toInput"
                                @keydown="handleTagKeydown($event, 'composeTo', 'composeToInput')"
                                @blur="handleTagBlur('composeTo', 'composeToInput')"
                                class="flex-1 min-w-[80px] bg-transparent text-xs text-gray-200 outline-none placeholder-gray-500 py-0.5"
                                placeholder="recipient@example.com"
                                :placeholder="composeTo.length === 0 ? 'recipient@example.com' : ''">
                        </div>
                        <button @click="composeShowCcBcc = !composeShowCcBcc"
                            class="text-[10px] shrink-0 cursor-pointer mt-1.5"
                            :class="composeShowCcBcc ? 'text-blue-400 hover:text-blue-300' : (composeCc.length || composeBcc.length) ? 'text-blue-400 hover:text-blue-300' : 'text-gray-500 hover:text-gray-300'">
                            <span x-show="!composeShowCcBcc && (composeCc.length || composeBcc.length)" x-text="(composeCc.length ? 'CC: ' + composeCc.length : '') + (composeCc.length && composeBcc.length ? ', ' : '') + (composeBcc.length ? 'BCC: ' + composeBcc.length : '')" class="inline-block align-middle"></span>
                            <span x-show="!(!composeShowCcBcc && (composeCc.length || composeBcc.length))">CC/BCC</span>
                            <i class="fa-solid fa-chevron-down text-[8px] ml-0.5 transition-transform" :class="composeShowCcBcc && 'rotate-180'"></i>
                        </button>
                    </div>

                    {{-- CC --}}
                    <div x-show="composeShowCcBcc" class="flex items-start gap-2 mb-1 lg:mb-2" x-collapse>
                        <label class="text-xs text-gray-500 w-10 shrink-0 mt-1.5">CC</label>
                        <div class="flex-1 flex flex-wrap items-center gap-1 bg-white/5 border border-white/10 rounded px-1.5 py-1 min-h-[28px] focus-within:border-blue-500/50 cursor-text"
                             @click="$refs.ccInput.focus()">
                            <template x-for="(email, i) in composeCc" :key="i">
                                <span class="inline-flex items-center gap-0.5 bg-blue-600/25 text-blue-300 rounded px-1.5 py-0.5 text-[11px] max-w-[180px]">
                                    <span class="truncate" x-text="email"></span>
                                    <button @click.stop="removeTag('composeCc', i)" class="text-blue-400 hover:text-white cursor-pointer ml-0.5">&times;</button>
                                </span>
                            </template>
                            <input type="text" x-model="composeCcInput" x-ref="ccInput"
                                @keydown="handleTagKeydown($event, 'composeCc', 'composeCcInput')"
                                @blur="handleTagBlur('composeCc', 'composeCcInput')"
                                class="flex-1 min-w-[80px] bg-transparent text-xs text-gray-200 outline-none placeholder-gray-500 py-0.5"
                                :placeholder="composeCc.length === 0 ? 'cc@example.com' : ''">
                        </div>
                    </div>

                    {{-- BCC --}}
                    <div x-show="composeShowCcBcc" class="flex items-start gap-2 mb-1 lg:mb-2" x-collapse>
                        <label class="text-xs text-gray-500 w-10 shrink-0 mt-1.5">BCC</label>
                        <div class="flex-1 flex flex-wrap items-center gap-1 bg-white/5 border border-white/10 rounded px-1.5 py-1 min-h-[28px] focus-within:border-blue-500/50 cursor-text"
                             @click="$refs.bccInput.focus()">
                            <template x-for="(email, i) in composeBcc" :key="i">
                                <span class="inline-flex items-center gap-0.5 bg-blue-600/25 text-blue-300 rounded px-1.5 py-0.5 text-[11px] max-w-[180px]">
                                    <span class="truncate" x-text="email"></span>
                                    <button @click.stop="removeTag('composeBcc', i)" class="text-blue-400 hover:text-white cursor-pointer ml-0.5">&times;</button>
                                </span>
                            </template>
                            <input type="text" x-model="composeBccInput" x-ref="bccInput"
                                @keydown="handleTagKeydown($event, 'composeBcc', 'composeBccInput')"
                                @blur="handleTagBlur('composeBcc', 'composeBccInput')"
                                class="flex-1 min-w-[80px] bg-transparent text-xs text-gray-200 outline-none placeholder-gray-500 py-0.5"
                                :placeholder="composeBcc.length === 0 ? 'bcc@example.com' : ''">
                        </div>
                    </div>

                    {{-- Subject --}}
                    <div class="flex items-center gap-2 mb-2 lg:mb-3">
                        <label class="text-xs text-gray-500 w-10 shrink-0">Subj</label>
                        <input type="text" x-model="composeSubject"
                            class="flex-1 bg-white/5 border border-white/10 rounded px-2 py-1 lg:py-1.5 text-xs text-gray-200 outline-none focus:border-blue-500/50"
                            placeholder="Subject">
                    </div>
                </div>

                {{-- Quill editor pane (flex-controlled height) --}}
                <div class="flex flex-col overflow-hidden"
                     :class="composeMode !== 'new' && composeOriginalHtml ? 'shrink-0' : 'flex-1 min-h-0'"
                     :style="composeMode !== 'new' && composeOriginalHtml ? 'height: ' + editorHeight + 'px' : ''">
                    <div id="quill-compose" class="flex-1 min-h-0"></div>
                </div>

                {{-- Drag handle (only for reply/forward) --}}
                <div x-show="composeMode !== 'new' && composeOriginalHtml"
                     class="shrink-0 h-3 cursor-row-resize group flex items-center justify-center select-none"
                     @mousedown.prevent="startComposeResize($event)"
                     @touchstart.prevent="startComposeResize($event)">
                    <div class="w-10 h-1 rounded-full bg-white/15 group-hover:bg-white/30 transition-colors"></div>
                </div>

                {{-- Original message pane (fills remaining space) --}}
                <template x-if="composeMode !== 'new' && composeOriginalHtml">
                    <div class="flex-1 min-h-0 flex flex-col overflow-hidden">
                        <div class="text-[10px] text-gray-500 mb-1.5 font-medium shrink-0">
                            <i class="fa-solid fa-quote-left mr-1"></i>Original message
                        </div>
                        <div class="flex-1 min-h-0 bg-white/[0.02] border border-white/5 rounded overflow-hidden">
                            <iframe
                                :srcdoc="composeOriginalHtml"
                                sandbox=""
                                class="w-full h-full border-0"
                                style="background: white;"
                            ></iframe>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Compose footer --}}
            <div class="flex items-center gap-2 px-3 py-1.5 lg:px-4 lg:py-2.5 border-t border-white/10 shrink-0">
                <button @click="sendCompose()"
                    class="bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white px-4 py-1.5 rounded text-xs font-medium cursor-pointer"
                    x-bind:disabled="composeSending">
                    <template x-if="!composeSending">
                        <span><i class="fa-solid fa-paper-plane mr-1"></i>Send</span>
                    </template>
                    <template x-if="composeSending">
                        <span>
                            <svg class="animate-spin inline-block mr-1" style="width:0.75em;height:0.75em" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                            Sending...
                        </span>
                    </template>
                </button>
                <button @click="cancelCompose()" class="px-4 py-1.5 text-xs text-gray-400 hover:text-white cursor-pointer">
                    Cancel
                </button>
            </div>
        </div>
    </template>

    {{-- ===================================================================== --}}
    {{-- DELETE CONFIRMATION MODAL --}}
    {{-- ===================================================================== --}}
    <template x-if="showDeleteConfirm">
        <div class="absolute inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="cancelDelete()">
            <div class="bg-[#1e1e32] border border-white/10 rounded-xl shadow-2xl px-5 py-4 mx-4 max-w-sm w-full">
                <div class="flex items-center gap-2.5 mb-3">
                    <div class="w-8 h-8 rounded-full bg-red-600/20 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-trash-can text-red-400 text-sm"></i>
                    </div>
                    <h3 class="text-sm font-semibold text-gray-100">Delete email?</h3>
                </div>
                <p class="text-xs text-gray-400 mb-4">This will permanently delete this email. This action cannot be undone.</p>
                <div class="flex items-center justify-end gap-2">
                    <button @click="cancelDelete()"
                        class="px-3 py-1.5 text-xs bg-white/5 hover:bg-white/10 rounded-lg text-gray-300 hover:text-gray-100 transition-colors cursor-pointer">
                        Cancel
                    </button>
                    <button @click="confirmDelete()"
                        class="px-3 py-1.5 text-xs bg-red-600/80 hover:bg-red-600 rounded-lg text-white transition-colors cursor-pointer">
                        <i class="fa-solid fa-trash-can mr-1"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- ===================================================================== --}}
    {{-- TOAST NOTIFICATION --}}
    {{-- ===================================================================== --}}
    <template x-if="toast">
        <div class="absolute bottom-4 right-4 z-50 px-3 py-2 rounded-lg text-xs font-medium shadow-lg transition-all"
             :class="toast.type === 'error' ? 'bg-red-600/90 text-white' : 'bg-green-600/90 text-white'"
             x-text="toast.message">
        </div>
    </template>

    {{-- ===================================================================== --}}
    {{-- ERROR BANNER --}}
    {{-- ===================================================================== --}}
    <template x-if="error">
        <div class="absolute top-12 left-4 right-4 z-50 bg-red-600/20 border border-red-500/30 rounded-lg px-3 py-2 text-xs text-red-300 flex items-center gap-2">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span x-text="error" class="flex-1"></span>
            <button @click="error = null" class="text-red-400 hover:text-white cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </template>

    {{-- ===================================================================== --}}
    {{-- QUILL DARK THEME OVERRIDES --}}
    {{-- ===================================================================== --}}
    <style>
        .ql-toolbar.ql-snow {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.1) !important;
            border-radius: 6px 6px 0 0;
            flex-shrink: 0;
        }
        .ql-container.ql-snow {
            border-color: rgba(255,255,255,0.1) !important;
            border-radius: 0 0 6px 6px;
            font-family: inherit;
            font-size: 13px;
        }
        .ql-container {
            flex: 1 !important;
            height: auto !important;
            overflow: visible !important;
            min-height: 0;
            overflow-y: auto;
        }
        .ql-editor {
            color: #e5e7eb;
        }
        .ql-editor.ql-blank::before {
            color: rgba(255,255,255,0.25);
            font-style: normal;
        }
        .ql-snow .ql-stroke {
            stroke: #9ca3af !important;
        }
        .ql-snow .ql-fill {
            fill: #9ca3af !important;
        }
        .ql-snow .ql-picker {
            color: #9ca3af;
        }
        .ql-snow .ql-picker-label {
            color: #9ca3af;
        }
        .ql-snow .ql-picker-options {
            background: #1e1e32;
            border-color: rgba(255,255,255,0.1) !important;
        }
        .ql-snow .ql-picker-item {
            color: #d1d5db;
        }
        .ql-snow button:hover .ql-stroke,
        .ql-snow .ql-picker-label:hover .ql-stroke {
            stroke: #e5e7eb !important;
        }
        .ql-snow button:hover .ql-fill,
        .ql-snow .ql-picker-label:hover .ql-fill {
            fill: #e5e7eb !important;
        }
        .ql-snow button.ql-active .ql-stroke {
            stroke: #60a5fa !important;
        }
        .ql-snow button.ql-active .ql-fill {
            fill: #60a5fa !important;
        }
        .ql-snow .ql-tooltip {
            background: #1e1e32;
            border-color: rgba(255,255,255,0.1);
            color: #e5e7eb;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .ql-snow .ql-tooltip input[type=text] {
            color: #e5e7eb;
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.1);
        }
        .ql-snow .ql-tooltip a {
            color: #60a5fa;
        }
    </style>
</div>
