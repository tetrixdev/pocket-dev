<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ $csrfToken }}">
    <title>PocketDev Configuration</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Tab styles */
        .tab-button {
            padding: 12px 24px;
            background: #374151;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 3px solid transparent;
        }

        .tab-button:hover {
            background: #4b5563;
        }

        .tab-button.active {
            background: #1f2937;
            border-bottom-color: #3b82f6;
        }

        /* Editor styles */
        .config-editor {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            border: 1px solid #374151;
            border-radius: 4px;
            padding: 16px;
            height: 400px;
            font-size: 14px;
            line-height: 1.5;
            resize: none;
            overflow: auto;
        }

        .config-editor:focus {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }

        /* Notification styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        }

        .notification.success {
            background: #065f46;
            border-left: 4px solid #10b981;
        }

        .notification.error {
            background: #7f1d1d;
            border-left: 4px solid #ef4444;
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Preview styles for syntax highlighting */
        .preview-pane {
            background: #1e1e1e;
            border: 1px solid #374151;
            border-radius: 4px;
            padding: 0;
            overflow: auto;
            height: 400px;
            font-size: 14px;
            line-height: 1.5;
        }

        .preview-pane pre code {
            padding: 16px;
        }

        .preview-pane pre {
            margin: 0;
            padding: 0;
        }

        .preview-pane code {
            font-family: 'Courier New', monospace;
            display: block;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <div class="h-screen flex flex-col pb-16" x-data="configEditor(@js($configs))">

        <!-- Header -->
        <div class="bg-gray-800 border-b border-gray-700 p-4">
            <h1 class="text-2xl font-bold">⚙️ Configuration Editor</h1>
        </div>

        <!-- Tabs -->
        <div class="bg-gray-800 border-b border-gray-700 flex gap-2 px-4">
            <template x-for="(config, id) in configs" :key="id">
                <button
                    @click="switchTab(id)"
                    class="tab-button"
                    :class="{ 'active': activeTab === id }"
                    x-text="config.title">
                </button>
            </template>
        </div>

        <!-- Content Area -->
        <div class="flex-1 overflow-auto">
            <div class="max-w-7xl mx-auto p-6">
                <template x-for="(config, id) in configs" :key="id">
                    <div x-show="activeTab === id" class="space-y-4">
                        <!-- Config Info -->
                        <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                            <h2 class="text-xl font-semibold mb-2" x-text="config.title"></h2>
                            <p class="text-sm text-gray-400">
                                <span class="font-mono" x-text="config.path"></span>
                                <span class="ml-2 text-xs bg-gray-700 px-2 py-1 rounded" x-text="'(' + config.container + ')'"></span>
                            </p>
                        </div>

                        <!-- Editor & Preview Grid -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4" :data-config-id="id">
                            <!-- Editor Pane -->
                            <div>
                                <label class="block text-sm font-semibold mb-2">Edit Content:</label>
                                <textarea
                                    x-model="contents[id]"
                                    @input="updatePreview(id)"
                                    @scroll="syncScroll($event, 'editor')"
                                    data-scroll-role="editor"
                                    class="config-editor w-full"
                                    :placeholder="'Loading ' + config.title + '...'"
                                    rows="20">
                                </textarea>
                            </div>

                            <!-- Preview Pane -->
                            <div>
                                <label class="block text-sm font-semibold mb-2">Preview (Syntax Highlighted):</label>
                                <div
                                    class="preview-pane"
                                    x-html="previews[id]"
                                    @scroll="syncScroll($event, 'preview')"
                                    data-scroll-role="preview"></div>
                            </div>
                        </div>

                    </div>
                </template>
            </div>
        </div>

        <!-- Fixed Bottom Navigation -->
        <div class="fixed bottom-0 left-0 right-0 bg-gray-800 border-t border-gray-700 safe-area-bottom z-50">
            <div class="grid grid-cols-3 gap-2 p-2">
                <!-- Back to Terminal -->
                <a href="/"
                   class="px-3 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition-all text-center text-sm flex items-center justify-center">
                    🖥️ Terminal
                </a>

                <!-- Save Current Config -->
                <button
                    @click="saveConfig(activeTab)"
                    :disabled="saving[activeTab]"
                    class="px-3 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold transition-all text-sm flex items-center justify-center">
                    <span x-show="!saving[activeTab]">💾 Save</span>
                    <span x-show="saving[activeTab]">⏳ Saving</span>
                </button>

                <!-- Reload Current Config -->
                <button
                    @click="loadConfig(activeTab)"
                    :disabled="loading[activeTab]"
                    class="px-3 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold transition-all text-sm flex items-center justify-center">
                    <span x-show="!loading[activeTab]">🔄 Reload</span>
                    <span x-show="loading[activeTab]">⏳ Loading</span>
                </button>
            </div>
        </div>

        <!-- Notifications -->
        <template x-if="notification">
            <div
                class="notification"
                :class="notification.type"
                x-transition:leave="transition ease-in duration-300"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0">
                <p x-text="notification.message"></p>
            </div>
        </template>
    </div>

    <script>
        function configEditor(configs) {
            return {
                configs: configs,
                activeTab: Object.keys(configs)[0],
                contents: {},
                previews: {},
                loading: {},
                saving: {},
                notification: null,
                scrollSyncing: {},

                init() {
                    // Load all configs on init
                    Object.keys(this.configs).forEach(id => {
                        this.loadConfig(id);
                    });
                },

                switchTab(id) {
                    this.activeTab = id;
                },

                async loadConfig(id) {
                    this.loading[id] = true;

                    try {
                        const response = await fetch(`/config/${id}`, {
                            headers: {
                                'Accept': 'application/json',
                            }
                        });

                        const result = await response.json();

                        if (result.success) {
                            this.contents[id] = result.content;
                            this.updatePreview(id);
                            this.showNotification('success', `${this.configs[id].title} loaded`);
                        } else {
                            this.showNotification('error', result.error || 'Failed to load config');
                        }
                    } catch (error) {
                        console.error('Load error:', error);
                        this.showNotification('error', 'Network error while loading config');
                    } finally {
                        this.loading[id] = false;
                    }
                },

                async saveConfig(id) {
                    this.saving[id] = true;

                    try {
                        const response = await fetch(`/config/${id}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            },
                            body: JSON.stringify({
                                content: this.contents[id]
                            })
                        });

                        const result = await response.json();

                        if (result.success) {
                            this.showNotification('success', result.message);
                        } else {
                            this.showNotification('error', result.error || 'Failed to save config');
                        }
                    } catch (error) {
                        console.error('Save error:', error);
                        this.showNotification('error', 'Network error while saving config');
                    } finally {
                        this.saving[id] = false;
                    }
                },

                updatePreview(id) {
                    const content = this.contents[id] || '';
                    const syntax = this.configs[id].syntax;

                    if (window.hljs) {
                        const highlighted = window.hljs.highlight(content, {
                            language: syntax
                        }).value;
                        this.previews[id] = `<pre><code class="hljs language-${syntax}">${highlighted}</code></pre>`;
                    } else {
                        // Fallback if highlight.js not loaded
                        this.previews[id] = `<pre><code>${this.escapeHtml(content)}</code></pre>`;
                    }
                },

                escapeHtml(text) {
                    const map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    };
                    return text.replace(/[&<>"']/g, m => map[m]);
                },

                showNotification(type, message) {
                    this.notification = { type, message };

                    // Auto-hide after 3 seconds
                    setTimeout(() => {
                        this.notification = null;
                    }, 3000);
                },

                syncScroll(event, source) {
                    const sourceElement = event.target;
                    const container = sourceElement.closest('[data-config-id]');

                    if (!container) return;

                    const configId = container.getAttribute('data-config-id');

                    // Prevent infinite loop by checking if we're already syncing
                    if (this.scrollSyncing[configId]) {
                        return;
                    }

                    this.scrollSyncing[configId] = true;

                    // Find the target element within the same container
                    const targetRole = source === 'editor' ? 'preview' : 'editor';
                    const targetElement = container.querySelector(`[data-scroll-role="${targetRole}"]`);

                    if (targetElement) {
                        // Calculate scroll percentage
                        const scrollPercentage = sourceElement.scrollTop / (sourceElement.scrollHeight - sourceElement.clientHeight);

                        // Apply same percentage to target
                        targetElement.scrollTop = scrollPercentage * (targetElement.scrollHeight - targetElement.clientHeight);
                    }

                    // Reset sync lock after a brief delay
                    setTimeout(() => {
                        this.scrollSyncing[configId] = false;
                    }, 10);
                }
            };
        }
    </script>
</body>
</html>
