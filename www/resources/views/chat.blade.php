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
        <!-- Fallback: CDN assets when Vite build is not available -->
        <script src="https://cdn.tailwindcss.com"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @endif

    <!-- Markdown rendering -->
    <script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.6/dist/purify.min.js"
            integrity="sha384-cwS6YdhLI7XS60eoDiC+egV0qHp8zI+Cms46R0nbn8JrmoAzV9uFL60etMZhAnSu"
            crossorigin="anonymous"></script>
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

        /* Mobile safe area */
        .safe-area-bottom {
            padding-bottom: env(safe-area-inset-bottom);
        }

        /* Hide desktop layout on mobile */
        @media (max-width: 767px) {
            .desktop-layout { display: none !important; }

            /* Mobile full-page scroll optimizations */
            html, body {
                scroll-behavior: smooth;
                -webkit-overflow-scrolling: touch;
            }
        }

        /* Hide mobile layout on desktop */
        @media (min-width: 768px) {
            .mobile-layout { display: none !important; }
        }

        /* Mobile drawer transition */
        .drawer-transition {
            transition: transform 0.3s ease-in-out;
        }
    </style>
</head>
<body class="antialiased bg-gray-900 text-gray-100" x-data="appState()" x-init="initVoice()">
    <!-- Desktop Layout -->
    <div class="desktop-layout flex h-screen">
        <div class="w-64 bg-gray-800 border-r border-gray-700 flex flex-col">
            <div class="p-4 border-b border-gray-700">
                <h2 class="text-lg font-semibold">PocketDev</h2>
                <button onclick="newSession()" class="mt-2 w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm">New Session</button>
            </div>
            <div id="sessions-list" class="flex-1 overflow-y-auto p-2">
                <div class="text-center text-gray-500 text-xs mt-4">Loading sessions...</div>
            </div>
            <div class="p-4 border-t border-gray-700 text-xs text-gray-400">
                <div class="mb-3 pb-3 border-b border-gray-700">
                    <div class="flex items-center justify-between mb-1">
                        <div class="flex items-center gap-1">
                            <span class="text-gray-300 font-semibold">Session Cost</span>
                            <button onclick="openPricingModal()" class="text-gray-400 hover:text-gray-200 transition-colors" title="Configure pricing">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </button>
                        </div>
                        <span id="session-cost" class="text-green-400 font-mono">$0.00</span>
                    </div>
                    <div class="text-gray-500" style="font-size: 10px;">
                        <span id="total-tokens">0 tokens</span>
                    </div>
                </div>
                <div>Working Dir: /workspace</div>
                <div class="text-gray-500">Access: /workspace, /pocketdev-source</div>
                <div class="flex gap-2">
                    <a href="/config" class="text-blue-400 hover:text-blue-300">⚙️ Configuration</a>
                    <button @click="showQuickSettings = true; loadQuickSettings()" class="text-blue-400 hover:text-blue-300">⚙️ Quick Settings</button>
                    <button @click="showShortcutsModal = true" class="text-blue-400 hover:text-blue-300">Shortcuts</button>
                </div>
            </div>
        </div>
        <div class="flex-1 flex flex-col">
            <div id="messages" class="flex-1 overflow-y-auto p-4 space-y-4">
                <div class="text-center text-gray-400 mt-20">
                    <h3 class="text-xl mb-2">Welcome to PocketDev</h3>
                    <p>Start a conversation to begin AI-powered development</p>
                </div>
            </div>
            <div class="border-t border-gray-700 p-4">
                <form onsubmit="sendMessage(event)" class="flex gap-2 items-stretch">
                    <!-- Voice Button -->
                    <button type="button"
                            @click="toggleVoiceRecording()"
                            :class="voiceButtonClass"
                            :disabled="isProcessing"
                            class="px-4 py-3 rounded-lg font-medium text-sm flex items-center justify-center"
                            title="Voice input (Ctrl+Space)"
                            x-text="voiceButtonText">
                    </button>

                    <!-- Input Field -->
                    <input type="text"
                           id="prompt"
                           placeholder="Ask Claude to help with your code..."
                           class="flex-1 px-4 py-3 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white"
                           @keydown.ctrl.t.prevent="cycleThinkingMode()"
                           @keydown.ctrl.space.prevent="toggleVoiceRecording()"
                           @keydown.ctrl="if($event.key === '?') { showShortcutsModal = true; $event.preventDefault(); }">

                    <!-- Thinking Toggle -->
                    <button type="button"
                            id="thinkingBadge"
                            onclick="cycleThinkingMode()"
                            class="px-4 py-3 rounded-lg font-medium text-sm cursor-pointer transition-all duration-200 hover:opacity-80 flex items-center justify-center"
                            title="Click to toggle extended thinking (or press Ctrl+T)&#10;Off → Think (4K) → Think Hard (10K) → Think Harder (20K) → Ultrathink (32K)">
                        <span id="thinkingIcon">🧠</span>
                        <span id="thinkingText" class="ml-1">Off</span>
                    </button>

                    <!-- Send Button -->
                    <button type="submit" @click="handleSendClick($event)" class="px-6 py-3 bg-green-600 hover:bg-green-700 rounded-lg flex items-center justify-center">➤ Send</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Mobile Layout -->
    <div class="mobile-layout">
        <!-- Mobile Header (Sticky) -->
        <div class="sticky top-0 z-10 bg-gray-800 border-b border-gray-700 p-4 flex items-center justify-between">
            <button @click="showMobileDrawer = true" class="text-gray-300 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <h2 class="text-lg font-semibold">PocketDev</h2>
            <a href="/config" class="text-gray-300 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            </a>
        </div>

        <!-- Messages Area -->
        <div id="messages-mobile" class="p-4 space-y-4 pb-56 min-h-screen">
            <!-- Messages will be dynamically added here by existing addMsg() function -->
        </div>

        <!-- Fixed Bottom Input (Mobile) -->
        <div class="fixed bottom-0 left-0 right-0 z-20 bg-gray-800 border-t border-gray-700 safe-area-bottom">
            <!-- Input Row -->
            <div class="p-3">
                <input type="text"
                       id="prompt-mobile"
                       placeholder="Ask Claude..."
                       class="w-full px-4 py-3 bg-gray-900 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white"
                       onkeydown="if(event.key === 'Enter') { document.getElementById('prompt').value = this.value; sendMessage(event); this.value = ''; }">
            </div>

            <!-- Controls Row 1: Thinking + Clear -->
            <div class="px-3 pb-2 grid grid-cols-2 gap-2">
                <button type="button"
                        onclick="cycleThinkingMode()"
                        class="px-4 py-3 rounded-lg font-medium text-sm flex items-center justify-center transition-all"
                        id="thinkingBadge-mobile">
                    <span id="thinkingIcon-mobile">🧠</span>
                    <span class="ml-1" id="thinkingText-mobile">Off</span>
                </button>
                <button type="button"
                        onclick="document.getElementById('prompt-mobile').value = ''"
                        class="px-4 py-3 bg-red-600 hover:bg-red-700 rounded-lg font-medium text-sm">
                    🗑️ Clear
                </button>
            </div>

            <!-- Controls Row 2: Voice + Send -->
            <div class="px-3 pb-3 grid grid-cols-2 gap-2">
                <button type="button"
                        @click="toggleVoiceRecording()"
                        :class="voiceButtonClass"
                        :disabled="isProcessing"
                        class="px-4 py-3 rounded-lg font-semibold text-sm flex items-center justify-center"
                        x-text="voiceButtonText">
                </button>
                <button type="button"
                        @click="if(handleSendClick($event)) { const mobilePrompt = document.getElementById('prompt-mobile'); if(mobilePrompt.value.trim()) { document.getElementById('prompt').value = mobilePrompt.value; sendMessage(event); mobilePrompt.value = ''; } }"
                        class="px-4 py-3 bg-green-600 hover:bg-green-700 rounded-lg font-semibold text-sm">
                    ➤ Send
                </button>
            </div>
        </div>

        <!-- Mobile Drawer Overlay -->
        <div x-show="showMobileDrawer"
             @click="showMobileDrawer = false"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-300"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black bg-opacity-50 z-40"
             style="display: none;">
        </div>

        <!-- Mobile Drawer -->
        <div x-show="showMobileDrawer"
             x-transition:enter="transition ease-out duration-300 transform"
             x-transition:enter-start="-translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-300 transform"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="-translate-x-full"
             class="fixed inset-y-0 left-0 w-5/6 max-w-sm bg-gray-800 z-50 flex flex-col"
             style="display: none;">
            <div class="p-4 border-b border-gray-700">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-lg font-semibold">Sessions</h2>
                    <button @click="showMobileDrawer = false" class="text-gray-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <button onclick="newSession()" @click="showMobileDrawer = false" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-sm">
                    New Session
                </button>
            </div>
            <div id="sessions-list-mobile" class="flex-1 overflow-y-auto p-2">
                <!-- Sessions will be populated by loadSessionsList() -->
            </div>
            <div class="p-4 border-t border-gray-700 text-xs text-gray-400">
                <div class="mb-2">Cost: <span id="session-cost-mobile" class="text-green-400 font-mono">$0.00</span></div>
                <div class="mb-2"><span id="total-tokens-mobile">0 tokens</span></div>
                <button @click="showQuickSettings = true; loadQuickSettings(); showMobileDrawer = false" class="text-blue-400 hover:text-blue-300 mr-3">
                    ⚙️ Quick Settings
                </button>
                <button @click="showShortcutsModal = true; showMobileDrawer = false" class="text-blue-400 hover:text-blue-300">
                    View Shortcuts
                </button>
            </div>
        </div>
    </div>

    <!-- OpenAI API Key Modal -->
    <div x-show="showOpenAiModal"
         @click.away="showOpenAiModal = false"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         style="display: none;">
        <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4">
            <h2 class="text-xl font-semibold text-gray-100 mb-4">🎙️ Voice Transcription Setup</h2>
            <p class="text-gray-300 mb-4">OpenAI API key is required for voice transcription. Your key will be encrypted and stored securely.</p>

            <input type="password"
                   x-model="openAiKeyInput"
                   @keydown.enter="saveOpenAiKey()"
                   placeholder="sk-..."
                   class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded text-gray-200 mb-4 focus:outline-none focus:border-blue-500">

            <div class="flex gap-2">
                <button @click="saveOpenAiKey()"
                        :disabled="!openAiKeyInput || openAiKeyInput.length < 20"
                        class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded text-white font-medium">
                    Save Key
                </button>
                <button @click="showOpenAiModal = false; openAiKeyInput = ''"
                        class="flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-gray-200 font-medium">
                    Cancel
                </button>
            </div>

            <p class="text-xs text-gray-500 mt-3">Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank" class="text-blue-400 hover:text-blue-300 underline">OpenAI Platform</a></p>
        </div>
    </div>

    <!-- Quick Settings Modal -->
    <div x-show="showQuickSettings"
         @click.away="showQuickSettings = false"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         style="display: none;">
        <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4">
            <h2 class="text-xl font-semibold text-gray-100 mb-4">⚙️ Quick Settings</h2>

            <div class="space-y-4">
                <!-- Model Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Model</label>
                    <div class="space-y-2">
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="quickSettings.model" value="claude-haiku-4-5-20251001" class="mr-2">
                            Haiku 4.5
                        </label>
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="quickSettings.model" value="claude-sonnet-4-5-20250929" class="mr-2">
                            Sonnet 4.5 (default)
                        </label>
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="quickSettings.model" value="claude-opus-4-5-20251101" class="mr-2">
                            Opus 4.5
                        </label>
                    </div>
                </div>

                <!-- Permission Mode Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Permission Mode</label>
                    <div class="space-y-2">
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="quickSettings.permissionMode" value="default" class="mr-2">
                            Default (prompt me)
                        </label>
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="quickSettings.permissionMode" value="acceptEdits" class="mr-2">
                            Accept Edits (default)
                        </label>
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="quickSettings.permissionMode" value="plan" class="mr-2">
                            Plan Mode (read-only)
                        </label>
                        <label class="flex items-center text-gray-300 cursor-pointer">
                            <input type="radio" x-model="quickSettings.permissionMode" value="bypassPermissions" class="mr-2">
                            Bypass ALL (dangerous!)
                        </label>
                    </div>
                </div>

                <!-- Max Turns Input -->
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Max Turns</label>
                    <input type="number"
                           x-model.number="quickSettings.maxTurns"
                           min="1"
                           max="9999"
                           class="w-full px-3 py-2 bg-gray-700 text-gray-100 rounded-lg border border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-400 mt-1">Default: 50, Max: 9999</p>
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-2 mt-6">
                    <button @click="saveQuickSettings()"
                            class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 rounded-lg font-semibold transition-all">
                        💾 Save
                    </button>
                    <button @click="showQuickSettings = false"
                            class="flex-1 px-4 py-2 bg-gray-600 hover:bg-gray-700 rounded-lg font-semibold transition-all">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Shortcuts Help Modal -->
    <div x-show="showShortcutsModal"
         @click.away="showShortcutsModal = false"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         style="display: none;">
        <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4">
            <h2 class="text-xl font-semibold text-gray-100 mb-4">⌨️ Keyboard Shortcuts</h2>

            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-gray-300">Send Message</span>
                    <kbd class="px-2 py-1 bg-gray-700 rounded text-sm font-mono">Enter</kbd>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-300">Toggle Thinking Mode</span>
                    <kbd class="px-2 py-1 bg-gray-700 rounded text-sm font-mono">Ctrl+T</kbd>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-300">Voice Recording</span>
                    <kbd class="px-2 py-1 bg-gray-700 rounded text-sm font-mono">Ctrl+Space</kbd>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-300">Show This Help</span>
                    <kbd class="px-2 py-1 bg-gray-700 rounded text-sm font-mono">Ctrl+?</kbd>
                </div>
            </div>

            <button @click="showShortcutsModal = false"
                    class="w-full mt-6 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-white font-medium">
                Close
            </button>
        </div>
    </div>

    <script>
        const baseUrl = window.location.origin;  // Dynamic - works from any URL
        let sessionId = null;  // Database session ID
        let claudeSessionId = null;  // Claude UUID for CLI
        const WORKING_DIRECTORY = '/workspace';  // Default working directory for Claude sessions

        // Extract sessionId from URL if present
        const urlPath = window.location.pathname;
        const sessionMatch = urlPath.match(/\/session\/([a-f0-9-]+)/);
        const urlSessionId = sessionMatch ? sessionMatch[1] : null;

        // Thinking mode management
        // Using MAX_THINKING_TOKENS environment variable (official method for headless/print mode)
        const thinkingModes = [
            { level: 0, name: 'Off', icon: '🧠', color: 'bg-gray-600 text-gray-200', tokens: 0 },
            { level: 1, name: 'Think', icon: '💭', color: 'bg-blue-600 text-white', tokens: 4000 },
            { level: 2, name: 'Think Hard', icon: '🤔', color: 'bg-purple-600 text-white', tokens: 10000 },
            { level: 3, name: 'Think Harder', icon: '🧩', color: 'bg-pink-600 text-white', tokens: 20000 },
            { level: 4, name: 'Ultrathink', icon: '🌟', color: 'bg-yellow-600 text-white', tokens: 32000 }
        ];

        let currentThinkingLevel = 0;

        // Load thinking mode from localStorage
        const savedThinkingLevel = localStorage.getItem('thinkingLevel');
        if (savedThinkingLevel !== null) {
            currentThinkingLevel = parseInt(savedThinkingLevel);
            updateThinkingBadge();
        }

        // Cost tracking
        let sessionTotalCost = 0;
        let sessionTotalTokens = 0;

        // Claude 4.5 Sonnet pricing (as of Jan 2025) - default values
        const PRICING = {
            input: 3.00 / 1000000,           // $3 per million input tokens
            output: 15.00 / 1000000,         // $15 per million output tokens
            cacheWriteMultiplier: 1.25,      // Cache write is 1.25× input price
            cacheReadMultiplier: 0.1         // Cache read is 0.1× input price
        };

        // Cycle through thinking modes
        function cycleThinkingMode() {
            currentThinkingLevel = (currentThinkingLevel + 1) % thinkingModes.length;
            updateThinkingBadge();
            localStorage.setItem('thinkingLevel', currentThinkingLevel);
        }

        // Update thinking badge visual
        function updateThinkingBadge() {
            const mode = thinkingModes[currentThinkingLevel];

            // Update desktop badge
            const badge = document.getElementById('thinkingBadge');
            const icon = document.getElementById('thinkingIcon');
            const text = document.getElementById('thinkingText');

            if (badge) {
                badge.className = badge.className.replace(/bg-\w+-\d+/g, '').replace(/text-\w+-\d+/g, '');
                badge.className += ' ' + mode.color;
            }
            if (icon) icon.textContent = mode.icon;
            if (text) text.textContent = mode.name;

            // Update mobile badge
            const mobileBadge = document.getElementById('thinkingBadge-mobile');
            const mobileIcon = document.getElementById('thinkingIcon-mobile');
            const mobileText = document.getElementById('thinkingText-mobile');

            if (mobileBadge) {
                mobileBadge.className = mobileBadge.className.replace(/bg-\w+-\d+/g, '').replace(/text-\w+-\d+/g, '');
                mobileBadge.className += ' ' + mode.color;
            }
            if (mobileIcon) mobileIcon.textContent = mode.icon;
            if (mobileText) mobileText.textContent = mode.name;
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
            if (!text) return '';
            return DOMPurify.sanitize(marked.parse(text));
        }

        // Helper to format timestamp (same format as sidebar)
        function formatTimestamp(timestamp) {
            const date = timestamp ? new Date(timestamp) : new Date();
            const dateStr = date.toLocaleDateString('en-GB').replace(/\//g, '-'); // DD-MM-YYYY format
            const timeStr = date.toLocaleTimeString('en-GB', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            }); // 23:15 format
            return `${dateStr} ${timeStr}`;
        }

        // Helper to calculate cost from usage
        function calculateCost(usage) {
            if (!usage) {
                return 0;
            }

            // Calculate token counts
            const inputTokens = (usage.input_tokens || 0);
            const cacheCreationTokens = (usage.cache_creation_input_tokens || 0);
            const cacheReadTokens = (usage.cache_read_input_tokens || 0);
            const outputTokens = (usage.output_tokens || 0);

            // Apply multipliers correctly
            const inputCost = inputTokens * PRICING.input;
            const cacheWriteCost = cacheCreationTokens * PRICING.input * PRICING.cacheWriteMultiplier;
            const cacheReadCost = cacheReadTokens * PRICING.input * PRICING.cacheReadMultiplier;
            const outputCost = outputTokens * PRICING.output;

            console.log('[COST-CALC]', {
                inputTokens, inputCost: inputCost.toFixed(6),
                cacheCreationTokens, cacheWriteCost: cacheWriteCost.toFixed(6),
                cacheReadTokens, cacheReadCost: cacheReadCost.toFixed(6),
                outputTokens, outputCost: outputCost.toFixed(6),
                total: (inputCost + cacheWriteCost + cacheReadCost + outputCost).toFixed(6)
            });

            return inputCost + cacheWriteCost + cacheReadCost + outputCost;
        }

        // Helper to update session cost display
        function updateSessionCost(additionalCost, additionalTokens) {
            sessionTotalCost += additionalCost;
            sessionTotalTokens += additionalTokens;

            // Update desktop
            const desktopCost = document.getElementById('session-cost');
            const desktopTokens = document.getElementById('total-tokens');
            if (desktopCost) desktopCost.textContent = '$' + sessionTotalCost.toFixed(4);
            if (desktopTokens) desktopTokens.textContent = sessionTotalTokens.toLocaleString() + ' tokens';

            // Update mobile
            const mobileCost = document.getElementById('session-cost-mobile');
            const mobileTokens = document.getElementById('total-tokens-mobile');
            if (mobileCost) mobileCost.textContent = '$' + sessionTotalCost.toFixed(4);
            if (mobileTokens) mobileTokens.textContent = sessionTotalTokens.toLocaleString() + ' tokens';
        }

        // Helper to reset session cost
        function resetSessionCost() {
            sessionTotalCost = 0;
            sessionTotalTokens = 0;

            // Reset desktop
            const desktopCost = document.getElementById('session-cost');
            const desktopTokens = document.getElementById('total-tokens');
            if (desktopCost) desktopCost.textContent = '$0.00';
            if (desktopTokens) desktopTokens.textContent = '0 tokens';

            // Reset mobile
            const mobileCost = document.getElementById('session-cost-mobile');
            const mobileTokens = document.getElementById('total-tokens-mobile');
            if (mobileCost) mobileCost.textContent = '$0.00';
            if (mobileTokens) mobileTokens.textContent = '0 tokens';
        }

        // Helper to clear messages from both desktop and mobile containers
        function clearMessages(welcomeMessage = null) {
            const desktop = document.getElementById('messages');
            const mobile = document.getElementById('messages-mobile');

            const html = welcomeMessage || '';

            if (desktop) desktop.innerHTML = html;
            if (mobile) mobile.innerHTML = html;
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
                // Auto-load session if URL contains session ID
                if (urlSessionId) {
                    loadSession(urlSessionId);
                }
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

        // Handle browser back/forward buttons
        window.addEventListener('popstate', function(event) {
            if (event.state && event.state.sessionId) {
                loadSession(event.state.sessionId);
            } else {
                // Back to home - reload page to show welcome screen
                window.location.reload();
            }
        });

        async function loadSessionsList() {
            try {
                const response = await fetch(baseUrl + `/api/claude/claude-sessions?project_path=${WORKING_DIRECTORY}`);
                const data = await response.json();

                const sessionsList = document.getElementById('sessions-list');
                const sessionsListMobile = document.getElementById('sessions-list-mobile');

                if (!data.sessions || data.sessions.length === 0) {
                    const emptyMessage = '<div class="text-center text-gray-500 text-xs mt-4">No sessions yet</div>';
                    if (sessionsList) sessionsList.innerHTML = emptyMessage;
                    if (sessionsListMobile) sessionsListMobile.innerHTML = emptyMessage;
                    return;
                }

                const sessionsHtml = data.sessions.map(session => {
                    const preview = session.prompt.substring(0, 50);
                    const datetime = new Date(session.modified * 1000);
                    const dateStr = datetime.toLocaleDateString('en-GB').replace(/\//g, '-'); // DD-MM-YYYY format
                    const timeStr = datetime.toLocaleTimeString('en-GB', {
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: false
                    }); // 23:15 format
                    const display = `${dateStr} ${timeStr}`;

                    return `
                        <div onclick="loadSession('${escapeHtml(session.id)}')" class="p-2 mb-1 rounded hover:bg-gray-700 cursor-pointer transition-colors">
                            <div class="text-xs text-gray-300 truncate">${escapeHtml(preview)}</div>
                            <div class="text-xs text-gray-500 mt-1">${display}</div>
                        </div>
                    `;
                }).join('');

                // Update both desktop and mobile sessions lists
                if (sessionsList) sessionsList.innerHTML = sessionsHtml;
                if (sessionsListMobile) sessionsListMobile.innerHTML = sessionsHtml;
            } catch (err) {
                console.error('Failed to load sessions:', err);
                const errorMessage = '<div class="text-center text-red-400 text-xs mt-4">Failed to load</div>';
                const sessionsList = document.getElementById('sessions-list');
                const sessionsListMobile = document.getElementById('sessions-list-mobile');
                if (sessionsList) sessionsList.innerHTML = errorMessage;
                if (sessionsListMobile) sessionsListMobile.innerHTML = errorMessage;
            }
        }

        async function loadSession(loadClaudeSessionId) {
            try {
                console.log('[FRONTEND-LOAD] Loading session:', loadClaudeSessionId);
                const response = await fetch(baseUrl + `/api/claude/claude-sessions/${loadClaudeSessionId}?project_path=${WORKING_DIRECTORY}`);
                const data = await response.json();

                if (!data || !data.messages) {
                    console.error('No messages in session');
                    return;
                }

                console.log('[FRONTEND-LOAD] Received messages from .jsonl:', {
                    message_count: data.messages.length,
                    messages: data.messages.map((m, idx) => ({
                        index: idx,
                        role: m.role,
                        content_type: typeof m.content,
                        content_preview: typeof m.content === 'string'
                            ? m.content.substring(0, 50)
                            : Array.isArray(m.content)
                                ? m.content.map(b => b.type).join(',')
                                : 'unknown'
                    }))
                });

                // Store the Claude session ID for continuing this conversation
                claudeSessionId = loadClaudeSessionId;

                // Try to find existing database session with this claude_session_id
                try {
                    const dbResponse = await fetch(baseUrl + `/api/claude/sessions?project_path=${WORKING_DIRECTORY}`);
                    const dbData = await dbResponse.json();
                    const existingSession = dbData.data?.find(s => s.claude_session_id === claudeSessionId);
                    if (existingSession) {
                        sessionId = existingSession.id;
                        console.log('[FRONTEND-LOAD] Found existing DB session:', sessionId);
                    } else {
                        // Create a database session for this Claude session
                        const createResponse = await fetch(baseUrl + '/api/claude/sessions', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                title: data.messages[0]?.content?.substring(0, 50) || 'Loaded Session',
                                project_path: WORKING_DIRECTORY,
                                claude_session_id: claudeSessionId
                            })
                        });
                        const createData = await createResponse.json();
                        sessionId = createData.session.id;
                        console.log('[FRONTEND-LOAD] Created new DB session:', sessionId);
                    }
                } catch (err) {
                    console.warn('Could not create/find database session:', err);
                    sessionId = null;
                }

                // Clear current messages and tool block mapping
                clearMessages();
                Object.keys(loadedToolBlocks).forEach(key => delete loadedToolBlocks[key]);

                // Reset session cost before loading (will be recalculated from message usage data)
                resetSessionCost();

                console.log('[FRONTEND-LOAD] Displaying messages from .jsonl file');
                console.log('[FRONTEND-LOAD] Sample message data:', data.messages[0]);

                // Display all messages from the session, parsing content arrays
                for (const msg of data.messages) {
                    console.log('[FRONTEND-LOAD] Processing message:', {
                        role: msg.role,
                        hasUsage: !!msg.usage,
                        hasModel: !!msg.model,
                        cost: msg.cost,
                        costType: typeof msg.cost
                    });
                    parseAndDisplayMessage(msg.role, msg.content, msg.timestamp, msg.usage, msg.model, msg.cost);
                }
                console.log('[FRONTEND-LOAD] Finished loading session');

                // Update URL without page reload
                window.history.pushState(
                    {sessionId: loadClaudeSessionId},
                    '',
                    `/session/${loadClaudeSessionId}`
                );
            } catch (err) {
                console.error('Failed to load session:', err);
                alert('Failed to load session');
            }
        }

        // Store tool blocks when loading old conversations for result linking
        const loadedToolBlocks = {};

        function parseAndDisplayMessage(role, content, timestamp = null, usage = null, model = null, cost = null) {
            // Use server-calculated cost (no client-side calculation needed)
            if (cost !== null && cost !== undefined) {
                // Calculate total tokens including cache tokens
                const totalTokens = (usage.input_tokens || 0) +
                                  (usage.cache_creation_input_tokens || 0) +
                                  (usage.cache_read_input_tokens || 0) +
                                  (usage.output_tokens || 0);
                // Add to session total for historical messages
                updateSessionCost(cost, totalTokens);

                console.log('[FRONTEND-LOAD] Historical message cost (server-calculated):', {
                    role,
                    usage,
                    cost,
                    totalTokens,
                    model
                });
            }

            // Content can be a string or an array of content blocks
            if (typeof content === 'string') {
                addMsg(role, content, timestamp, cost, usage, model);
                return;
            }

            if (Array.isArray(content)) {
                // Parse each content block
                for (const block of content) {
                    if (block.type === 'text' && block.text) {
                        // Only add cost to the first text block (the main message)
                        addMsg(role, block.text, timestamp, cost, usage, model);
                        // Set cost/usage to null after first block to avoid duplicates
                        cost = null;
                        usage = null;
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
            addMsg(role, JSON.stringify(content), timestamp, cost);
        }

        async function newSession() {
            // Create a new database session (which will have a Claude session UUID)
            const response = await fetch(baseUrl + '/api/claude/sessions', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({title: 'New Session', project_path: WORKING_DIRECTORY})
            });
            const data = await response.json();
            sessionId = data.session.id;
            claudeSessionId = data.session.claude_session_id;

            // Update URL to reflect new session
            window.history.pushState(
                {sessionId: claudeSessionId},
                '',
                `/session/${claudeSessionId}`
            );

            // Reset session cost for new session
            resetSessionCost();

            // Clear messages and show welcome message
            clearMessages('<div class="text-center text-gray-400 mt-20"><h3 class="text-xl mb-2">Session Started</h3></div>');

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

            console.log('[FRONTEND-SEND] Sending new message', {
                sessionId,
                claudeSessionId,
                prompt_preview: originalPrompt.substring(0, 50)
            });

            // Show user message in UI
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

            // Usage and cost tracking for this message
            let messageUsage = null;
            let messageCost = 0;

            try {
                console.log('[FRONTEND-SEND] Initiating streaming request');
                // Send prompt with thinking level to start streaming
                const response = await fetch(`${baseUrl}/api/claude/sessions/${sessionId}/stream`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        prompt: originalPrompt,
                        thinking_level: currentThinkingLevel
                    })
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
                                console.log('[FRONTEND-SSE] Received SSE event:', {
                                    type: data.type,
                                    event_type: data.event?.type,
                                    is_error: data.is_error,
                                    message_role: data.message?.role,
                                    data_preview: JSON.stringify(data).substring(0, 100)
                                });

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

                                    // Handle usage data (message_delta with usage field)
                                    if (event.type === 'message_delta' && event.usage) {
                                        messageUsage = event.usage;
                                        console.log('[FRONTEND-SSE] Received usage data:', messageUsage);
                                    }

                                    // Handle message_stop event (also may contain usage)
                                    if (event.type === 'message_stop') {
                                        console.log('[FRONTEND-SSE] Message stopped');
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

                // Calculate and update cost if we have usage data
                if (messageUsage && assistantMsgId) {
                    messageCost = calculateCost(messageUsage);
                    // Calculate total tokens including cache tokens
                    const totalTokens = (messageUsage.input_tokens || 0) +
                                      (messageUsage.cache_creation_input_tokens || 0) +
                                      (messageUsage.cache_read_input_tokens || 0) +
                                      (messageUsage.output_tokens || 0);

                    // Get current model from config
                    const currentModel = 'claude-sonnet-4-5-20250929'; // Default, will be from response in future

                    // Store usage data for breakdown modal
                    window.messageUsageData = window.messageUsageData || {};
                    window.messageUsageData[assistantMsgId] = {
                        usage: messageUsage,
                        model: currentModel,
                        cost: messageCost
                    };

                    console.log('[FRONTEND-COST] Message cost calculated:', {
                        usage: messageUsage,
                        cost: messageCost,
                        totalTokens: totalTokens,
                        model: currentModel
                    });

                    // Update session totals
                    updateSessionCost(messageCost, totalTokens);

                    // Add cost to the assistant message (both desktop and mobile)
                    const costText = `$${messageCost.toFixed(4)}`;
                    const costHtml = `<span class="text-gray-500 px-2">•</span><span class="text-green-400">${costText}</span>` +
                        `<button onclick="showCostBreakdown('${assistantMsgId}')" class="text-gray-500 hover:text-gray-300 transition-colors ml-2" title="View cost breakdown">
                            <svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </button>`;

                    // Update cost in both desktop and mobile containers
                    const containers = ['messages', 'messages-mobile'];
                    containers.forEach(containerId => {
                        const container = document.getElementById(containerId);
                        if (!container) return;

                        const msgElement = container.querySelector('#' + assistantMsgId);
                        if (msgElement) {
                            const metadataDiv = msgElement.querySelector('.text-xs.mt-2');
                            if (metadataDiv) {
                                const currentContent = metadataDiv.innerHTML;
                                metadataDiv.innerHTML = currentContent + costHtml;
                            }
                        }
                    });
                }

                // Reload session list after message completes
                loadSessionsList();

            } catch (err) {
                if (!assistantMsgId) assistantMsgId = addMsg('assistant', '');
                updateMsg(assistantMsgId, 'Error: ' + err.message);
                console.error('Stream error:', err);
            }
        }

        function addMsg(role, content, timestamp = null, cost = null, usage = null, model = null) {
            const id = 'msg-' + Date.now() + '-' + Math.floor(Math.random() * 1000000000);
            const isUser = role === 'user';
            const isThinking = role === 'thinking';
            const isTool = role === 'tool';

            console.log('[FRONTEND-UI] addMsg called', {
                role,
                content_preview: typeof content === 'string' ? content.substring(0, 50) : typeof content,
                timestamp,
                cost,
                usage,
                model,
                stack: new Error().stack.split('\n')[2] // Show caller
            });

            // Store usage data for breakdown modal
            if (usage && model) {
                window.messageUsageData = window.messageUsageData || {};
                window.messageUsageData[id] = { usage, model, cost };
            }

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

                // Format timestamp and cost
                const timestampText = formatTimestamp(timestamp);
                const costText = cost !== null && cost !== undefined ? `$${cost.toFixed(4)}` : null;

                console.log('[FRONTEND-UI] Message metadata:', { timestamp, cost, usage, model, hasCost: !!costText });

                // Build metadata string
                let metadata = `<span class="text-gray-400">${timestampText}</span>`;
                if (costText) {
                    metadata += `<span class="text-gray-500 px-2">•</span><span class="text-green-400">${costText}</span>`;
                    // Add cog icon if we have usage data
                    if (usage && model) {
                        console.log('[FRONTEND-UI] Adding cog icon for message:', id);
                        metadata += `<button onclick="showCostBreakdown('${id}')" class="inline-flex items-center text-gray-500 hover:text-gray-300 transition-colors ml-1" title="View cost breakdown">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </button>`;
                    }
                }

                html = `
                    <div id="${id}" class="flex ${isUser ? 'justify-end' : 'justify-start'}">
                        <div class="max-w-3xl">
                            <div class="px-4 py-3 rounded-lg ${bgColor}">
                                <div class="text-sm ${isUser ? 'whitespace-pre-wrap' : 'markdown-content'}">${renderedContent}</div>
                                <div class="text-xs mt-2 flex ${isUser ? 'justify-end' : 'justify-start'}">${metadata}</div>
                            </div>
                        </div>
                    </div>
                `;
            }

            // Add to desktop messages
            const container = document.getElementById('messages');
            if (container) {
                container.innerHTML += html;
                container.scrollTop = container.scrollHeight;
            }

            // Also add to mobile messages
            const mobileContainer = document.getElementById('messages-mobile');
            if (mobileContainer) {
                mobileContainer.innerHTML += html;
                // For mobile, scroll the window instead of the container (full-page scroll)
                setTimeout(() => window.scrollTo(0, document.body.scrollHeight), 0);
            }

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
            // Update message in both desktop and mobile containers
            const containers = ['messages', 'messages-mobile'];

            containers.forEach(containerId => {
                const container = document.getElementById(containerId);
                if (!container) return;

                const msgElement = container.querySelector('#' + id);
                if (!msgElement) return;

                // Check if this is a collapsible block (thinking or tool) by looking for the -content div
                const blockContent = msgElement.querySelector('#' + id + '-content');
                if (blockContent) {
                    // Check if it's a tool block or thinking block
                    if (typeof content === 'object' && content.name !== undefined) {
                        // Tool block - reformat with updated data
                        const toolData = formatToolCall(content);

                        // Update title
                        const titleSpan = msgElement.querySelector('#' + id + '-title');
                        if (titleSpan) {
                            titleSpan.innerHTML = toolData.title;
                        }

                        // Update body
                        const bodyDiv = msgElement.querySelector('#' + id + '-body');
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
                if (containerId === 'messages-mobile') {
                    // For mobile with full-page scroll, scroll the window
                    window.scrollTo(0, document.body.scrollHeight);
                } else {
                    // For desktop, scroll the container
                    container.scrollTop = container.scrollHeight;
                }
            });
        }

        function escapeHtml(text) {
            return text.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // Cost breakdown modal
        async function showCostBreakdown(messageId) {
            const data = window.messageUsageData?.[messageId];
            if (!data) {
                console.error('No usage data found for message:', messageId);
                return;
            }

            const { usage, model, cost } = data;

            // Fetch pricing for this model
            const response = await fetch(`${baseUrl}/api/pricing/${encodeURIComponent(model)}`);
            const pricing = await response.json();

            // Calculate breakdown
            const inputTokens = usage.input_tokens || 0;
            const cacheWriteTokens = usage.cache_creation_input_tokens || 0;
            const cacheReadTokens = usage.cache_read_input_tokens || 0;
            const outputTokens = usage.output_tokens || 0;

            const inputPrice = pricing.input_price_per_million || 0;
            const outputPrice = pricing.output_price_per_million || 0;
            const cacheWriteMult = pricing.cache_write_multiplier || 1.25;
            const cacheReadMult = pricing.cache_read_multiplier || 0.1;

            // Calculate costs
            const inputCost = (inputTokens / 1000000) * inputPrice;
            const cacheWriteCost = (cacheWriteTokens / 1000000) * inputPrice * cacheWriteMult;
            const cacheReadCost = (cacheReadTokens / 1000000) * inputPrice * cacheReadMult;
            const outputCost = (outputTokens / 1000000) * outputPrice;
            const totalCost = inputCost + cacheWriteCost + cacheReadCost + outputCost;

            // Populate breakdown modal
            document.getElementById('breakdown-model').textContent = model;
            document.getElementById('breakdown-input-tokens').textContent = inputTokens.toLocaleString();
            document.getElementById('breakdown-input-cost').textContent = `$${inputCost.toFixed(6)}`;
            document.getElementById('breakdown-cache-write-tokens').textContent = cacheWriteTokens.toLocaleString();
            document.getElementById('breakdown-cache-write-cost').textContent = `$${cacheWriteCost.toFixed(6)}`;
            document.getElementById('breakdown-cache-read-tokens').textContent = cacheReadTokens.toLocaleString();
            document.getElementById('breakdown-cache-read-cost').textContent = `$${cacheReadCost.toFixed(6)}`;
            document.getElementById('breakdown-output-tokens').textContent = outputTokens.toLocaleString();
            document.getElementById('breakdown-output-cost').textContent = `$${outputCost.toFixed(6)}`;
            document.getElementById('breakdown-total-cost').textContent = `$${totalCost.toFixed(4)}`;

            // Store current model for editing
            window.currentBreakdownModel = model;

            // Show modal
            document.getElementById('breakdown-modal').classList.remove('hidden');
        }

        function closeBreakdownModal() {
            document.getElementById('breakdown-modal').classList.add('hidden');
        }

        function openPricingFromBreakdown() {
            closeBreakdownModal();
            currentPricingModel = window.currentBreakdownModel;
            openPricingModal();
        }

        // Pricing modal management
        let currentPricingModel = null;

        async function openPricingModal() {
            try {
                // Get the current model being used (from last message or default)
                if (!currentPricingModel) {
                    currentPricingModel = 'claude-sonnet-4-5-20250929';
                }

                console.log('[PRICING] Opening pricing modal for model:', currentPricingModel);

                // Fetch current pricing
                const response = await fetch(`${baseUrl}/api/pricing/${encodeURIComponent(currentPricingModel)}`);
                const pricing = await response.json();

                console.log('[PRICING] Fetched pricing:', pricing);

                // Populate modal
                document.getElementById('pricing-model-name').textContent = currentPricingModel;
                document.getElementById('input-price').value = pricing.input_price_per_million || '';
                document.getElementById('cache-write-multiplier').value = pricing.cache_write_multiplier || 1.25;
                document.getElementById('cache-read-multiplier').value = pricing.cache_read_multiplier || 0.1;
                document.getElementById('output-price').value = pricing.output_price_per_million || '';

                // Show modal
                document.getElementById('pricing-modal').classList.remove('hidden');
            } catch (err) {
                console.error('[PRICING] Error opening modal:', err);
                alert('Error loading pricing settings: ' + err.message);
            }
        }

        function closePricingModal() {
            document.getElementById('pricing-modal').classList.add('hidden');
        }

        async function savePricing() {
            const inputPrice = parseFloat(document.getElementById('input-price').value);
            const cacheWriteMultiplier = parseFloat(document.getElementById('cache-write-multiplier').value);
            const cacheReadMultiplier = parseFloat(document.getElementById('cache-read-multiplier').value);
            const outputPrice = parseFloat(document.getElementById('output-price').value);

            if (isNaN(inputPrice) || isNaN(outputPrice)) {
                alert('Please enter valid pricing values');
                return;
            }

            try {
                const response = await fetch(`${baseUrl}/api/pricing/${encodeURIComponent(currentPricingModel)}`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        input_price_per_million: inputPrice,
                        cache_write_multiplier: cacheWriteMultiplier,
                        cache_read_multiplier: cacheReadMultiplier,
                        output_price_per_million: outputPrice
                    })
                });

                if (response.ok) {
                    // Update pricing in memory and reload costs
                    PRICING.input = inputPrice / 1000000;
                    PRICING.output = outputPrice / 1000000;
                    PRICING.cacheWriteMultiplier = cacheWriteMultiplier;
                    PRICING.cacheReadMultiplier = cacheReadMultiplier;

                    alert('Pricing saved successfully!');
                    closePricingModal();

                    // Optionally reload the session to recalculate costs
                    if (claudeSessionId) {
                        loadSession(claudeSessionId);
                    }
                } else {
                    alert('Failed to save pricing');
                }
            } catch (err) {
                console.error('Error saving pricing:', err);
                alert('Error saving pricing');
            }
        }

        // Alpine.js State Management for Voice Recording and Modals
        function appState() {
            return {
                // Voice recording state
                isRecording: false,
                isProcessing: false,
                mediaRecorder: null,
                audioChunks: [],
                openAiKeyConfigured: false,
                autoSendAfterTranscription: false,

                // Modal state
                showOpenAiModal: false,
                showShortcutsModal: false,
                showMobileDrawer: false,
                openAiKeyInput: '',

                // Quick settings state
                showQuickSettings: false,
                quickSettings: {
                    model: 'claude-sonnet-4-5-20250929',
                    permissionMode: 'acceptEdits',
                    maxTurns: 50
                },

                // Initialize voice recording
                async initVoice() {
                    // Check if OpenAI key is configured
                    try {
                        const response = await fetch(baseUrl + '/api/claude/openai-key/check');
                        const data = await response.json();
                        this.openAiKeyConfigured = data.configured;
                    } catch (err) {
                        console.error('OpenAI key check failed:', err);
                    }
                },

                // Voice recording methods
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
                        // Detect if mobile device
                        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

                        // Desktop: Use strict settings for better quality
                        // Mobile: Use permissive settings for better compatibility
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

                        // Try different MIME types in order of preference
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
                            this.mediaRecorder = new MediaRecorder(stream, {
                                mimeType: selectedMimeType
                            });
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

                        // Start recording with timeslice for mobile compatibility
                        this.mediaRecorder.start(isMobile ? 1000 : undefined);
                        this.isRecording = true;
                    } catch (err) {
                        console.error('Error accessing microphone:', err);
                        alert('Could not access microphone. Please check permissions.');
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
                        // Validate that we have audio data
                        if (!this.audioChunks || this.audioChunks.length === 0) {
                            alert('No audio recorded. Please try again and speak for at least 1 second.');
                            return;
                        }

                        // Get the actual MIME type used by MediaRecorder
                        const actualMimeType = this.mediaRecorder.mimeType || 'audio/webm';

                        // Create blob with the actual MIME type
                        const audioBlob = new Blob(this.audioChunks, { type: actualMimeType });

                        // Validate minimum file size (at least 1KB)
                        if (audioBlob.size < 1000) {
                            alert('Recording too short. Please record for at least 1 second.');
                            return;
                        }

                        // Validate maximum file size (10MB as per backend validation)
                        if (audioBlob.size > 10 * 1024 * 1024) {
                            alert('Recording too large. Please keep it under 10MB.');
                            return;
                        }

                        // Generate appropriate file extension based on MIME type
                        let extension = 'webm';
                        if (actualMimeType.includes('mp4')) {
                            extension = 'm4a';
                        } else if (actualMimeType.includes('mpeg') || actualMimeType.includes('mp3')) {
                            extension = 'mp3';
                        } else if (actualMimeType.includes('wav')) {
                            extension = 'wav';
                        } else if (actualMimeType.includes('ogg')) {
                            extension = 'ogg';
                        }

                        const filename = `recording.${extension}`;

                        const formData = new FormData();
                        formData.append('audio', audioBlob, filename);

                        const response = await fetch(baseUrl + '/api/claude/transcribe', {
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
                            alert('Transcription failed: ' + (data.error || 'Unknown error'));
                            return;
                        }

                        if (data.success && data.transcription) {
                            // Insert transcription into both desktop and mobile prompt fields
                            const desktopPrompt = document.getElementById('prompt');
                            const mobilePrompt = document.getElementById('prompt-mobile');
                            if (desktopPrompt) desktopPrompt.value = data.transcription;
                            if (mobilePrompt) mobilePrompt.value = data.transcription;

                            // Auto-send if flag is set
                            if (this.autoSendAfterTranscription) {
                                this.autoSendAfterTranscription = false; // Reset flag
                                // Trigger send
                                setTimeout(() => {
                                    const form = document.querySelector('form[onsubmit*="sendMessage"]');
                                    if (form) {
                                        sendMessage(new Event('submit'));
                                    }
                                    // Clear mobile prompt after send
                                    const mobilePrompt = document.getElementById('prompt-mobile');
                                    if (mobilePrompt) mobilePrompt.value = '';
                                }, 100); // Small delay to ensure input is populated
                            }
                        } else {
                            alert('Transcription failed: ' + (data.error || 'Unknown error'));
                        }
                    } catch (err) {
                        console.error('Error processing recording:', err);
                        alert('Error processing audio');
                    } finally {
                        this.isProcessing = false;
                    }
                },

                // OpenAI key management
                async saveOpenAiKey() {
                    if (!this.openAiKeyInput || this.openAiKeyInput.length < 20) {
                        alert('Please enter a valid OpenAI API key');
                        return;
                    }

                    try {
                        const response = await fetch(baseUrl + '/api/claude/openai-key', {
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
                            alert('OpenAI API key saved successfully!');
                            await this.startVoiceRecording();
                        } else {
                            alert('Failed to save API key');
                        }
                    } catch (err) {
                        console.error('Error saving API key:', err);
                        alert('Error saving API key');
                    }
                },

                // Quick settings methods
                async loadQuickSettings() {
                    try {
                        const response = await fetch(baseUrl + '/api/claude/quick-settings');
                        const data = await response.json();
                        this.quickSettings = {
                            model: data.model,
                            permissionMode: data.permissionMode,
                            maxTurns: data.maxTurns
                        };
                    } catch (err) {
                        console.error('Error loading quick settings:', err);
                    }
                },

                async saveQuickSettings() {
                    try {
                        const response = await fetch(baseUrl + '/api/claude/quick-settings', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify(this.quickSettings)
                        });

                        if (response.ok) {
                            this.showQuickSettings = false;
                            alert('Quick settings saved successfully!');
                        } else {
                            alert('Failed to save settings');
                        }
                    } catch (err) {
                        console.error('Error saving quick settings:', err);
                        alert('Error saving settings');
                    }
                },

                // Handle send button click - stop recording if active and auto-send
                handleSendClick(event) {
                    if (this.isRecording) {
                        // Stop recording and set auto-send flag
                        event.preventDefault();
                        this.autoSendAfterTranscription = true;
                        this.stopVoiceRecording();
                        return false;
                    }
                    // Otherwise, let the normal send happen
                    return true;
                },

                // Computed properties for button styling
                get voiceButtonText() {
                    if (this.isProcessing) return '⏳ Processing...';
                    if (this.isRecording) return '⏹️ Stop Recording';
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

    <!-- Cost Breakdown Modal -->
    <div id="breakdown-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-gray-800 rounded-lg p-6 max-w-lg w-full mx-4">
            <h2 class="text-xl font-semibold text-gray-100 mb-4">💰 Cost Breakdown</h2>

            <div class="mb-4">
                <div class="text-sm text-gray-400 mb-2">Model:</div>
                <div id="breakdown-model" class="text-gray-200 font-mono text-sm bg-gray-900 px-3 py-2 rounded"></div>
            </div>

            <div class="space-y-2 mb-4">
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-400">Input Tokens:</span>
                    <div class="flex gap-4">
                        <span id="breakdown-input-tokens" class="text-gray-200 font-mono"></span>
                        <span id="breakdown-input-cost" class="text-green-400 font-mono min-w-[80px] text-right"></span>
                    </div>
                </div>

                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-400">Cache Write (1.25×):</span>
                    <div class="flex gap-4">
                        <span id="breakdown-cache-write-tokens" class="text-gray-200 font-mono"></span>
                        <span id="breakdown-cache-write-cost" class="text-green-400 font-mono min-w-[80px] text-right"></span>
                    </div>
                </div>

                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-400">Cache Read (0.1×):</span>
                    <div class="flex gap-4">
                        <span id="breakdown-cache-read-tokens" class="text-gray-200 font-mono"></span>
                        <span id="breakdown-cache-read-cost" class="text-green-400 font-mono min-w-[80px] text-right"></span>
                    </div>
                </div>

                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-400">Output Tokens:</span>
                    <div class="flex gap-4">
                        <span id="breakdown-output-tokens" class="text-gray-200 font-mono"></span>
                        <span id="breakdown-output-cost" class="text-green-400 font-mono min-w-[80px] text-right"></span>
                    </div>
                </div>

                <div class="border-t border-gray-700 pt-2 mt-2">
                    <div class="flex justify-between items-center font-semibold">
                        <span class="text-gray-200">Total:</span>
                        <span id="breakdown-total-cost" class="text-green-400 font-mono text-lg"></span>
                    </div>
                </div>
            </div>

            <div class="bg-amber-900/20 border border-amber-700/30 rounded-lg p-3 mb-4">
                <div class="flex gap-2">
                    <svg class="w-4 h-4 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div class="text-xs text-amber-200">
                        <strong class="text-amber-300">Note:</strong> Claude 4 models with extended thinking may show lower costs after page refresh. You're billed for full thinking tokens, but only a summary is stored. Costs during streaming are accurate.
                        <a href="https://docs.claude.com/en/docs/build-with-claude/extended-thinking#pricing" target="_blank" class="text-amber-400 hover:text-amber-300 underline ml-1">Learn more</a>
                    </div>
                </div>
            </div>

            <div class="bg-amber-900/20 border border-amber-700/30 rounded-lg p-3 mb-4">
                <div class="flex gap-2">
                    <svg class="w-4 h-4 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div class="text-xs text-amber-200">
                        <strong class="text-amber-300">Note:</strong> When using API keys, you're billed for background processes including warmup messages, conversation summarization, and command processing (typically under $0.04 per session).
                        <a href="https://docs.claude.com/en/docs/claude-code/costs#background-token-usage" target="_blank" class="text-amber-400 hover:text-amber-300 underline ml-1">Learn more</a>
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button onclick="openPricingFromBreakdown()" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-white font-medium">Edit Pricing</button>
                <button onclick="closeBreakdownModal()" class="flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-gray-200 font-medium">Close</button>
            </div>
        </div>
    </div>

    <!-- Pricing Modal -->
    <div id="pricing-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4">
            <h2 class="text-xl font-semibold text-gray-100 mb-4">⚙️ Pricing Settings</h2>

            <div class="mb-4">
                <div class="text-sm text-gray-400 mb-2">Model:</div>
                <div id="pricing-model-name" class="text-gray-200 font-mono text-sm bg-gray-900 px-3 py-2 rounded"></div>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Input Price (per million tokens):</label>
                    <input type="number" id="input-price" step="0.01" class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-gray-200 focus:outline-none focus:border-blue-500" placeholder="e.g., 3.00">
                </div>

                <div>
                    <label class="block text-sm text-gray-400 mb-1">Cache Write Multiplier:</label>
                    <input type="number" id="cache-write-multiplier" step="0.01" class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-gray-200 focus:outline-none focus:border-blue-500" placeholder="e.g., 1.25">
                    <div class="text-xs text-gray-500 mt-1">Cache write = input price × multiplier</div>
                </div>

                <div>
                    <label class="block text-sm text-gray-400 mb-1">Cache Read Multiplier:</label>
                    <input type="number" id="cache-read-multiplier" step="0.01" class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-gray-200 focus:outline-none focus:border-blue-500" placeholder="e.g., 0.1">
                    <div class="text-xs text-gray-500 mt-1">Cache read = input price × multiplier</div>
                </div>

                <div>
                    <label class="block text-sm text-gray-400 mb-1">Output Price (per million tokens):</label>
                    <input type="number" id="output-price" step="0.01" class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded text-gray-200 focus:outline-none focus:border-blue-500" placeholder="e.g., 15.00">
                </div>
            </div>

            <div class="flex gap-2 mt-6">
                <button onclick="savePricing()" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded text-white font-medium">Save</button>
                <button onclick="closePricingModal()" class="flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-gray-200 font-medium">Cancel</button>
            </div>
        </div>
    </div>
</body>
</html>
