<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PocketDev Chat</title>

    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="https://cdn.tailwindcss.com"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
        <script>
            // Define Alpine store for CDN mode (before Alpine starts)
            document.addEventListener('alpine:init', () => {
                Alpine.store('debug', {
                    logs: [],
                    showPanel: false,
                    maxLogs: 100,
                    log(message, data = null) {
                        const timestamp = new Date().toISOString().substr(11, 12);
                        const entry = { timestamp, message, data };
                        console.log(`[DEBUG ${timestamp}] ${message}`, data ?? '');
                        this.logs.push(entry);
                        if (this.logs.length > this.maxLogs) this.logs.shift();
                    },
                    clear() { this.logs = []; },
                    copy() {
                        const text = this.logs.map(log => {
                            const dataStr = log.data !== null ? ' ' + (typeof log.data === 'object' ? JSON.stringify(log.data) : log.data) : '';
                            return `[${log.timestamp}] ${log.message}${dataStr}`;
                        }).join('\n');
                        navigator.clipboard.writeText(text);
                    },
                    toggle() { this.showPanel = !this.showPanel; }
                });
                window.debugLog = (message, data = null) => Alpine.store('debug').log(message, data);
            });
        </script>
    @endif

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
<body class="antialiased bg-gray-900 text-gray-100 h-screen overflow-hidden" x-data="chatApp()" x-init="init()">

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
        <div class="flex-1 flex flex-col">

            {{-- Desktop Header (hidden on mobile) --}}
            <div class="hidden md:flex bg-gray-800 border-b border-gray-700 p-2 items-center justify-between">
                <div class="flex items-center gap-3 pl-2">
                    <h2 class="text-base font-semibold">PocketDev</h2>
                    <button @click="showAgentSelector = true"
                            class="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-200"
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
                    {{-- Context window progress bar --}}
                    <x-chat.context-progress />
                </div>
                <a href="{{ route('config.index') }}" class="text-gray-300 hover:text-white p-2" title="Settings">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </a>
            </div>

            {{-- Messages Container --}}
            {{-- Mobile: fixed position between header and input, contained scroll --}}
            {{-- Desktop: flex container scroll with overflow-y-auto --}}
            <div id="messages"
                 class="p-4 space-y-4 overflow-y-auto bg-gray-900
                        fixed top-[60px] left-0 right-0 z-0
                        md:static md:pt-4 md:pb-4 md:flex-1"
                 :style="'bottom: ' + mobileInputHeight + 'px'"
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
                        <x-chat.system-block />
                        <x-chat.interrupted-block />
                        <x-chat.error-block />
                        <x-chat.empty-response />
                    </div>
                </template>
            </div>

            {{-- Scroll to Bottom Button (mobile) --}}
            <button @click="autoScrollEnabled = true; scrollToBottom()"
                    :class="(!isAtBottom && messages.length > 0) ? 'opacity-100 scale-100 pointer-events-auto' : 'opacity-0 scale-75 pointer-events-none'"
                    class="md:hidden fixed z-50 w-10 h-10 bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white rounded-full shadow-lg flex items-center justify-center transition-all duration-200 right-4"
                    :style="'bottom: ' + (parseInt(mobileInputHeight) + 16) + 'px'"
                    title="Scroll to bottom">
                <i class="fas fa-arrow-down"></i>
            </button>
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
                    class="hidden md:flex fixed z-50 w-10 h-10 bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white rounded-full shadow-lg items-center justify-center transition-colors duration-200 right-8 bottom-24"
                    title="Scroll to bottom">
                <i class="fas fa-arrow-down"></i>
            </button>

            {{-- Desktop Input (hidden on mobile) --}}
            <div class="hidden md:block">
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
                currentConversationUuid: null,
                conversationProvider: null, // Provider of current conversation (for mid-convo agent switch)
                isStreaming: false,
                _justCompletedStream: false,
                autoScrollEnabled: true, // Auto-scroll during streaming; disabled when user scrolls up manually
                isAtBottom: true, // Track if user is at bottom of messages
                ignoreScrollEvents: false, // Ignore scroll events during conversation loading
                _initDone: false, // Guard against double initialization
                sessionCost: 0,
                totalTokens: 0,

                // Agents
                agents: [],
                currentAgentId: null,

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
                showPricingSettings: false,
                showMessageDetails: false,
                showOpenAiModal: false,
                showClaudeCodeAuthModal: false,
                showErrorModal: false,
                showSearchModal: false,
                errorMessage: '',
                openAiKeyInput: '',

                // Conversation search
                showSearchInput: false,

                // Copy message state
                copiedMessageId: null,
                conversationSearchQuery: '',
                conversationSearchResults: [],
                conversationSearchLoading: false,
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

                // Realtime transcription state (WebSocket-based)
                realtimeWs: null,
                realtimeTranscript: '',
                realtimeAudioContext: null,
                realtimeAudioWorklet: null,
                realtimeStream: null,
                waitingForFinalTranscript: false,
                stopTimeout: null,
                currentTranscriptItemId: null,

                // Mobile input height tracking (for dynamic messages container bottom)
                mobileInputHeight: 60,

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

                    // Fetch available agents
                    await this.fetchAgents();

                    // Load conversations list
                    await this.fetchConversations();

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
                        // Check for turn parameter in URL
                        const urlParams = new URLSearchParams(window.location.search);
                        const turnParam = urlParams.get('turn');
                        if (turnParam) {
                            const turnNumber = parseInt(turnParam, 10);
                            if (!isNaN(turnNumber)) {
                                this.pendingScrollToTurn = turnNumber;
                                this.autoScrollEnabled = false;
                            }
                        }

                        if (event.state && event.state.conversationUuid) {
                            this.loadConversation(event.state.conversationUuid);
                        } else {
                            // Back to new conversation state
                            this.newConversation();
                        }
                    });

                    // Track mobile input height for dynamic messages container bottom
                    this.$nextTick(() => {
                        if (this.$refs.mobileInput) {
                            const resizeObserver = new ResizeObserver((entries) => {
                                for (const entry of entries) {
                                    this.mobileInputHeight = entry.contentRect.height;
                                }
                            });
                            resizeObserver.observe(this.$refs.mobileInput);
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
                        const response = await fetch('/api/agents');
                        const data = await response.json();
                        this.agents = data.data || [];

                        // Auto-select default agent if none selected
                        if (!this.currentAgentId && this.agents.length > 0) {
                            // Find default agent, or first available
                            const defaultAgent = this.agents.find(a => a.is_default) || this.agents[0];
                            this.selectAgent(defaultAgent, false);
                        }
                    } catch (err) {
                        console.error('Failed to fetch agents:', err);
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

                async selectAgent(agent, closeModal = true) {
                    if (!agent) return;

                    // If we have an active conversation AND we're switching to a different agent,
                    // update the backend first. This ensures the next message uses the new agent's
                    // system prompt and tools.
                    if (this.currentConversationUuid && agent.id !== this.currentAgentId) {
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
                        const response = await fetch('/api/conversations?working_directory=/var/www');
                        const data = await response.json();
                        this.conversations = data.data || [];
                        this.conversationsPage = data.current_page || 1;
                        this.conversationsLastPage = data.last_page || 1;
                    } catch (err) {
                        console.error('Failed to fetch conversations:', err);
                    }
                },

                async fetchMoreConversations() {
                    if (this.loadingMoreConversations || this.conversationsPage >= this.conversationsLastPage) {
                        return;
                    }
                    this.loadingMoreConversations = true;
                    try {
                        const nextPage = this.conversationsPage + 1;
                        const response = await fetch(`/api/conversations?working_directory=/var/www&page=${nextPage}`);
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
                        const response = await fetch(`/api/conversations/search?query=${encodeURIComponent(this.conversationSearchQuery)}&limit=20`);
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

                async loadConversation(uuid) {
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

                        // Only set state after validating response
                        this.currentConversationUuid = uuid;

                        // Update URL to reflect loaded conversation
                        this.updateUrl(uuid);
                        this.messages = [];
                        this.isAtBottom = true; // Hide scroll button during load
                        this.debugLog('SET isAtBottom = true (loadConversation reset)');
                        // Only enable auto-scroll if not coming from search result
                        if (this.pendingScrollToTurn === null) {
                            this.autoScrollEnabled = true;
                        }
                        this.ignoreScrollEvents = true; // Ignore scroll events until load complete
                        this.debugLog('loadConversation: reset state, ignoreScrollEvents=true');

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

                        // Parse messages for display
                        if (data.conversation?.messages) {
                            for (const msg of data.conversation.messages) {
                                this.addMessageFromDb(msg);
                            }
                            this.debugLog('loadConversation: messages loaded', { count: this.messages.length });
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

                        // Scroll to turn if coming from search, otherwise scroll to bottom
                        if (this.pendingScrollToTurn !== null) {
                            this.scrollToTurn(this.pendingScrollToTurn);
                            this.pendingScrollToTurn = null;
                        } else {
                            this.scrollToBottom();
                        }

                        // Re-enable scroll event handling after scroll completes
                        this.$nextTick(() => {
                            this.ignoreScrollEvents = false;
                            this.debugLog('loadConversation: $nextTick completed');
                        });

                        // Check if there's an active stream for this conversation
                        await this.checkAndReconnectStream(uuid);

                    } catch (err) {
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
                    if (!this.prompt.trim() || this.isStreaming) return;

                    const userPrompt = this.prompt;
                    this.prompt = '';

                    // Create conversation if needed
                    if (!this.currentConversationUuid) {
                        // Require agent selection
                        if (!this.currentAgentId) {
                            this.showAgentSelector = true;
                            this.prompt = userPrompt; // Restore prompt
                            return;
                        }

                        try {
                            const createBody = {
                                working_directory: '/var/www',
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
                            this.conversationProvider = this.provider; // Lock provider for this conversation

                            // Update URL with new conversation UUID
                            this.updateUrl(this.currentConversationUuid);

                            await this.fetchConversations();
                        } catch (err) {
                            this.showError('Failed to create conversation: ' + err.message);
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
                            return;
                        }

                        // Connect to stream events SSE endpoint
                        await this.connectToStreamEvents();

                    } catch (err) {
                        this.showError('Failed to start streaming: ' + err.message);
                        this.isStreaming = false;
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
                                                this.showError('Failed to connect to stream');
                                                return;
                                            }
                                        }
                                        if (event.status === 'completed' || event.status === 'failed') {
                                            this.isStreaming = false;
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
                                timestamp: new Date().toISOString(),
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
                                timestamp: new Date().toISOString(),
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
                                timestamp: new Date().toISOString(),
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
                    this.debugLog('scrollToBottom called', { autoScrollEnabled: this.autoScrollEnabled });
                    if (!this.autoScrollEnabled) return;
                    this.$nextTick(() => {
                        const container = document.getElementById('messages');
                        if (container) {
                            container.scrollTop = container.scrollHeight;
                            this.isAtBottom = true;
                            this.debugLog('SET isAtBottom = true (scrollToBottom)', { scrollTop: container.scrollTop, scrollHeight: container.scrollHeight });
                        }
                    });
                },

                scrollToTurn(turnNumber) {
                    this.debugLog('scrollToTurn called', { turnNumber, type: typeof turnNumber });

                    // Debug: Check what turn_numbers exist in messages
                    const turnNumbers = this.messages.map(m => m.turn_number);
                    this.debugLog('scrollToTurn: available turn_numbers in messages', { turnNumbers });

                    this.$nextTick(() => {
                        // Small delay to ensure DOM is fully rendered
                        setTimeout(() => {
                            // Debug: Check what data-turn values exist in DOM
                            const allTurnElements = document.querySelectorAll('[data-turn]');
                            const domTurns = Array.from(allTurnElements).map(el => el.getAttribute('data-turn'));
                            this.debugLog('scrollToTurn: data-turn values in DOM', { domTurns, count: allTurnElements.length });

                            const selector = `[data-turn="${turnNumber}"]`;
                            this.debugLog('scrollToTurn: using selector', { selector });

                            const turnElement = document.querySelector(selector);
                            this.debugLog('scrollToTurn: element found?', { found: !!turnElement });

                            if (turnElement) {
                                const container = document.getElementById('messages');
                                if (container) {
                                    // Calculate scroll position to put element at top (with small offset)
                                    const containerRect = container.getBoundingClientRect();
                                    const elementRect = turnElement.getBoundingClientRect();
                                    const currentScrollTop = container.scrollTop;
                                    const topOffset = 16; // Small padding from top
                                    const newScrollTop = currentScrollTop + (elementRect.top - containerRect.top) - topOffset;
                                    const finalScrollTop = Math.max(0, newScrollTop);

                                    this.debugLog('scrollToTurn: scroll calculation', {
                                        currentScrollTop,
                                        containerHeight: containerRect.height,
                                        elementTop: elementRect.top,
                                        containerTop: containerRect.top,
                                        newScrollTop,
                                        finalScrollTop
                                    });

                                    container.scrollTop = finalScrollTop;
                                    this.debugLog('scrollToTurn: scrollTop set to', { scrollTop: container.scrollTop });
                                }
                                // Brief highlight effect
                                turnElement.classList.add('bg-blue-900/30');
                                setTimeout(() => turnElement.classList.remove('bg-blue-900/30'), 2000);
                                this.isAtBottom = false;
                                this.debugLog('scrollToTurn: SUCCESS - scrolled to turn', { turnNumber });
                            } else {
                                // Fallback to bottom if turn not found
                                this.debugLog('scrollToTurn: FAILED - turn not found', { turnNumber, selector });
                                this.autoScrollEnabled = true;
                                this.scrollToBottom();
                            }
                        }, 100);
                    });
                },

                handleMessagesScroll(event) {
                    // Ignore scroll events during conversation loading
                    if (this.ignoreScrollEvents) {
                        this.debugLog('handleMessagesScroll IGNORED (ignoreScrollEvents=true)');
                        return;
                    }

                    const container = event.target;
                    // Check if user is at bottom (within 50px threshold)
                    const diff = container.scrollHeight - container.scrollTop - container.clientHeight;
                    const atBottom = diff < 50;

                    // Always log scroll events for debugging
                    this.debugLog('handleMessagesScroll', {
                        atBottom,
                        wasAtBottom: this.isAtBottom,
                        changed: this.isAtBottom !== atBottom,
                        diff: Math.round(diff),
                        scrollTop: Math.round(container.scrollTop),
                        scrollHeight: container.scrollHeight,
                        clientHeight: container.clientHeight
                    });

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

                renderMarkdown(text) {
                    if (!text) return '';
                    return DOMPurify.sanitize(marked.parse(text));
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
                    try {
                        this.isProcessing = true;
                        // Preserve existing prompt text, add space if needed
                        this.realtimeTranscript = this.prompt.trim() ? this.prompt.trim() + ' ' : '';
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
