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
            white-space: pre;
            word-wrap: normal;
            overflow-wrap: normal;
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
        <div class="bg-gray-800 border-b border-gray-700 p-4 flex justify-between items-center">
            <h1 class="text-2xl font-bold">⚙️ Configuration Editor</h1>
            <a href="/" class="text-blue-400 hover:text-blue-300 text-sm hidden md:block">
                ← Back to Chat
            </a>
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
            <div class="max-w-5xl mx-auto p-6">
                <template x-for="(config, id) in configs" :key="id">
                    <div x-show="activeTab === id" class="space-y-4">
                        <!-- Config Info -->
                        <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                            <h2 class="text-xl font-semibold mb-2" x-text="config.title"></h2>
                            <p class="text-sm text-gray-400">
                                <span class="font-mono" x-text="config.local_path"></span>
                                <span class="ml-2 text-xs bg-gray-700 px-2 py-1 rounded" x-text="'(' + config.container + ')'"></span>
                            </p>
                        </div>

                        <!-- Single Editor -->
                        <div>
                            <textarea
                                x-model="contents[id]"
                                class="config-editor w-full"
                                :placeholder="'Loading ' + config.title + '...'"
                                rows="25"
                                style="min-height: 500px;">
                            </textarea>
                        </div>

                        <!-- Action Buttons (Desktop) -->
                        <div class="hidden md:flex gap-3">
                            <button
                                @click="saveConfig(activeTab)"
                                :disabled="saving[activeTab]"
                                class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold transition-all">
                                <span x-show="!saving[activeTab]">💾 Save</span>
                                <span x-show="saving[activeTab]">⏳ Saving...</span>
                            </button>
                            <button
                                @click="showResetConfirm = true"
                                :disabled="loading[activeTab]"
                                class="px-4 py-2 bg-red-600 hover:bg-red-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold transition-all">
                                🔄 Reset to Default
                            </button>
                        </div>

                    </div>
                </template>
            </div>
        </div>

        <!-- Fixed Bottom Navigation (Mobile Only) -->
        <div class="md:hidden fixed bottom-0 left-0 right-0 bg-gray-800 border-t border-gray-700 safe-area-bottom z-50">
            <div class="grid grid-cols-3 gap-2 p-2">
                <!-- Back to Chat -->
                <a href="/"
                   class="px-3 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold transition-all text-center text-sm flex items-center justify-center">
                    ← Chat
                </a>

                <!-- Save Current Config -->
                <button
                    @click="saveConfig(activeTab)"
                    :disabled="saving[activeTab]"
                    class="px-3 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold transition-all text-sm flex items-center justify-center">
                    <span x-show="!saving[activeTab]">💾 Save</span>
                    <span x-show="saving[activeTab]">⏳ Saving</span>
                </button>

                <!-- Reset to Default -->
                <button
                    @click="showResetConfirm = true"
                    :disabled="loading[activeTab]"
                    class="px-3 py-2 bg-red-600 hover:bg-red-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold transition-all text-sm flex items-center justify-center">
                    🔄 Reset
                </button>
            </div>
        </div>

        <!-- Reset Confirmation Modal -->
        <div x-show="showResetConfirm"
             @click.away="showResetConfirm = false"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
             style="display: none;">
            <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 border border-gray-700">
                <h2 class="text-xl font-semibold text-gray-100 mb-4">⚠️ Reset to Default?</h2>
                <p class="text-gray-300 mb-6">This will reload the configuration from disk, discarding any unsaved changes. This action cannot be undone.</p>

                <div class="flex gap-3">
                    <button @click="resetConfig(); showResetConfirm = false"
                            class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg font-semibold transition-all">
                        Reset
                    </button>
                    <button @click="showResetConfirm = false"
                            class="flex-1 px-4 py-2 bg-gray-600 hover:bg-gray-700 rounded-lg font-semibold transition-all">
                        Cancel
                    </button>
                </div>
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
        const baseUrl = window.location.origin;  // Strip credentials from URL

        function configEditor(configs) {
            return {
                configs: configs,
                activeTab: Object.keys(configs)[0],
                contents: {},
                loading: {},
                saving: {},
                notification: null,
                showResetConfirm: false,

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
                        const response = await fetch(`${baseUrl}/config/${id}`, {
                            headers: {
                                'Accept': 'application/json',
                            }
                        });

                        const result = await response.json();

                        if (result.success) {
                            this.contents[id] = result.content;
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
                        const response = await fetch(`${baseUrl}/config/${id}`, {
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

                resetConfig() {
                    // Reload the current tab's config from disk
                    this.loadConfig(this.activeTab);
                },

                showNotification(type, message) {
                    this.notification = { type, message };

                    // Auto-hide after 3 seconds
                    setTimeout(() => {
                        this.notification = null;
                    }, 3000);
                }
            };
        }
    </script>
</body>
</html>
