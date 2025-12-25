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
    @endif

    <script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.6/dist/purify.min.js"
            integrity="sha384-cwS6YdhLI7XS60eoDiC+egV0qHp8zI+Cms46R0nbn8JrmoAzV9uFL60etMZhAnSu"
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
          crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
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

            {{-- Messages Container --}}
            {{-- Mobile: fixed position between header and input, contained scroll --}}
            {{-- Desktop: flex container scroll with overflow-y-auto --}}
            <div id="messages"
                 class="p-4 space-y-4 overflow-y-auto bg-gray-900
                        fixed top-[60px] bottom-[200px] left-0 right-0 z-0
                        md:static md:pt-4 md:pb-4 md:flex-1">

                {{-- Empty State --}}
                <template x-if="messages.length === 0">
                    <div class="text-center text-gray-400 mt-10 md:mt-20">
                        <h3 class="text-xl mb-2">Welcome to PocketDev</h3>
                        <p class="text-sm md:text-base">Multi-provider AI chat with direct API streaming</p>
                    </div>
                </template>

                {{-- Messages List --}}
                <template x-for="(msg, index) in messages" :key="msg.id">
                    <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                        <x-chat.user-message />
                        <x-chat.assistant-message />
                        <x-chat.thinking-block />
                        <x-chat.tool-block />
                        <x-chat.system-block />
                        <x-chat.empty-response />
                    </div>
                </template>
            </div>

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
                currentConversationUuid: null,
                conversationProvider: null, // Provider of current conversation (for mid-convo agent switch)
                isStreaming: false,
                _justCompletedStream: false,
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
                showCostBreakdown: false,
                showOpenAiModal: false,
                showClaudeCodeAuthModal: false,
                showErrorModal: false,
                errorMessage: '',
                openAiKeyInput: '',

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
                audioChunkCount: 0,

                // Anthropic API key state (for Claude Code)
                anthropicKeyInput: '',
                anthropicKeyConfigured: false,

                // Stream reconnection state
                lastEventIndex: 0,
                streamAbortController: null,
                _streamConnectNonce: 0,
                _streamRetryTimeoutId: null,
                _streamState: {
                    thinkingMsgIndex: -1,
                    textMsgIndex: -1,
                    toolMsgIndex: -1,
                    thinkingContent: '',
                    textContent: '',
                    toolInput: '',
                    turnCost: 0,
                },

                async init() {
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
                        await this.loadConversation(urlConversationUuid);

                        // Scroll to bottom if returning from settings
                        if (returningFromSettings) {
                            this.$nextTick(() => this.scrollToBottom());
                        }
                    }

                    // Handle browser back/forward navigation
                    window.addEventListener('popstate', (event) => {
                        if (event.state && event.state.conversationUuid) {
                            this.loadConversation(event.state.conversationUuid);
                        } else {
                            // Back to new conversation state
                            this.newConversation();
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

                selectAgent(agent, closeModal = true) {
                    if (!agent) return;

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
                    } catch (err) {
                        console.error('Failed to fetch conversations:', err);
                    }
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

                        // Reset token counters
                        this.inputTokens = 0;
                        this.outputTokens = 0;
                        this.cacheCreationTokens = 0;
                        this.cacheReadTokens = 0;
                        this.sessionCost = 0;
                        this.lastEventIndex = 0;

                        // Reset stream state for potential reconnection
                        this._streamState = {
                            thinkingMsgIndex: -1,
                            textMsgIndex: -1,
                            toolMsgIndex: -1,
                            thinkingContent: '',
                            textContent: '',
                            toolInput: '',
                            turnCost: 0,
                            turnInputTokens: 0,
                            turnOutputTokens: 0,
                            turnCacheCreationTokens: 0,
                            turnCacheReadTokens: 0,
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

                        // Parse messages for display
                        if (data.conversation?.messages) {
                            for (const msg of data.conversation.messages) {
                                this.addMessageFromDb(msg);
                            }
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

                        // If conversation has an agent, select it
                        // Defensive check: ensure agents are loaded before looking up
                        if (data.conversation?.agent_id) {
                            // If agents not yet loaded, fetch them first
                            if (this.agents.length === 0) {
                                await this.fetchAgents();
                            }

                            const agent = this.agents.find(a => a.id === data.conversation.agent_id);
                            if (agent) {
                                this.selectAgent(agent, false);
                            } else {
                                console.warn(`Agent ${data.conversation.agent_id} not found in available agents`);
                            }
                        }

                        // Load provider-specific reasoning settings from conversation
                        this.responseLevel = data.conversation?.response_level ?? 1;
                        this.anthropicThinkingBudget = data.conversation?.anthropic_thinking_budget ?? 0;
                        this.openaiReasoningEffort = data.conversation?.openai_reasoning_effort ?? 'none';
                        this.openaiCompatibleReasoningEffort = data.conversation?.openai_compatible_reasoning_effort ?? 'none';
                        this.claudeCodeThinkingTokens = data.conversation?.claude_code_thinking_tokens ?? 0;

                        this.scrollToBottom();

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
                            cacheReadTokens: msgCacheRead
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
                                    cacheReadTokens: msgCacheRead
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
                                    cacheReadTokens: isLast ? msgCacheRead : null
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
                                    cacheReadTokens: isLast ? msgCacheRead : null
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
                                    cacheReadTokens: isLast ? msgCacheRead : null
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
                    this.scrollToBottom();

                    // Reset stream state
                    this.lastEventIndex = 0;
                    this._justCompletedStream = false;
                    this._streamState = {
                        thinkingMsgIndex: -1,
                        textMsgIndex: -1,
                        toolMsgIndex: -1,
                        thinkingContent: '',
                        textContent: '',
                        toolInput: '',
                        turnCost: 0,
                        turnInputTokens: 0,
                        turnOutputTokens: 0,
                        turnCacheCreationTokens: 0,
                        turnCacheReadTokens: 0,
                    };

                    try {
                        // Build stream request body
                        const streamBody = {
                            prompt: userPrompt,
                            response_level: this.responseLevel,
                            model: this.model
                        };
                        // NOTE: Reasoning/thinking settings are now loaded from the agent on the backend.
                        // Per-message reasoning override has been disabled (see input-desktop.blade.php).
                        // To re-enable, uncomment below and the reasoning toggle button.
                        /*
                        if (this.provider === 'anthropic') {
                            streamBody.anthropic_thinking_budget = this.anthropicThinkingBudget;
                        } else if (this.provider === 'openai') {
                            streamBody.openai_reasoning_effort = this.openaiReasoningEffort;
                        } else if (this.provider === 'openai_compatible') {
                            streamBody.openai_compatible_reasoning_effort = this.openaiCompatibleReasoningEffort;
                        } else if (this.provider === 'claude_code') {
                            streamBody.claude_code_thinking_tokens = this.claudeCodeThinkingTokens;
                        }
                        */

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

                // Handle a single stream event
                handleStreamEvent(event) {
                    const state = this._streamState;

                    // Debug: log all events except usage
                    if (event.type !== 'usage') {
                        console.log('SSE Event:', event.type, event.content ? String(event.content).substring(0, 50) : '(no content)');
                    }

                    switch (event.type) {
                        case 'thinking_start':
                            state.thinkingMsgIndex = this.messages.length;
                            state.thinkingContent = '';
                            this.messages.push({
                                id: 'msg-' + Date.now() + '-thinking',
                                role: 'thinking',
                                content: '',
                                timestamp: new Date().toISOString(),
                                collapsed: false
                            });
                            break;

                        case 'thinking_delta':
                            if (state.thinkingMsgIndex >= 0 && event.content) {
                                state.thinkingContent += event.content;
                                this.messages[state.thinkingMsgIndex] = {
                                    ...this.messages[state.thinkingMsgIndex],
                                    content: state.thinkingContent
                                };
                            }
                            break;

                        case 'thinking_signature':
                            // Signature is captured but not displayed
                            break;

                        case 'thinking_stop':
                            if (state.thinkingMsgIndex >= 0) {
                                this.messages[state.thinkingMsgIndex] = {
                                    ...this.messages[state.thinkingMsgIndex],
                                    collapsed: true
                                };
                            }
                            break;

                        case 'text_start':
                            // Collapse thinking
                            if (state.thinkingMsgIndex >= 0) {
                                this.messages[state.thinkingMsgIndex] = {
                                    ...this.messages[state.thinkingMsgIndex],
                                    collapsed: true
                                };
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
                            // Collapse thinking
                            if (state.thinkingMsgIndex >= 0) {
                                this.messages[state.thinkingMsgIndex] = {
                                    ...this.messages[state.thinkingMsgIndex],
                                    collapsed: true
                                };
                            }
                            state.toolMsgIndex = this.messages.length;
                            state.toolInput = '';
                            this.messages.push({
                                id: 'msg-' + Date.now() + '-tool',
                                role: 'tool',
                                toolName: event.metadata?.tool_name || 'Tool',
                                toolId: event.metadata?.tool_id,
                                toolInput: '',
                                toolResult: null,
                                content: '',
                                timestamp: new Date().toISOString(),
                                collapsed: false
                            });
                            break;

                        case 'tool_use_delta':
                            if (state.toolMsgIndex >= 0 && event.content) {
                                state.toolInput += event.content;
                                this.messages[state.toolMsgIndex] = {
                                    ...this.messages[state.toolMsgIndex],
                                    toolInput: state.toolInput,
                                    content: state.toolInput
                                };
                            }
                            break;

                        case 'tool_use_stop':
                            if (state.toolMsgIndex >= 0) {
                                this.messages[state.toolMsgIndex] = {
                                    ...this.messages[state.toolMsgIndex],
                                    collapsed: true
                                };
                                // Reset for next tool
                                state.toolMsgIndex = -1;
                                state.toolInput = '';
                            }
                            break;

                        case 'tool_result':
                            // Find the tool message with matching toolId and add the result
                            const toolUseId = event.metadata?.tool_id;
                            if (toolUseId) {
                                const toolMsgIndex = this.messages.findIndex(m => m.role === 'tool' && m.toolId === toolUseId);
                                if (toolMsgIndex >= 0) {
                                    this.messages[toolMsgIndex] = {
                                        ...this.messages[toolMsgIndex],
                                        toolResult: event.content
                                    };
                                }
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
                                    model: this.model
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
                    this.$nextTick(() => {
                        const container = document.getElementById('messages');
                        if (container) {
                            container.scrollTop = container.scrollHeight;
                        }
                    });
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
                    this.showCostBreakdown = true;
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
                            const msg = JSON.parse(event.data);

                            if (msg.type === 'conversation.item.input_audio_transcription.delta') {
                                if (msg.delta) {
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
                        processor.connect(this.realtimeAudioContext.destination);
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
