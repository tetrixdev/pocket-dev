<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claude Code Chat</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="antialiased bg-gray-900 text-gray-100">
    <div class="flex h-screen">
        <div class="w-64 bg-gray-800 border-r border-gray-700 flex flex-col">
            <div class="p-4 border-b border-gray-700">
                <h2 class="text-lg font-semibold">Claude Code</h2>
                <button onclick="newSession()" class="mt-2 w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm">New Session</button>
            </div>
            <div class="p-4 border-t border-gray-700 text-xs text-gray-400 mt-auto">
                <div>Project: /var/www</div>
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
                <form onsubmit="sendMessage(event)" class="flex gap-2">
                    <input type="text" id="prompt" placeholder="Ask Claude to help with your code..." class="flex-1 px-4 py-3 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white">
                    <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg">Send</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        const baseUrl = 'http://192.168.1.175';
        let sessionId = null;

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
        checkAuth();

        async function newSession() {
            const response = await fetch(baseUrl + '/api/claude/sessions', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({title: 'New Session', project_path: '/var/www'})
            });
            const data = await response.json();
            sessionId = data.session.id;
            document.getElementById('messages').innerHTML = '<div class="text-center text-gray-400 mt-20"><h3 class="text-xl mb-2">Session Started</h3></div>';
        }

        async function sendMessage(e) {
            e.preventDefault();
            const prompt = document.getElementById('prompt').value;
            if (!prompt.trim()) return;

            if (!sessionId) await newSession();

            // Show user message immediately
            addMsg('user', prompt);
            document.getElementById('prompt').value = '';

            // Prepare for assistant response
            let thinkingMsgId = null;
            let assistantMsgId = null;
            let thinkingContent = '';
            let textContent = '';
            let currentBlockType = null;

            try {
                // Send prompt to start streaming
                const response = await fetch(`${baseUrl}/api/claude/sessions/${sessionId}/stream`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({prompt: prompt})
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
                                    }

                                    // Handle thinking deltas
                                    if (event.type === 'content_block_delta' && event.delta?.type === 'thinking_delta') {
                                        thinkingContent += event.delta.thinking || '';
                                        if (!thinkingMsgId) {
                                            thinkingMsgId = addMsg('thinking', thinkingContent);
                                        } else {
                                            updateMsg(thinkingMsgId, thinkingContent);
                                        }
                                    }

                                    // Handle text deltas
                                    if (event.type === 'content_block_delta' && event.delta?.type === 'text_delta') {
                                        textContent += event.delta.text || '';
                                        if (!assistantMsgId) {
                                            assistantMsgId = addMsg('assistant', textContent);
                                        } else {
                                            updateMsg(assistantMsgId, textContent);
                                        }
                                    }
                                }

                                // Handle complete assistant messages (fallback for non-streaming)
                                if (data.type === 'assistant' && data.message && data.message.content) {
                                    for (const contentItem of data.message.content) {
                                        if (contentItem.type === 'text' && contentItem.text) {
                                            textContent += contentItem.text;
                                            if (!assistantMsgId) {
                                                assistantMsgId = addMsg('assistant', textContent);
                                            } else {
                                                updateMsg(assistantMsgId, textContent);
                                            }
                                        }
                                    }
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

            let html;

            if (isThinking) {
                // Thinking blocks have a distinct collapsible style
                html = `
                    <div id="${id}" class="flex justify-start">
                        <div class="max-w-3xl w-full">
                            <div class="border border-purple-500/30 rounded-lg bg-purple-900/20 overflow-hidden">
                                <div class="flex items-center gap-2 px-4 py-2 bg-purple-900/30 border-b border-purple-500/20 cursor-pointer" onclick="toggleThinking('${id}')">
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
            } else {
                // Regular messages (user/assistant/error)
                const bgColor = isUser ? 'bg-blue-600' : (role === 'error' ? 'bg-red-900' : 'bg-gray-800');
                html = `
                    <div id="${id}" class="flex ${isUser ? 'justify-end' : 'justify-start'}">
                        <div class="max-w-3xl">
                            <div class="px-4 py-3 rounded-lg ${bgColor}">
                                <div class="text-sm whitespace-pre-wrap">${escapeHtml(content)}</div>
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

        function toggleThinking(id) {
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

        function updateMsg(id, content) {
            const msgElement = document.getElementById(id);
            if (msgElement) {
                // Check if this is a thinking block by looking for the -content div
                const thinkingContent = document.getElementById(id + '-content');
                if (thinkingContent) {
                    // It's a thinking block
                    const contentDiv = thinkingContent.querySelector('.whitespace-pre-wrap');
                    if (contentDiv) {
                        contentDiv.textContent = content;
                    }
                } else {
                    // Regular message
                    const contentDiv = msgElement.querySelector('.whitespace-pre-wrap');
                    if (contentDiv) {
                        contentDiv.textContent = content;
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
