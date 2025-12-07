<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PocketDev Chat V2</title>

    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="https://cdn.tailwindcss.com"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @endif

    <script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

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

        @media (max-width: 767px) {
            .desktop-layout { display: none !important; }
            html, body { scroll-behavior: smooth; -webkit-overflow-scrolling: touch; }
        }
        @media (min-width: 768px) {
            .mobile-layout { display: none !important; }
        }
    </style>
</head>
<body class="antialiased bg-gray-900 text-gray-100" x-data="chatV2App()" x-init="init()">

    {{-- Desktop Layout --}}
    <div class="desktop-layout flex h-screen">
        @include('partials.chat-v2.sidebar')

        <div class="flex-1 flex flex-col">
            {{-- Messages Area --}}
            <div id="messages" class="flex-1 overflow-y-auto p-4 space-y-4">
                <template x-if="messages.length === 0">
                    <div class="text-center text-gray-400 mt-20">
                        <h3 class="text-xl mb-2">Welcome to PocketDev V2</h3>
                        <p>Multi-provider AI chat with direct API streaming</p>
                    </div>
                </template>

                <template x-for="(msg, index) in messages" :key="msg.id">
                    <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                        @include('partials.chat-v2.messages.user-message', ['variant' => 'desktop'])
                        @include('partials.chat-v2.messages.assistant-message', ['variant' => 'desktop'])
                        @include('partials.chat-v2.messages.thinking-block', ['variant' => 'desktop'])
                        @include('partials.chat-v2.messages.tool-block', ['variant' => 'desktop'])
                        @include('partials.chat-v2.messages.empty-response', ['variant' => 'desktop'])
                    </div>
                </template>
            </div>

            @include('partials.chat-v2.input-desktop')
        </div>
    </div>

    {{-- Mobile Layout --}}
    <div class="mobile-layout">
        @include('partials.chat-v2.mobile-layout')

        {{-- Messages Area --}}
        <div id="messages-mobile" class="p-4 space-y-4 pb-56 min-h-screen">
            <template x-if="messages.length === 0">
                <div class="text-center text-gray-400 mt-10">
                    <h3 class="text-xl mb-2">Welcome to PocketDev V2</h3>
                    <p class="text-sm">Multi-provider AI chat</p>
                </div>
            </template>

            <template x-for="(msg, index) in messages" :key="msg.id + '-mobile'">
                <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                    @include('partials.chat-v2.messages.user-message', ['variant' => 'mobile'])
                    @include('partials.chat-v2.messages.assistant-message', ['variant' => 'mobile'])
                    @include('partials.chat-v2.messages.thinking-block', ['variant' => 'mobile'])
                    @include('partials.chat-v2.messages.tool-block', ['variant' => 'mobile'])
                    @include('partials.chat-v2.messages.empty-response', ['variant' => 'mobile'])
                </div>
            </template>
        </div>

        @include('partials.chat-v2.input-mobile')
    </div>

    @include('partials.chat-v2.modals')

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

        function chatV2App() {
            return {
                // State
                prompt: '',
                messages: [],
                conversations: [],
                currentConversationUuid: null,
                isStreaming: false,
                sessionCost: 0,
                totalTokens: 0,

                // Provider/Model
                provider: 'anthropic',
                model: 'claude-sonnet-4-5-20250929',
                providers: {},
                availableModels: {},

                // Thinking modes
                thinkingLevel: 0,
                thinkingModes: [
                    { level: 0, name: 'Off', icon: '🧠', color: 'bg-gray-600 text-gray-200', tokens: 0 },
                    { level: 1, name: 'Think', icon: '💭', color: 'bg-blue-600 text-white', tokens: 4000 },
                    { level: 2, name: 'Think Hard', icon: '🤔', color: 'bg-purple-600 text-white', tokens: 10000 },
                    { level: 3, name: 'Think Harder', icon: '🧩', color: 'bg-pink-600 text-white', tokens: 20000 },
                    { level: 4, name: 'Ultrathink', icon: '🌟', color: 'bg-yellow-600 text-white', tokens: 32000 }
                ],

                // Response length modes
                responseLevel: 1,
                responseModes: [
                    { level: 0, name: 'Short', icon: '📝', tokens: 4000 },
                    { level: 1, name: 'Normal', icon: '📄', tokens: 8192 },
                    { level: 2, name: 'Long', icon: '📜', tokens: 16000 },
                    { level: 3, name: 'Very Long', icon: '📚', tokens: 32000 }
                ],

                // Modals
                showMobileDrawer: false,
                showShortcutsModal: false,
                showQuickSettings: false,
                showPricingSettings: false,
                showCostBreakdown: false,
                showOpenAiModal: false,
                showErrorModal: false,
                errorMessage: '',
                openAiKeyInput: '',

                // Pricing settings (per-model)
                // Default pricing from Anthropic (per million tokens)
                modelPricing: {
                    // Anthropic models (prices per million tokens)
                    // Cache write = 1.25x base input, Cache read = 0.1x base input
                    'claude-sonnet-4-5-20250929': { input: 3.00, output: 15.00, cacheWrite: 3.75, cacheRead: 0.30, name: 'Claude Sonnet 4.5' },
                    'claude-opus-4-5-20251101': { input: 5.00, output: 25.00, cacheWrite: 6.25, cacheRead: 0.50, name: 'Claude Opus 4.5' },
                    'claude-haiku-4-5-20251101': { input: 1.00, output: 5.00, cacheWrite: 1.25, cacheRead: 0.10, name: 'Claude Haiku 4.5' },
                    'claude-3-5-sonnet-20241022': { input: 3.00, output: 15.00, cacheWrite: 3.75, cacheRead: 0.30, name: 'Claude 3.5 Sonnet' },
                    'claude-3-opus-20240229': { input: 15.00, output: 75.00, cacheWrite: 18.75, cacheRead: 1.50, name: 'Claude 3 Opus' },
                    'claude-3-haiku-20240307': { input: 0.25, output: 1.25, cacheWrite: 0.30, cacheRead: 0.03, name: 'Claude 3 Haiku' },
                },
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

                // Voice recording state
                isRecording: false,
                isProcessing: false,
                mediaRecorder: null,
                audioChunks: [],
                openAiKeyConfigured: false,
                autoSendAfterTranscription: false,

                async init() {
                    // Load saved thinking level
                    const savedThinking = localStorage.getItem('thinkingLevel');
                    if (savedThinking !== null) this.thinkingLevel = parseInt(savedThinking);

                    // Load saved response level
                    const savedResponse = localStorage.getItem('responseLevel');
                    if (savedResponse !== null) this.responseLevel = parseInt(savedResponse);

                    // Load saved pricing
                    const savedPricing = localStorage.getItem('modelPricing');
                    if (savedPricing) {
                        try {
                            const parsed = JSON.parse(savedPricing);
                            // Merge with defaults (in case new models were added)
                            this.modelPricing = { ...this.modelPricing, ...parsed };
                        } catch (e) {
                            console.error('Failed to parse saved pricing:', e);
                        }
                    }

                    // Fetch providers
                    await this.fetchProviders();

                    // Load conversations list
                    await this.fetchConversations();

                    // Check OpenAI key for voice transcription
                    await this.checkOpenAiKey();
                },

                async fetchProviders() {
                    try {
                        const response = await fetch('/api/v2/providers');
                        const data = await response.json();
                        this.providers = data.providers;
                        this.provider = data.default || 'anthropic';
                        this.updateModels();
                    } catch (err) {
                        console.error('Failed to fetch providers:', err);
                        this.showError('Failed to load providers');
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

                async fetchConversations() {
                    try {
                        const response = await fetch('/api/v2/conversations?working_directory=/var/www');
                        const data = await response.json();
                        this.conversations = data.data || [];
                    } catch (err) {
                        console.error('Failed to fetch conversations:', err);
                    }
                },

                async newConversation() {
                    this.currentConversationUuid = null;
                    this.messages = [];
                    this.sessionCost = 0;
                    this.totalTokens = 0;
                    this.inputTokens = 0;
                    this.outputTokens = 0;
                    this.cacheCreationTokens = 0;
                    this.cacheReadTokens = 0;
                },

                async loadConversation(uuid) {
                    try {
                        const response = await fetch(`/api/v2/conversations/${uuid}`);
                        const data = await response.json();

                        this.currentConversationUuid = uuid;
                        this.messages = [];

                        // Reset token counters
                        this.inputTokens = 0;
                        this.outputTokens = 0;
                        this.cacheCreationTokens = 0;
                        this.cacheReadTokens = 0;
                        this.sessionCost = 0;

                        // Calculate totals and costs per-message using each message's model
                        if (data.conversation?.messages) {
                            for (const msg of data.conversation.messages) {
                                const inputToks = msg.input_tokens || 0;
                                const outputToks = msg.output_tokens || 0;
                                const cacheCreate = msg.cache_creation_tokens || 0;
                                const cacheRead = msg.cache_read_tokens || 0;
                                const msgModel = msg.model || data.conversation.model || this.model;

                                this.inputTokens += inputToks;
                                this.outputTokens += outputToks;
                                this.cacheCreationTokens += cacheCreate;
                                this.cacheReadTokens += cacheRead;

                                // Calculate cost using the model that generated this message
                                if (inputToks > 0 || outputToks > 0) {
                                    this.sessionCost += this.calculateCost(msgModel, inputToks, outputToks, cacheCreate, cacheRead);
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
                        }
                        if (data.conversation?.model) {
                            this.model = data.conversation.model;
                        }

                        this.scrollToBottom();
                    } catch (err) {
                        console.error('Failed to load conversation:', err);
                        this.showError('Failed to load conversation');
                    }
                },

                addMessageFromDb(dbMsg) {
                    // Convert DB message format to UI format
                    const content = dbMsg.content;

                    // Get token counts and model for cost calculation
                    const msgInputTokens = dbMsg.input_tokens || 0;
                    const msgOutputTokens = dbMsg.output_tokens || 0;
                    const msgCacheCreation = dbMsg.cache_creation_tokens || 0;
                    const msgCacheRead = dbMsg.cache_read_tokens || 0;
                    const msgModel = dbMsg.model || this.model; // Use stored model or current default

                    // Calculate cost using per-model pricing
                    const msgCost = msgInputTokens > 0 || msgOutputTokens > 0
                        ? this.calculateCost(msgModel, msgInputTokens, msgOutputTokens, msgCacheCreation, msgCacheRead)
                        : null;

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
                                    toolInput: block.input,
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
                        try {
                            const response = await fetch('/api/v2/conversations', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    working_directory: '/var/www',
                                    provider_type: this.provider,
                                    model: this.model,
                                    title: userPrompt.substring(0, 50)
                                })
                            });
                            const data = await response.json();
                            this.currentConversationUuid = data.conversation.uuid;
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

                    // Start streaming
                    this.isStreaming = true;

                    // Track message indices for reactive updates (more reliable than object refs)
                    let thinkingMsgIndex = -1;
                    let textMsgIndex = -1;
                    let toolMsgIndex = -1;
                    let thinkingContent = '';
                    let textContent = '';
                    let toolInput = '';

                    try {
                        const response = await fetch(`/api/v2/conversations/${this.currentConversationUuid}/stream`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                prompt: userPrompt,
                                thinking_level: this.thinkingLevel,
                                response_level: this.responseLevel
                            })
                        });

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

                                    // Debug: log all events except usage
                                    if (event.type !== 'usage') {
                                        console.log('SSE Event:', event.type, event.content ? event.content.substring(0, 50) : '(no content)');
                                    }

                                    // Handle stream events inline for better reactivity
                                    switch (event.type) {
                                        case 'thinking_start':
                                            thinkingMsgIndex = this.messages.length;
                                            thinkingContent = '';
                                            this.messages.push({
                                                id: 'msg-' + Date.now() + '-thinking',
                                                role: 'thinking',
                                                content: '',
                                                timestamp: new Date().toISOString(),
                                                collapsed: false
                                            });
                                            break;

                                        case 'thinking_delta':
                                            if (thinkingMsgIndex >= 0 && event.content) {
                                                thinkingContent += event.content;
                                                // Force reactivity by updating array element
                                                this.messages[thinkingMsgIndex] = {
                                                    ...this.messages[thinkingMsgIndex],
                                                    content: thinkingContent
                                                };
                                            }
                                            break;

                                        case 'thinking_signature':
                                            // Signature is captured but not displayed
                                            break;

                                        case 'thinking_stop':
                                            if (thinkingMsgIndex >= 0) {
                                                this.messages[thinkingMsgIndex] = {
                                                    ...this.messages[thinkingMsgIndex],
                                                    collapsed: true
                                                };
                                            }
                                            break;

                                        case 'text_start':
                                            // Collapse thinking
                                            if (thinkingMsgIndex >= 0) {
                                                this.messages[thinkingMsgIndex] = {
                                                    ...this.messages[thinkingMsgIndex],
                                                    collapsed: true
                                                };
                                            }
                                            textMsgIndex = this.messages.length;
                                            textContent = '';
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
                                            if (textMsgIndex >= 0 && event.content) {
                                                textContent += event.content;
                                                // Force reactivity by updating array element
                                                this.messages[textMsgIndex] = {
                                                    ...this.messages[textMsgIndex],
                                                    content: textContent
                                                };
                                                this.scrollToBottom();
                                            }
                                            break;

                                        case 'text_stop':
                                            // Text block complete
                                            break;

                                        case 'tool_use_start':
                                            // Collapse thinking
                                            if (thinkingMsgIndex >= 0) {
                                                this.messages[thinkingMsgIndex] = {
                                                    ...this.messages[thinkingMsgIndex],
                                                    collapsed: true
                                                };
                                            }
                                            toolMsgIndex = this.messages.length;
                                            toolInput = '';
                                            this.messages.push({
                                                id: 'msg-' + Date.now() + '-tool',
                                                role: 'tool',
                                                toolName: event.metadata?.tool_name || 'Tool',
                                                toolId: event.metadata?.tool_id,
                                                toolInput: '',
                                                content: '',
                                                timestamp: new Date().toISOString(),
                                                collapsed: false
                                            });
                                            break;

                                        case 'tool_use_delta':
                                            if (toolMsgIndex >= 0 && event.content) {
                                                toolInput += event.content;
                                                this.messages[toolMsgIndex] = {
                                                    ...this.messages[toolMsgIndex],
                                                    toolInput: toolInput,
                                                    content: toolInput
                                                };
                                            }
                                            break;

                                        case 'tool_use_stop':
                                            if (toolMsgIndex >= 0) {
                                                this.messages[toolMsgIndex] = {
                                                    ...this.messages[toolMsgIndex],
                                                    collapsed: true
                                                };
                                                // Reset for next tool
                                                toolMsgIndex = -1;
                                                toolInput = '';
                                            }
                                            break;

                                        case 'tool_result':
                                            // Tool result is handled server-side, displayed on next response
                                            console.log('Tool result:', event.metadata?.tool_id, event.content?.substring(0, 100));
                                            break;

                                        case 'usage':
                                            if (event.metadata) {
                                                const input = event.metadata.input_tokens || 0;
                                                const output = event.metadata.output_tokens || 0;
                                                const cacheCreation = event.metadata.cache_creation_tokens || 0;
                                                const cacheRead = event.metadata.cache_read_tokens || 0;

                                                this.inputTokens += input;
                                                this.outputTokens += output;
                                                this.cacheCreationTokens += cacheCreation;
                                                this.cacheReadTokens += cacheRead;
                                                this.totalTokens += input + output;
                                                this.sessionCost += (input * 3 + output * 15) / 1000000;
                                            }
                                            break;

                                        case 'done':
                                            if (textMsgIndex >= 0) {
                                                this.messages[textMsgIndex] = {
                                                    ...this.messages[textMsgIndex],
                                                    cost: this.sessionCost
                                                };
                                            }
                                            break;

                                        case 'error':
                                            this.showError(event.content || 'Unknown error');
                                            break;

                                        case 'debug':
                                            console.log('Debug from server:', event.content, event.metadata);
                                            break;

                                        default:
                                            console.log('Unknown event type:', event.type, event);
                                    }
                                } catch (parseErr) {
                                    console.error('Parse error:', parseErr, line);
                                }
                            }
                        }
                    } catch (err) {
                        this.showError('Streaming error: ' + err.message);
                    } finally {
                        this.isStreaming = false;
                        await this.fetchConversations();
                    }
                },

                cycleThinkingMode() {
                    this.thinkingLevel = (this.thinkingLevel + 1) % this.thinkingModes.length;
                    localStorage.setItem('thinkingLevel', this.thinkingLevel);
                },

                showError(message) {
                    this.errorMessage = message;
                    this.showErrorModal = true;
                },

                scrollToBottom() {
                    this.$nextTick(() => {
                        const desktop = document.getElementById('messages');
                        const mobile = document.getElementById('messages-mobile');
                        if (desktop) desktop.scrollTop = desktop.scrollHeight;
                        if (mobile) window.scrollTo(0, document.body.scrollHeight);
                    });
                },

                renderMarkdown(text) {
                    if (!text) return '';
                    return marked.parse(text);
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
                                const preview = input.old_string.substring(0, 100);
                                html += `<div><span class="text-blue-300 font-semibold">Find:</span> <pre class="mt-1 text-red-200 bg-red-950/30 px-2 py-1 rounded whitespace-pre-wrap text-xs">${this.escapeHtml(preview)}${input.old_string.length > 100 ? '...' : ''}</pre></div>`;
                            }
                            if (input.new_string) {
                                const preview = input.new_string.substring(0, 100);
                                html += `<div><span class="text-blue-300 font-semibold">Replace:</span> <pre class="mt-1 text-green-200 bg-green-950/30 px-2 py-1 rounded whitespace-pre-wrap text-xs">${this.escapeHtml(preview)}${input.new_string.length > 100 ? '...' : ''}</pre></div>`;
                            }
                        } else {
                            // Generic: show all params
                            for (const [key, value] of Object.entries(input)) {
                                const displayValue = typeof value === 'string' && value.length > 100
                                    ? value.substring(0, 100) + '...'
                                    : JSON.stringify(value);
                                html += `<div><span class="text-blue-300 font-semibold">${this.escapeHtml(key)}:</span> ${this.escapeHtml(displayValue)}</div>`;
                            }
                        }

                        return html;
                    } catch (e) {
                        return `<pre class="text-xs">${this.escapeHtml(msg.content || '')}</pre>`;
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

                savePricing() {
                    localStorage.setItem('modelPricing', JSON.stringify(this.modelPricing));
                },

                updateModelPricing(modelId, field, value) {
                    if (!this.modelPricing[modelId]) {
                        this.modelPricing[modelId] = { ...this.defaultPricing };
                    }
                    this.modelPricing[modelId][field] = parseFloat(value) || 0;
                    this.savePricing();
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
                        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

                        const audioConstraints = isMobile ? {
                            audio: {
                                echoCancellation: true,
                                noiseSuppression: true,
                                autoGainControl: true
                            }
                        } : {
                            audio: {
                                autoGainControl: false,
                                echoCancellation: false,
                                noiseSuppression: false,
                                sampleRate: 16000,
                                channelCount: 1
                            }
                        };

                        const stream = await navigator.mediaDevices.getUserMedia(audioConstraints);

                        const mimeTypes = [
                            'audio/webm;codecs=opus',
                            'audio/webm',
                            'audio/mp4',
                            'audio/ogg;codecs=opus',
                            'audio/wav'
                        ];

                        let selectedMimeType = '';
                        for (const mimeType of mimeTypes) {
                            if (MediaRecorder.isTypeSupported(mimeType)) {
                                selectedMimeType = mimeType;
                                break;
                            }
                        }

                        if (!selectedMimeType) {
                            this.mediaRecorder = new MediaRecorder(stream);
                        } else {
                            this.mediaRecorder = new MediaRecorder(stream, { mimeType: selectedMimeType });
                        }

                        this.audioChunks = [];

                        this.mediaRecorder.ondataavailable = (event) => {
                            if (event.data.size > 0) {
                                this.audioChunks.push(event.data);
                            }
                        };

                        this.mediaRecorder.onstop = async () => {
                            await this.processRecording();
                        };

                        this.mediaRecorder.start(isMobile ? 1000 : undefined);
                        this.isRecording = true;
                    } catch (err) {
                        console.error('Error accessing microphone:', err);
                        this.showError('Could not access microphone. Please check permissions.');
                    }
                },

                stopVoiceRecording() {
                    if (this.mediaRecorder && this.isRecording) {
                        this.mediaRecorder.stop();
                        this.isRecording = false;
                        this.mediaRecorder.stream.getTracks().forEach(track => track.stop());
                    }
                },

                async processRecording() {
                    this.isProcessing = true;

                    try {
                        if (!this.audioChunks || this.audioChunks.length === 0) {
                            this.showError('No audio recorded. Please try again and speak for at least 1 second.');
                            return;
                        }

                        const actualMimeType = this.mediaRecorder.mimeType || 'audio/webm';
                        const audioBlob = new Blob(this.audioChunks, { type: actualMimeType });

                        if (audioBlob.size < 1000) {
                            this.showError('Recording too short. Please record for at least 1 second.');
                            return;
                        }

                        if (audioBlob.size > 10 * 1024 * 1024) {
                            this.showError('Recording too large. Please keep it under 10MB.');
                            return;
                        }

                        let extension = 'webm';
                        if (actualMimeType.includes('mp4')) extension = 'm4a';
                        else if (actualMimeType.includes('mpeg') || actualMimeType.includes('mp3')) extension = 'mp3';
                        else if (actualMimeType.includes('wav')) extension = 'wav';
                        else if (actualMimeType.includes('ogg')) extension = 'ogg';

                        const formData = new FormData();
                        formData.append('audio', audioBlob, `recording.${extension}`);

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
                            return;
                        }

                        const data = await response.json();

                        if (!response.ok) {
                            this.showError('Transcription failed: ' + (data.error || 'Unknown error'));
                            return;
                        }

                        if (data.success && data.transcription) {
                            this.prompt = data.transcription;

                            if (this.autoSendAfterTranscription) {
                                this.autoSendAfterTranscription = false;
                                setTimeout(() => this.sendMessage(), 100);
                            }
                        } else {
                            this.showError('Transcription failed: ' + (data.error || 'Unknown error'));
                        }
                    } catch (err) {
                        console.error('Error processing recording:', err);
                        this.showError('Error processing audio');
                    } finally {
                        this.isProcessing = false;
                    }
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
                    if (this.isProcessing) return '⏳ Processing...';
                    if (this.isRecording) return '⏹️ Stop';
                    return '🎙️ Record';
                },

                get voiceButtonClass() {
                    if (this.isProcessing) return 'bg-gray-600 text-gray-200 cursor-not-allowed';
                    if (this.isRecording) return 'bg-red-600 text-white hover:bg-red-700';
                    return 'bg-purple-600 text-white hover:bg-purple-700';
                }
            };
        }
    </script>
</body>
</html>
