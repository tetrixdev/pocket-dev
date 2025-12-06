<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat V2 - Multi-Provider</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        .message-content pre { background: #1e1e1e; padding: 1rem; border-radius: 0.5rem; overflow-x: auto; }
        .message-content code { font-family: 'Fira Code', monospace; }
        .thinking-block { background: #1a1a2e; border-left: 3px solid #6366f1; }
        .tool-block { background: #1a2e1a; border-left: 3px solid #22c55e; }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">
    <div class="max-w-4xl mx-auto p-4">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Chat V2 <span class="text-sm text-gray-500">Multi-Provider API</span></h1>
            <div class="flex items-center gap-4">
                <select id="provider" class="bg-gray-800 border border-gray-700 rounded px-3 py-2">
                    <option value="anthropic">Anthropic</option>
                </select>
                <select id="model" class="bg-gray-800 border border-gray-700 rounded px-3 py-2">
                    <option value="claude-sonnet-4-5-20250929">Claude Sonnet 4.5</option>
                    <option value="claude-opus-4-5-20251101">Claude Opus 4.5</option>
                </select>
                <select id="thinking" class="bg-gray-800 border border-gray-700 rounded px-3 py-2">
                    <option value="0">No Thinking</option>
                    <option value="1">Think</option>
                    <option value="2">Think Hard</option>
                    <option value="3">Think Harder</option>
                    <option value="4">Ultrathink</option>
                </select>
            </div>
        </div>

        <!-- Status bar -->
        <div id="status" class="mb-4 p-3 bg-gray-800 rounded flex items-center justify-between text-sm">
            <span id="status-text">No conversation started</span>
            <span id="token-usage" class="text-gray-500"></span>
        </div>

        <!-- Messages -->
        <div id="messages" class="space-y-4 mb-4 max-h-[60vh] overflow-y-auto">
            <!-- Messages will be added here -->
        </div>

        <!-- Input -->
        <div class="flex gap-2">
            <textarea id="prompt" rows="3" placeholder="Type your message..."
                class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 resize-none focus:outline-none focus:border-indigo-500"></textarea>
            <button id="send" class="bg-indigo-600 hover:bg-indigo-700 px-6 py-3 rounded-lg font-medium transition">
                Send
            </button>
        </div>

        <!-- New conversation button -->
        <div class="mt-4 text-center">
            <button id="new-conv" class="text-gray-500 hover:text-gray-300 text-sm">
                + New Conversation
            </button>
        </div>
    </div>

    <script>
        // State
        let conversationUuid = null;
        let isStreaming = false;
        let totalInputTokens = 0;
        let totalOutputTokens = 0;

        // Elements
        const messagesEl = document.getElementById('messages');
        const promptEl = document.getElementById('prompt');
        const sendBtn = document.getElementById('send');
        const statusText = document.getElementById('status-text');
        const tokenUsage = document.getElementById('token-usage');
        const providerEl = document.getElementById('provider');
        const modelEl = document.getElementById('model');
        const thinkingEl = document.getElementById('thinking');
        const newConvBtn = document.getElementById('new-conv');

        // Initialize marked
        marked.setOptions({
            highlight: function(code, lang) {
                if (lang && hljs.getLanguage(lang)) {
                    return hljs.highlight(code, { language: lang }).value;
                }
                return hljs.highlightAuto(code).value;
            }
        });

        // Create a new conversation
        async function createConversation() {
            const response = await fetch('/api/v2/conversations', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    working_directory: '/var/www',
                    provider_type: providerEl.value,
                    model: modelEl.value,
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to create conversation');
            }

            const data = await response.json();
            conversationUuid = data.conversation.uuid;
            statusText.textContent = `Conversation: ${conversationUuid.substring(0, 8)}...`;
            messagesEl.innerHTML = '';
            totalInputTokens = 0;
            totalOutputTokens = 0;
            updateTokenDisplay();
        }

        // Add a message to the UI
        function addMessage(role, content, type = 'text') {
            const div = document.createElement('div');
            div.className = 'p-4 rounded-lg';

            if (role === 'user') {
                div.className += ' bg-indigo-900/30 ml-12';
            } else if (type === 'thinking') {
                div.className += ' thinking-block pl-4';
            } else if (type === 'tool') {
                div.className += ' tool-block pl-4';
            } else {
                div.className += ' bg-gray-800';
            }

            div.innerHTML = `
                <div class="text-xs text-gray-500 mb-2">${role}${type !== 'text' ? ` (${type})` : ''}</div>
                <div class="message-content prose prose-invert max-w-none">${marked.parse(content || '')}</div>
            `;

            messagesEl.appendChild(div);
            messagesEl.scrollTop = messagesEl.scrollHeight;

            return div;
        }

        // Update message content
        function updateMessage(el, content) {
            const contentEl = el.querySelector('.message-content');
            contentEl.innerHTML = marked.parse(content || '');
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        // Update token display
        function updateTokenDisplay() {
            tokenUsage.textContent = `Tokens: ${totalInputTokens.toLocaleString()} in / ${totalOutputTokens.toLocaleString()} out`;
        }

        // Send message
        async function sendMessage() {
            const prompt = promptEl.value.trim();
            if (!prompt || isStreaming) return;

            // Create conversation if needed
            if (!conversationUuid) {
                await createConversation();
            }

            // Add user message
            addMessage('user', prompt);
            promptEl.value = '';

            // Start streaming
            isStreaming = true;
            sendBtn.disabled = true;
            sendBtn.textContent = 'Streaming...';
            statusText.textContent = 'Streaming response...';

            let currentThinkingEl = null;
            let currentTextEl = null;
            let currentToolEl = null;
            let thinkingContent = '';
            let textContent = '';
            let toolContent = '';

            try {
                const response = await fetch(`/api/v2/conversations/${conversationUuid}/stream`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        prompt,
                        thinking_level: parseInt(thinkingEl.value),
                    }),
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

                            switch (event.type) {
                                case 'thinking_start':
                                    thinkingContent = '';
                                    currentThinkingEl = addMessage('assistant', '', 'thinking');
                                    break;

                                case 'thinking_delta':
                                    thinkingContent += event.content || '';
                                    if (currentThinkingEl) {
                                        updateMessage(currentThinkingEl, thinkingContent);
                                    }
                                    break;

                                case 'text_start':
                                    textContent = '';
                                    currentTextEl = addMessage('assistant', '');
                                    break;

                                case 'text_delta':
                                    textContent += event.content || '';
                                    if (currentTextEl) {
                                        updateMessage(currentTextEl, textContent);
                                    }
                                    break;

                                case 'tool_use_start':
                                    toolContent = `**Tool: ${event.metadata?.tool_name}**\n\n`;
                                    currentToolEl = addMessage('assistant', toolContent, 'tool');
                                    break;

                                case 'tool_use_delta':
                                    toolContent += event.content || '';
                                    if (currentToolEl) {
                                        updateMessage(currentToolEl, toolContent);
                                    }
                                    break;

                                case 'tool_result':
                                    const resultText = `\n\n**Result:**\n\`\`\`\n${event.content}\n\`\`\``;
                                    toolContent += resultText;
                                    if (currentToolEl) {
                                        updateMessage(currentToolEl, toolContent);
                                    }
                                    break;

                                case 'usage':
                                    if (event.metadata) {
                                        totalInputTokens += event.metadata.input_tokens || 0;
                                        totalOutputTokens += event.metadata.output_tokens || 0;
                                        updateTokenDisplay();
                                    }
                                    break;

                                case 'done':
                                    statusText.textContent = `Done (${event.metadata?.stop_reason || 'complete'})`;
                                    break;

                                case 'error':
                                    addMessage('system', `Error: ${event.content}`, 'error');
                                    statusText.textContent = 'Error occurred';
                                    break;
                            }
                        } catch (e) {
                            console.error('Parse error:', e);
                        }
                    }
                }
            } catch (e) {
                addMessage('system', `Error: ${e.message}`, 'error');
                statusText.textContent = 'Request failed';
            } finally {
                isStreaming = false;
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send';
            }
        }

        // Event listeners
        sendBtn.addEventListener('click', sendMessage);
        promptEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        newConvBtn.addEventListener('click', () => {
            conversationUuid = null;
            messagesEl.innerHTML = '';
            totalInputTokens = 0;
            totalOutputTokens = 0;
            statusText.textContent = 'No conversation started';
            updateTokenDisplay();
        });

        // Check provider availability on load
        fetch('/api/v2/providers')
            .then(r => r.json())
            .then(data => {
                if (!data.providers?.anthropic?.available) {
                    statusText.textContent = 'Warning: Anthropic API key not configured';
                    statusText.className += ' text-yellow-500';
                }
            });
    </script>
</body>
</html>
