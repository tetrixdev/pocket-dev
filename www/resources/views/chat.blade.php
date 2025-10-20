<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claude Code Chat</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Markdown rendering -->
    <script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
    <!-- Code syntax highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <style>
        /* Markdown styling */
        .markdown-content {
            line-height: 1.6;
        }
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
    </style>
</head>
<body class="antialiased bg-gray-900 text-gray-100">
    <div class="flex h-screen">
        <div class="w-64 bg-gray-800 border-r border-gray-700 flex flex-col">
            <div class="p-4 border-b border-gray-700">
                <h2 class="text-lg font-semibold">Claude Code</h2>
                <button onclick="newSession()" class="mt-2 w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm">New Session</button>
            </div>
            <div id="sessions-list" class="flex-1 overflow-y-auto p-2">
                <div class="text-center text-gray-500 text-xs mt-4">Loading sessions...</div>
            </div>
            <div class="p-4 border-t border-gray-700 text-xs text-gray-400">
                <div>Working Dir: /workspace</div>
                <div class="text-gray-500">Access: /workspace, /pocketdev-source</div>
                <a href="/claude/auth" class="text-blue-400 hover:text-blue-300">Auth Settings</a>
            </div>
        </div>
        <div class="flex-1 flex flex-col">
            <div id="messages" class="flex-1 overflow-y-auto p-4 space-y-4">
                <div class="text-center text-gray-400 mt-20">
                    <h3 class="text-xl mb-2">Welcome to Claude Code</h3>
                    <p>Start a conversation to begin AI-powered development</p>
                </div>
            </div>
            <div class="border-t border-gray-700 p-4">
                <form onsubmit="sendMessage(event)" class="flex gap-2 items-stretch">
                    <input type="text" id="prompt" placeholder="Ask Claude to help with your code..." class="flex-1 px-4 py-3 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white">
                    <button type="button" id="thinkingBadge" onclick="cycleThinkingMode()" class="px-4 py-3 rounded-lg font-medium text-sm cursor-pointer transition-all duration-200 hover:opacity-80 flex items-center justify-center" title="Click to toggle extended thinking (or press Shift+T)&#10;Off → Ultrathink (32K tokens)">
                        <span id="thinkingIcon">🧠</span>
                        <span id="thinkingText" class="ml-1">Off</span>
                    </button>
                    <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg flex items-center justify-center">Send</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        const baseUrl = 'http://192.168.1.175';
        let sessionId = null;  // Database session ID
        let claudeSessionId = null;  // Claude UUID for CLI

        // Thinking mode management
        // Only Ultrathink is supported in Claude Code CLI v2.0+ when using --print mode
        const thinkingModes = [
            { level: 0, name: 'Off', icon: '🧠', color: 'bg-gray-600 text-gray-200', keyword: null, budget: 0 },
            { level: 1, name: 'Ultrathink', icon: '🌟', color: 'bg-yellow-600 text-white', keyword: 'ultrathink', budget: 32000 }
        ];

        let currentThinkingLevel = 0;

        // Load thinking mode from localStorage
        const savedThinkingLevel = localStorage.getItem('thinkingLevel');
        if (savedThinkingLevel !== null) {
            currentThinkingLevel = parseInt(savedThinkingLevel);
            updateThinkingBadge();
        }

        // Cycle through thinking modes
        function cycleThinkingMode() {
            currentThinkingLevel = (currentThinkingLevel + 1) % thinkingModes.length;
            updateThinkingBadge();
            localStorage.setItem('thinkingLevel', currentThinkingLevel);
        }

        // Update thinking badge visual
        function updateThinkingBadge() {
            const mode = thinkingModes[currentThinkingLevel];
            const badge = document.getElementById('thinkingBadge');
            const icon = document.getElementById('thinkingIcon');
            const text = document.getElementById('thinkingText');

            // Remove all color classes
            badge.className = badge.className.replace(/bg-\w+-\d+/g, '').replace(/text-\w+-\d+/g, '');

            // Add new color classes
            badge.className += ' ' + mode.color;

            // Update content
            icon.textContent = mode.icon;
            text.textContent = mode.name;
        }

        // Configure marked.js for markdown rendering
        marked.setOptions({
            breaks: true,
            gfm: true,
            highlight: function(code, lang) {
                if (lang && hljs.getLanguage(lang)) {
                    try {
                        return hljs.highlight(code, { language: lang }).value;
                    } catch (err) {}
                }
                return hljs.highlightAuto(code).value;
            }
        });

        // Helper to render markdown
        function renderMarkdown(text) {
            const html = marked.parse(text);
            return html;
        }

        // Check authentication on page load
        async function checkAuth() {
            try {
                const response = await fetch(baseUrl + '/claude/auth/status');
                const data = await response.json();

                if (!data.authenticated) {
                    // Redirect to auth page if not authenticated
                    window.location.href = '/claude/auth';
                    return false;
                }

                return true;
            } catch (err) {
                console.error('Auth check failed:', err);
                // Continue anyway - maybe the auth endpoint is not available
                return true;
            }
        }

        // Initialize on page load
        checkAuth().then(authenticated => {
            if (authenticated) {
                loadSessionsList();
            }
        });

        // Initialize thinking badge on load
        updateThinkingBadge();

        // Add keyboard shortcut for thinking toggle (Shift+T)
        document.getElementById('prompt').addEventListener('keydown', function(e) {
            if (e.shiftKey && e.key.toLowerCase() === 't') {
                e.preventDefault();
                cycleThinkingMode();
            }
        });

        async function loadSessionsList() {
            try {
                const response = await fetch(baseUrl + '/api/claude/claude-sessions?project_path=/');
                const data = await response.json();

                const sessionsList = document.getElementById('sessions-list');

                if (!data.sessions || data.sessions.length === 0) {
                    sessionsList.innerHTML = '<div class="text-center text-gray-500 text-xs mt-4">No sessions yet</div>';
                    return;
                }

                sessionsList.innerHTML = data.sessions.map(session => {
                    const preview = session.prompt.substring(0, 50);
                    const date = new Date(session.modified * 1000).toLocaleDateString();

                    return `
                        <div onclick="loadSession('${escapeHtml(session.id)}')" class="p-2 mb-1 rounded hover:bg-gray-700 cursor-pointer transition-colors">
                            <div class="text-xs text-gray-300 truncate">${escapeHtml(preview)}</div>
                            <div class="text-xs text-gray-500 mt-1">${date}</div>
                        </div>
                    `;
                }).join('');
            } catch (err) {
                console.error('Failed to load sessions:', err);
                document.getElementById('sessions-list').innerHTML = '<div class="text-center text-red-400 text-xs mt-4">Failed to load</div>';
            }
        }

        async function loadSession(loadClaudeSessionId) {
            try {
                const response = await fetch(baseUrl + `/api/claude/claude-sessions/${loadClaudeSessionId}?project_path=/`);
                const data = await response.json();

                if (!data || !data.messages) {
                    console.error('No messages in session');
                    return;
                }

                // Store the Claude session ID for continuing this conversation
                claudeSessionId = loadClaudeSessionId;

                // Try to find existing database session with this claude_session_id
                try {
                    const dbResponse = await fetch(baseUrl + '/api/claude/sessions?project_path=/');
                    const dbData = await dbResponse.json();
                    const existingSession = dbData.data?.find(s => s.claude_session_id === claudeSessionId);
                    if (existingSession) {
                        sessionId = existingSession.id;
                    } else {
                        // Create a database session for this Claude session
                        const createResponse = await fetch(baseUrl + '/api/claude/sessions', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                title: data.messages[0]?.content?.substring(0, 50) || 'Loaded Session',
                                project_path: '/',
                                claude_session_id: claudeSessionId
                            })
                        });
                        const createData = await createResponse.json();
                        sessionId = createData.session.id;
                    }
                } catch (err) {
                    console.warn('Could not create/find database session:', err);
                    sessionId = null;
                }

                // Clear current messages and tool block mapping
                document.getElementById('messages').innerHTML = '';
                Object.keys(loadedToolBlocks).forEach(key => delete loadedToolBlocks[key]);

                // Display all messages from the session, parsing content arrays
                for (const msg of data.messages) {
                    parseAndDisplayMessage(msg.role, msg.content);
                }
            } catch (err) {
                console.error('Failed to load session:', err);
                alert('Failed to load session');
            }
        }

        // Store tool blocks when loading old conversations for result linking
        const loadedToolBlocks = {};

        function parseAndDisplayMessage(role, content) {
            // Content can be a string or an array of content blocks
            if (typeof content === 'string') {
                addMsg(role, content);
                return;
            }

            if (Array.isArray(content)) {
                // Parse each content block
                for (const block of content) {
                    if (block.type === 'text' && block.text) {
                        addMsg(role, block.text);
                    } else if (block.type === 'thinking' && block.thinking) {
                        const thinkingId = addMsg('thinking', block.thinking);
                        // Collapse by default for old messages
                        setTimeout(() => collapseBlock(thinkingId), 10);
                    } else if (block.type === 'tool_use') {
                        const toolData = {
                            name: block.name || 'Unknown Tool',
                            input: JSON.stringify(block.input || {}),
                            result: null
                        };
                        const toolId = addMsg('tool', toolData);
                        // Store for result linking
                        if (block.id) {
                            loadedToolBlocks[block.id] = { toolId, toolData };
                        }
                        // Collapse by default for old messages
                        setTimeout(() => collapseBlock(toolId), 10);
                    } else if (block.type === 'tool_result' && block.tool_use_id) {
                        // Link result to tool block
                        const toolInfo = loadedToolBlocks[block.tool_use_id];
                        if (toolInfo) {
                            toolInfo.toolData.result = block.content;
                            updateMsg(toolInfo.toolId, toolInfo.toolData);
                        }
                    }
                }
                return;
            }

            // Fallback for unexpected format
            addMsg(role, JSON.stringify(content));
        }

        async function newSession() {
            // Create a new database session (which will have a Claude session UUID)
            const response = await fetch(baseUrl + '/api/claude/sessions', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({title: 'New Session', project_path: '/'})
            });
            const data = await response.json();
            sessionId = data.session.id;
            claudeSessionId = data.session.claude_session_id;
            document.getElementById('messages').innerHTML = '<div class="text-center text-gray-400 mt-20"><h3 class="text-xl mb-2">Session Started</h3></div>';
            // Reload the sessions list to show the new session
            setTimeout(() => loadSessionsList(), 500);
        }

        function formatToolCall(content) {
            const toolName = content.name || 'Unknown Tool';
            let inputData = {};

            // Try to parse the input JSON
            try {
                inputData = JSON.parse(content.input || '{}');
            } catch (e) {
                // If parsing fails, treat as incomplete/streaming
                inputData = { _raw: content.input };
            }

            // Create tool-specific title
            let title = toolName;
            if (toolName === 'Bash' && inputData.description) {
                title = `Bash: ${inputData.description}`;
            } else if (toolName === 'Read' && inputData.file_path) {
                title = `Read: ${inputData.file_path}`;
            } else if (toolName === 'Write' && inputData.file_path) {
                title = `Write: ${inputData.file_path}`;
            } else if (toolName === 'Edit' && inputData.file_path) {
                title = `Edit: ${inputData.file_path}`;
            } else if (toolName === 'Glob' && inputData.pattern) {
                title = `Glob: ${inputData.pattern}`;
            } else if (toolName === 'Grep' && inputData.pattern) {
                title = `Grep: ${inputData.pattern}`;
            }

            // Format body with key-value pairs
            let body = '';

            if (inputData._raw) {
                // Streaming/incomplete JSON
                body = `<div class="font-mono text-gray-400">${escapeHtml(inputData._raw)}</div>`;
            } else {
                // Format based on tool type
                if (toolName === 'Bash') {
                    body += `<div><span class="text-blue-300 font-semibold">Command:</span> <code class="text-blue-100 bg-blue-950/50 px-2 py-1 rounded">${escapeHtml(inputData.command || '')}</code></div>`;
                    if (inputData.description) {
                        body += `<div><span class="text-blue-300 font-semibold">Description:</span> ${escapeHtml(inputData.description)}</div>`;
                    }
                } else if (toolName === 'Read') {
                    body += `<div><span class="text-blue-300 font-semibold">File:</span> ${escapeHtml(inputData.file_path || '')}</div>`;
                } else if (toolName === 'Write') {
                    body += `<div><span class="text-blue-300 font-semibold">File:</span> ${escapeHtml(inputData.file_path || '')}</div>`;
                    if (inputData.content) {
                        const preview = inputData.content.length > 200 ? inputData.content.substring(0, 200) + '...' : inputData.content;
                        body += `<div><span class="text-blue-300 font-semibold">Content:</span><pre class="mt-1 text-blue-100 bg-blue-950/50 px-2 py-1 rounded whitespace-pre-wrap">${escapeHtml(preview)}</pre></div>`;
                    }
                } else if (toolName === 'Edit') {
                    body += `<div><span class="text-blue-300 font-semibold">File:</span> ${escapeHtml(inputData.file_path || '')}</div>`;
                    if (inputData.old_string) {
                        body += `<div><span class="text-blue-300 font-semibold">Find:</span><pre class="mt-1 text-red-200 bg-red-950/30 px-2 py-1 rounded whitespace-pre-wrap">${escapeHtml(inputData.old_string.substring(0, 150))}${inputData.old_string.length > 150 ? '...' : ''}</pre></div>`;
                    }
                    if (inputData.new_string) {
                        body += `<div><span class="text-blue-300 font-semibold">Replace:</span><pre class="mt-1 text-green-200 bg-green-950/30 px-2 py-1 rounded whitespace-pre-wrap">${escapeHtml(inputData.new_string.substring(0, 150))}${inputData.new_string.length > 150 ? '...' : ''}</pre></div>`;
                    }
                } else if (toolName === 'Glob') {
                    body += `<div><span class="text-blue-300 font-semibold">Pattern:</span> <code class="text-blue-100">${escapeHtml(inputData.pattern || '')}</code></div>`;
                    if (inputData.path) {
                        body += `<div><span class="text-blue-300 font-semibold">Path:</span> ${escapeHtml(inputData.path)}</div>`;
                    }
                } else if (toolName === 'Grep') {
                    body += `<div><span class="text-blue-300 font-semibold">Pattern:</span> <code class="text-blue-100">${escapeHtml(inputData.pattern || '')}</code></div>`;
                    if (inputData.path) {
                        body += `<div><span class="text-blue-300 font-semibold">Path:</span> ${escapeHtml(inputData.path)}</div>`;
                    }
                    if (inputData.output_mode) {
                        body += `<div><span class="text-blue-300 font-semibold">Mode:</span> ${escapeHtml(inputData.output_mode)}</div>`;
                    }
                } else {
                    // Generic fallback - show all parameters
                    for (const [key, value] of Object.entries(inputData)) {
                        const displayValue = typeof value === 'string' && value.length > 100
                            ? value.substring(0, 100) + '...'
                            : JSON.stringify(value);
                        body += `<div><span class="text-blue-300 font-semibold">${escapeHtml(key)}:</span> ${escapeHtml(displayValue)}</div>`;
                    }
                }
            }

            // Add result section if available
            if (content.result) {
                const resultText = typeof content.result === 'string' ? content.result : JSON.stringify(content.result, null, 2);
                const resultPreview = resultText.length > 500 ? resultText.substring(0, 500) + '...' : resultText;
                body += `<div class="mt-3 pt-3 border-t border-blue-500/20">
                    <div class="text-blue-300 font-semibold mb-1">Result:</div>
                    <pre class="text-green-200 bg-blue-950/50 px-2 py-1 rounded whitespace-pre-wrap text-xs">${escapeHtml(resultPreview)}</pre>
                </div>`;
            }

            return { title, body };
        }

        async function sendMessage(e) {
            e.preventDefault();
            const originalPrompt = document.getElementById('prompt').value;
            if (!originalPrompt.trim()) return;

            if (!sessionId) await newSession();

            // Prepend thinking keyword if thinking is enabled
            const thinkingMode = thinkingModes[currentThinkingLevel];
            const promptToSend = thinkingMode.keyword
                ? `${thinkingMode.keyword}: ${originalPrompt}`
                : originalPrompt;

            // Show original user message (without prefix) in UI
            addMsg('user', originalPrompt);
            document.getElementById('prompt').value = '';

            // Prepare for assistant response
            let thinkingMsgId = null;
            let assistantMsgId = null;
            let thinkingContent = '';
            let textContent = '';
            let currentBlockType = null;
            let lastExpandedBlockId = null;

            // Tool call tracking (can have multiple parallel tools)
            let toolBlocks = {};  // Map block index to {msgId, name, content, toolId}
            let currentBlockIndex = -1;
            let toolBlockMap = {};  // Map tool_use_id to block ID for updating results
            let hasReceivedStreamingDeltas = false;  // Track if we're in streaming mode

            try {
                // Send prompt with thinking keyword to start streaming
                const response = await fetch(`${baseUrl}/api/claude/sessions/${sessionId}/stream`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({prompt: promptToSend})
                });

                // Check if request was successful
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                // Read the stream
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const {done, value} = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, {stream: true});
                    const lines = buffer.split('\n');
                    buffer = lines.pop(); // Keep incomplete line in buffer

                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            try {
                                const data = JSON.parse(line.substring(6));
                                console.log('Stream data:', data);

                                // Handle error in result
                                if (data.type === 'result' && data.is_error) {
                                    if (!assistantMsgId) assistantMsgId = addMsg('assistant', '');
                                    updateMsg(assistantMsgId, 'Error: ' + data.result);
                                    continue;
                                }

                                // Handle streaming events (partial messages)
                                if (data.type === 'stream_event' && data.event) {
                                    const event = data.event;

                                    // Track content block start
                                    if (event.type === 'content_block_start') {
                                        currentBlockType = event.content_block?.type;
                                        currentBlockIndex = event.index ?? currentBlockIndex + 1;

                                        // If starting a new collapsible block (thinking or tool_use), collapse the previous one
                                        if ((currentBlockType === 'thinking' || currentBlockType === 'tool_use') && lastExpandedBlockId) {
                                            collapseBlock(lastExpandedBlockId);
                                        }

                                        // Reset block tracking when starting new content blocks
                                        if (currentBlockType === 'thinking') {
                                            // New thinking block - reset
                                            thinkingContent = '';
                                            thinkingMsgId = null;
                                        } else if (currentBlockType === 'text') {
                                            // New text block - reset
                                            textContent = '';
                                            assistantMsgId = null;
                                        } else if (currentBlockType === 'tool_use') {
                                            // New tool block - initialize tracking for this specific block index
                                            const toolName = event.content_block?.name || 'Unknown Tool';
                                            const toolId = event.content_block?.id || '';
                                            toolBlocks[currentBlockIndex] = {
                                                msgId: null,
                                                name: toolName,
                                                content: '',
                                                toolId: toolId
                                            };
                                        }
                                    }

                                    // Handle thinking deltas
                                    if (event.type === 'content_block_delta' && event.delta?.type === 'thinking_delta') {
                                        hasReceivedStreamingDeltas = true;
                                        thinkingContent += event.delta.thinking || '';
                                        if (!thinkingMsgId) {
                                            thinkingMsgId = addMsg('thinking', thinkingContent);
                                            lastExpandedBlockId = thinkingMsgId;
                                        } else {
                                            updateMsg(thinkingMsgId, thinkingContent);
                                        }
                                    }

                                    // Handle tool use deltas (input_json_delta)
                                    if (event.type === 'content_block_delta' && event.delta?.type === 'input_json_delta') {
                                        hasReceivedStreamingDeltas = true;
                                        const blockIndex = event.index ?? currentBlockIndex;
                                        const toolBlock = toolBlocks[blockIndex];

                                        if (toolBlock) {
                                            toolBlock.content += event.delta.partial_json || '';

                                            if (!toolBlock.msgId) {
                                                // Create new tool block
                                                toolBlock.msgId = addMsg('tool', {
                                                    name: toolBlock.name,
                                                    input: toolBlock.content,
                                                    result: null
                                                });
                                                lastExpandedBlockId = toolBlock.msgId;

                                                // Store mapping for result updates
                                                if (toolBlock.toolId) {
                                                    toolBlockMap[toolBlock.toolId] = toolBlock.msgId;
                                                }
                                            } else {
                                                // Update existing tool block
                                                updateMsg(toolBlock.msgId, {
                                                    name: toolBlock.name,
                                                    input: toolBlock.content,
                                                    result: null
                                                });
                                            }
                                        }
                                    }

                                    // Handle text deltas
                                    if (event.type === 'content_block_delta' && event.delta?.type === 'text_delta') {
                                        hasReceivedStreamingDeltas = true;
                                        textContent += event.delta.text || '';

                                        // Only create block after we have at least some real content (not just whitespace/newlines)
                                        const trimmedContent = textContent.trim();

                                        if (assistantMsgId) {
                                            // Block already exists, just update it
                                            updateMsg(assistantMsgId, textContent);
                                        } else if (trimmedContent.length >= 3) {
                                            // Create new block only when we have substantial content (at least 3 chars)
                                            // This prevents brief empty blocks from appearing

                                            // If we have an expanded block (thinking/tool), collapse it when real text starts
                                            if (lastExpandedBlockId) {
                                                collapseBlock(lastExpandedBlockId);
                                                lastExpandedBlockId = null;
                                            }

                                            assistantMsgId = addMsg('assistant', textContent);
                                        }
                                    }
                                }

                                // Handle tool results (come as user messages)
                                if (data.type === 'user' && data.message && data.message.content) {
                                    for (const item of data.message.content) {
                                        if (item.type === 'tool_result' && item.tool_use_id) {
                                            const msgId = toolBlockMap[item.tool_use_id];
                                            if (msgId) {
                                                // Find the tool block that matches this message ID
                                                let toolBlockData = null;
                                                for (const idx in toolBlocks) {
                                                    if (toolBlocks[idx].msgId === msgId) {
                                                        toolBlockData = toolBlocks[idx];
                                                        break;
                                                    }
                                                }

                                                if (toolBlockData) {
                                                    // Update with result
                                                    const toolData = {
                                                        name: toolBlockData.name,
                                                        input: toolBlockData.content,
                                                        result: item.content
                                                    };
                                                    updateMsg(msgId, toolData);
                                                }
                                            }
                                        }
                                    }
                                }

                                // Handle complete assistant messages (fallback for non-streaming only)
                                if (data.type === 'assistant' && data.message && data.message.content) {
                                    // Only use this if we're NOT in streaming mode and haven't created a block yet
                                    if (!hasReceivedStreamingDeltas && !assistantMsgId) {
                                        let completeText = '';
                                        for (const contentItem of data.message.content) {
                                            if (contentItem.type === 'text' && contentItem.text) {
                                                completeText += contentItem.text;
                                            }
                                        }
                                        // Only create block if there's actual content
                                        if (completeText.trim().length > 0) {
                                            assistantMsgId = addMsg('assistant', completeText);
                                        }
                                    }
                                    // If we're streaming or already have content, ignore this complete message
                                }

                            } catch (parseErr) {
                                console.error('Failed to parse SSE data:', line, parseErr);
                            }
                        }
                    }
                }

                // If we didn't get any content, show error
                if (!textContent && !thinkingContent) {
                    if (!assistantMsgId) assistantMsgId = addMsg('assistant', '');
                    updateMsg(assistantMsgId, 'No response received from Claude');
                }

                // Reload session list after message completes
                loadSessionsList();

            } catch (err) {
                if (!assistantMsgId) assistantMsgId = addMsg('assistant', '');
                updateMsg(assistantMsgId, 'Error: ' + err.message);
                console.error('Stream error:', err);
            }
        }

        function addMsg(role, content) {
            const id = 'msg-' + Date.now() + '-' + Math.random();
            const isUser = role === 'user';
            const isThinking = role === 'thinking';
            const isTool = role === 'tool';

            let html;

            if (isThinking) {
                // Thinking blocks have a distinct collapsible style (plain text, no markdown)
                html = `
                    <div id="${id}" class="flex justify-start">
                        <div class="max-w-3xl w-full">
                            <div class="border border-purple-500/30 rounded-lg bg-purple-900/20 overflow-hidden">
                                <div class="flex items-center gap-2 px-4 py-2 bg-purple-900/30 border-b border-purple-500/20 cursor-pointer" onclick="toggleBlock('${id}')">
                                    <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                    </svg>
                                    <span class="text-sm font-semibold text-purple-300">Thinking</span>
                                    <svg id="${id}-icon" class="w-4 h-4 text-purple-400 ml-auto transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </div>
                                <div id="${id}-content" class="px-4 py-3">
                                    <div class="text-xs text-purple-200 whitespace-pre-wrap font-mono">${escapeHtml(content)}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else if (isTool) {
                // Tool call blocks with smart formatting
                const toolData = formatToolCall(content);
                html = `
                    <div id="${id}" class="flex justify-start">
                        <div class="max-w-3xl w-full">
                            <div class="border border-blue-500/30 rounded-lg bg-blue-900/20 overflow-hidden">
                                <div class="flex items-center gap-2 px-4 py-2 bg-blue-900/30 border-b border-blue-500/20 cursor-pointer" onclick="toggleBlock('${id}')">
                                    <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <span id="${id}-title" class="text-sm font-semibold text-blue-300">${toolData.title}</span>
                                    <svg id="${id}-icon" class="w-4 h-4 text-blue-400 ml-auto transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </div>
                                <div id="${id}-content" class="px-4 py-3">
                                    <div id="${id}-body" class="text-xs text-blue-200 space-y-2">${toolData.body}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                // Regular messages
                const bgColor = isUser ? 'bg-blue-600' : (role === 'error' ? 'bg-red-900' : 'bg-gray-800');
                // User messages: plain text, Assistant messages: markdown
                const renderedContent = isUser ? escapeHtml(content) : renderMarkdown(content);
                html = `
                    <div id="${id}" class="flex ${isUser ? 'justify-end' : 'justify-start'}">
                        <div class="max-w-3xl">
                            <div class="px-4 py-3 rounded-lg ${bgColor}">
                                <div class="text-sm ${isUser ? 'whitespace-pre-wrap' : 'markdown-content'}">${renderedContent}</div>
                            </div>
                        </div>
                    </div>
                `;
            }

            const container = document.getElementById('messages');
            container.innerHTML += html;
            container.scrollTop = container.scrollHeight;

            return id;
        }

        function toggleBlock(id) {
            const content = document.getElementById(id + '-content');
            const icon = document.getElementById(id + '-icon');
            if (content.style.display === 'none') {
                content.style.display = 'block';
                icon.style.transform = 'rotate(0deg)';
            } else {
                content.style.display = 'none';
                icon.style.transform = 'rotate(-90deg)';
            }
        }

        function collapseBlock(id) {
            const content = document.getElementById(id + '-content');
            const icon = document.getElementById(id + '-icon');
            if (content && icon) {
                content.style.display = 'none';
                icon.style.transform = 'rotate(-90deg)';
            }
        }

        function updateMsg(id, content) {
            const msgElement = document.getElementById(id);
            if (msgElement) {
                // Check if this is a collapsible block (thinking or tool) by looking for the -content div
                const blockContent = document.getElementById(id + '-content');
                if (blockContent) {
                    // Check if it's a tool block or thinking block
                    if (typeof content === 'object' && content.name !== undefined) {
                        // Tool block - reformat with updated data
                        const toolData = formatToolCall(content);

                        // Update title
                        const titleSpan = document.getElementById(id + '-title');
                        if (titleSpan) {
                            titleSpan.innerHTML = toolData.title;
                        }

                        // Update body
                        const bodyDiv = document.getElementById(id + '-body');
                        if (bodyDiv) {
                            bodyDiv.innerHTML = toolData.body;
                        }
                    } else {
                        // Thinking block - plain text
                        const contentDiv = blockContent.querySelector('.whitespace-pre-wrap');
                        if (contentDiv) {
                            contentDiv.textContent = content;
                        }
                    }
                } else {
                    // Regular message - check if it's markdown or plain text
                    const markdownDiv = msgElement.querySelector('.markdown-content');
                    const plainTextDiv = msgElement.querySelector('.whitespace-pre-wrap');

                    if (markdownDiv) {
                        // Assistant message - render markdown
                        markdownDiv.innerHTML = renderMarkdown(content);
                    } else if (plainTextDiv) {
                        // User message - plain text
                        plainTextDiv.textContent = content;
                    }
                }

                // Auto-scroll to bottom
                const container = document.getElementById('messages');
                container.scrollTop = container.scrollHeight;
            }
        }

        function escapeHtml(text) {
            return text.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    </script>
</body>
</html>
