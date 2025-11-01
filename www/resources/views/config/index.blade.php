<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ $csrfToken }}">
    <title>PocketDev Configuration</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Category button styles */
        .category-button {
            padding: 12px 16px;
            text-align: left;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            cursor: pointer;
        }

        .category-button:hover {
            background: #374151;
        }

        .category-button.active {
            background: #1f2937;
            border-left-color: #3b82f6;
        }

        /* File list styles */
        .file-item {
            padding: 10px 16px;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }

        .file-item:hover {
            background: #374151;
        }

        .file-item.active {
            background: #1f2937;
            border-left-color: #10b981;
        }

        /* Editor styles */
        .config-editor, .frontmatter-editor, .prompt-editor {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            border: 1px solid #374151;
            border-radius: 4px;
            padding: 16px;
            font-size: 14px;
            line-height: 1.5;
            resize: none;
        }

        .config-editor:focus, .frontmatter-editor:focus, .prompt-editor:focus {
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
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <div class="h-screen flex flex-col" x-data="configApp(@js($configs))">

        <!-- Header -->
        <div class="bg-gray-800 border-b border-gray-700 p-4 flex justify-between items-center">
            <h1 class="text-2xl font-bold">⚙️ Configuration</h1>
            <a href="/" class="text-blue-400 hover:text-blue-300 text-sm">
                ← Back to Chat
            </a>
        </div>

        <!-- Main Content: Sidebar + Content Area -->
        <div class="flex-1 flex overflow-hidden">

            <!-- Sidebar (Categories) -->
            <div class="w-64 bg-gray-800 border-r border-gray-700 overflow-y-auto">

                <!-- Files Category -->
                <div class="border-b border-gray-700">
                    <button
                        @click="activeCategory = 'files'"
                        class="category-button w-full"
                        :class="{ 'active': activeCategory === 'files' }">
                        📄 Files
                    </button>
                    <div x-show="activeCategory === 'files'" class="bg-gray-900">
                        <button
                            @click="switchTab('claude')"
                            class="file-item w-full text-sm"
                            :class="{ 'active': activeTab === 'claude' }">
                            CLAUDE.md
                        </button>
                        <button
                            @click="switchTab('settings')"
                            class="file-item w-full text-sm"
                            :class="{ 'active': activeTab === 'settings' }">
                            settings.json
                        </button>
                    </div>
                </div>

                <!-- Agents Category -->
                <div class="border-b border-gray-700">
                    <button
                        @click="activeCategory = 'agents'; loadAgents()"
                        class="category-button w-full"
                        :class="{ 'active': activeCategory === 'agents' }">
                        🤖 Agents
                    </button>
                    <div x-show="activeCategory === 'agents'" class="bg-gray-900">
                        <button
                            @click="showCreateAgentModal = true"
                            class="file-item w-full text-sm text-blue-400 hover:text-blue-300">
                            + New Agent
                        </button>
                        <template x-for="agent in agents" :key="agent.filename">
                            <button
                                @click="loadAgent(agent.filename)"
                                class="file-item w-full text-sm text-left"
                                :class="{ 'active': activeAgent === agent.filename }">
                                <div x-text="agent.name"></div>
                                <div class="text-xs text-gray-500 truncate" x-text="agent.description"></div>
                            </button>
                        </template>
                    </div>
                </div>

                <!-- System Category -->
                <div>
                    <button
                        @click="activeCategory = 'system'"
                        class="category-button w-full"
                        :class="{ 'active': activeCategory === 'system' }">
                        🔧 System
                    </button>
                    <div x-show="activeCategory === 'system'" class="bg-gray-900">
                        <button
                            @click="switchTab('nginx')"
                            class="file-item w-full text-sm"
                            :class="{ 'active': activeTab === 'nginx' }">
                            Nginx Proxy
                        </button>
                    </div>
                </div>

            </div>

            <!-- Main Content Area -->
            <div class="flex-1 overflow-auto">

                <!-- Simple File Editor (CLAUDE.md, settings.json, nginx) -->
                <div x-show="activeCategory === 'files' || activeCategory === 'system'" class="p-6">
                    <template x-for="(config, id) in configs" :key="id">
                        <div x-show="activeTab === id" class="space-y-4">
                            <!-- Config Info -->
                            <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                                <h2 class="text-xl font-semibold mb-2" x-text="config.title"></h2>
                                <p class="text-sm text-gray-400">
                                    <span class="font-mono" x-text="config.local_path"></span>
                                </p>
                            </div>

                            <!-- Editor -->
                            <div>
                                <textarea
                                    x-model="contents[id]"
                                    class="config-editor w-full"
                                    rows="25"
                                    style="min-height: 500px;">
                                </textarea>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-3">
                                <button
                                    @click="saveConfig(activeTab)"
                                    :disabled="saving[activeTab]"
                                    class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold">
                                    <span x-show="!saving[activeTab]">💾 Save</span>
                                    <span x-show="saving[activeTab]">⏳ Saving...</span>
                                </button>
                                <button
                                    @click="loadConfig(activeTab)"
                                    class="px-4 py-2 bg-gray-600 hover:bg-gray-700 rounded-lg font-semibold">
                                    🔄 Reload
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Agent Editor (Dual Pane: Frontmatter + System Prompt) -->
                <div x-show="activeCategory === 'agents' && activeAgent" class="p-6 space-y-4">
                    <!-- Agent Header -->
                    <div class="bg-gray-800 p-4 rounded-lg border border-gray-700 flex justify-between items-center">
                        <div>
                            <h2 class="text-xl font-semibold" x-text="'Agent: ' + agentData.frontmatter.name"></h2>
                            <p class="text-sm text-gray-400 font-mono" x-text="activeAgent"></p>
                        </div>
                        <button
                            @click="showDeleteConfirm = true"
                            class="px-3 py-2 bg-red-600 hover:bg-red-700 rounded-lg text-sm font-semibold">
                            🗑️ Delete
                        </button>
                    </div>

                    <!-- Dual Pane Editor -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Left: Frontmatter Form -->
                        <div class="space-y-4">
                            <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                                <h3 class="text-lg font-semibold mb-4">Frontmatter</h3>

                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Name (lowercase, hyphens only)</label>
                                        <input
                                            type="text"
                                            x-model="agentData.frontmatter.name"
                                            pattern="[a-z0-9-]+"
                                            class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium mb-1">Description</label>
                                        <textarea
                                            x-model="agentData.frontmatter.description"
                                            rows="3"
                                            maxlength="1024"
                                            class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white resize-none"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium mb-1">Tools (comma-separated)</label>
                                        <input
                                            type="text"
                                            x-model="agentData.frontmatter.tools"
                                            placeholder="Read, Write, Edit, Bash"
                                            class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                                        <p class="text-xs text-gray-500 mt-1">Leave empty to inherit all tools</p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium mb-1">Model</label>
                                        <select
                                            x-model="agentData.frontmatter.model"
                                            class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                                            <option value="inherit">Inherit from main</option>
                                            <option value="haiku">Haiku</option>
                                            <option value="sonnet">Sonnet</option>
                                            <option value="opus">Opus</option>
                                        </select>
                                        <p class="text-xs text-gray-500 mt-1">Note: The main assistant may override this choice (e.g., use Haiku for cost optimization)</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: System Prompt -->
                        <div class="space-y-2">
                            <label class="block text-sm font-medium">System Prompt</label>
                            <textarea
                                x-model="agentData.systemPrompt"
                                class="prompt-editor w-full"
                                rows="20"
                                style="min-height: 400px;"></textarea>
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="flex gap-3">
                        <button
                            @click="saveAgent()"
                            :disabled="savingAgent"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold">
                            <span x-show="!savingAgent">💾 Save Agent</span>
                            <span x-show="savingAgent">⏳ Saving...</span>
                        </button>
                    </div>
                </div>

                <!-- Empty State for Agents -->
                <div x-show="activeCategory === 'agents' && !activeAgent" class="p-6">
                    <div class="max-w-md mx-auto text-center py-12">
                        <div class="text-6xl mb-4">🤖</div>
                        <h3 class="text-xl font-semibold mb-2">No Agent Selected</h3>
                        <p class="text-gray-400 mb-6">Select an agent from the sidebar or create a new one</p>
                        <button
                            @click="showCreateAgentModal = true"
                            class="px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold">
                            + Create New Agent
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Create Agent Modal -->
        <div x-show="showCreateAgentModal"
             @click.away="showCreateAgentModal = false"
             class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
             style="display: none;">
            <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-lg w-full mx-4 border border-gray-700">
                <h2 class="text-xl font-semibold mb-4">Create New Agent</h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Name (lowercase, hyphens only)</label>
                        <input
                            type="text"
                            x-model="newAgent.name"
                            pattern="[a-z0-9-]+"
                            placeholder="my-agent"
                            class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Description</label>
                        <textarea
                            x-model="newAgent.description"
                            rows="3"
                            maxlength="1024"
                            placeholder="What this agent does and when to use it"
                            class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white resize-none"></textarea>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button @click="createAgent()"
                            :disabled="creatingAgent || !newAgent.name || !newAgent.description"
                            class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold">
                        <span x-show="!creatingAgent">Create</span>
                        <span x-show="creatingAgent">Creating...</span>
                    </button>
                    <button @click="showCreateAgentModal = false"
                            class="flex-1 px-4 py-2 bg-gray-600 hover:bg-gray-700 rounded-lg font-semibold">
                        Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div x-show="showDeleteConfirm"
             @click.away="showDeleteConfirm = false"
             class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
             style="display: none;">
            <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 border border-gray-700">
                <h2 class="text-xl font-semibold text-gray-100 mb-4">⚠️ Delete Agent?</h2>
                <p class="text-gray-300 mb-6">Are you sure you want to delete this agent? This action cannot be undone.</p>

                <div class="flex gap-3">
                    <button @click="deleteAgent(); showDeleteConfirm = false"
                            class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg font-semibold">
                        Delete
                    </button>
                    <button @click="showDeleteConfirm = false"
                            class="flex-1 px-4 py-2 bg-gray-600 hover:bg-gray-700 rounded-lg font-semibold">
                        Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Notifications -->
        <template x-if="notification">
            <div class="notification" :class="notification.type">
                <p x-text="notification.message"></p>
            </div>
        </template>
    </div>

    <script>
        const baseUrl = window.location.origin;

        function configApp(configs) {
            return {
                // Categories
                activeCategory: 'files',

                // Simple configs
                configs: configs,
                activeTab: 'claude',
                contents: {},
                loading: {},
                saving: {},

                // Agents
                agents: [],
                activeAgent: null,
                agentData: {
                    frontmatter: { name: '', description: '', tools: '', model: 'inherit' },
                    systemPrompt: ''
                },
                savingAgent: false,

                // Create agent
                showCreateAgentModal: false,
                newAgent: { name: '', description: '', model: 'inherit' },
                creatingAgent: false,

                // UI state
                showDeleteConfirm: false,
                notification: null,

                init() {
                    // Load simple configs
                    Object.keys(this.configs).forEach(id => {
                        this.loadConfig(id);
                    });
                },

                // =========================================
                // SIMPLE CONFIG METHODS (CLAUDE.md, settings.json, nginx)
                // =========================================

                switchTab(id) {
                    this.activeTab = id;
                    this.activeAgent = null;
                },

                async loadConfig(id) {
                    this.loading[id] = true;
                    try {
                        const response = await fetch(`${baseUrl}/config/${id}`);
                        const result = await response.json();
                        if (result.success) {
                            this.contents[id] = result.content;
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to load config');
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
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ content: this.contents[id] })
                        });
                        const result = await response.json();
                        if (result.success) {
                            this.showNotification('success', result.message);
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to save config');
                    } finally {
                        this.saving[id] = false;
                    }
                },

                // =========================================
                // AGENTS METHODS
                // =========================================

                async loadAgents() {
                    try {
                        const response = await fetch(`${baseUrl}/config/agents/list`);
                        const result = await response.json();
                        if (result.success) {
                            this.agents = result.agents;
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to load agents');
                    }
                },

                async loadAgent(filename) {
                    try {
                        const response = await fetch(`${baseUrl}/config/agents/read/${filename}`);
                        const result = await response.json();
                        if (result.success) {
                            this.activeAgent = filename;
                            this.agentData = {
                                frontmatter: {
                                    name: result.frontmatter.name || '',
                                    description: result.frontmatter.description || '',
                                    tools: result.frontmatter.tools || '',
                                    model: result.frontmatter.model || 'inherit'
                                },
                                systemPrompt: result.systemPrompt || ''
                            };
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to load agent');
                    }
                },

                async createAgent() {
                    this.creatingAgent = true;
                    try {
                        const response = await fetch(`${baseUrl}/config/agents/create`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify(this.newAgent)
                        });
                        const result = await response.json();
                        if (result.success) {
                            this.showNotification('success', 'Agent created successfully');
                            this.showCreateAgentModal = false;
                            this.newAgent = { name: '', description: '', model: 'inherit' };
                            await this.loadAgents();
                            await this.loadAgent(result.filename);
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to create agent');
                    } finally {
                        this.creatingAgent = false;
                    }
                },

                async saveAgent() {
                    this.savingAgent = true;
                    try {
                        const response = await fetch(`${baseUrl}/config/agents/save/${this.activeAgent}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify(this.agentData.frontmatter.name ? {
                                name: this.agentData.frontmatter.name,
                                description: this.agentData.frontmatter.description,
                                tools: this.agentData.frontmatter.tools,
                                model: this.agentData.frontmatter.model,
                                systemPrompt: this.agentData.systemPrompt
                            } : {})
                        });
                        const result = await response.json();
                        if (result.success) {
                            this.showNotification('success', 'Agent saved successfully');
                            await this.loadAgents();
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to save agent');
                    } finally {
                        this.savingAgent = false;
                    }
                },

                async deleteAgent() {
                    try {
                        const response = await fetch(`${baseUrl}/config/agents/delete/${this.activeAgent}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            }
                        });
                        const result = await response.json();
                        if (result.success) {
                            this.showNotification('success', 'Agent deleted successfully');
                            this.activeAgent = null;
                            await this.loadAgents();
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to delete agent');
                    }
                },

                // =========================================
                // NOTIFICATION
                // =========================================

                showNotification(type, message) {
                    this.notification = { type, message };
                    setTimeout(() => { this.notification = null; }, 3000);
                }
            };
        }
    </script>
</body>
</html>
