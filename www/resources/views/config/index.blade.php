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

                <!-- Commands Category -->
                <div class="border-b border-gray-700">
                    <button
                        @click="activeCategory = 'commands'; loadCommands()"
                        class="category-button w-full"
                        :class="{ 'active': activeCategory === 'commands' }">
                        ⚡ Commands
                    </button>
                    <div x-show="activeCategory === 'commands'" class="bg-gray-900">
                        <button
                            @click="showCreateCommandModal = true"
                            class="file-item w-full text-sm text-blue-400 hover:text-blue-300">
                            + New Command
                        </button>
                        <template x-for="command in commands" :key="command.filename">
                            <button
                                @click="loadCommand(command.filename)"
                                class="file-item w-full text-sm text-left"
                                :class="{ 'active': activeCommand === command.filename }">
                                <div class="font-mono">/<span x-text="command.name"></span></div>
                                <div class="text-xs text-gray-500 truncate" x-text="command.argumentHints"></div>
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Hooks Category -->
                <div class="border-b border-gray-700">
                    <button
                        @click="activeCategory = 'hooks'; loadHooks()"
                        class="category-button w-full"
                        :class="{ 'active': activeCategory === 'hooks' }">
                        🪝 Hooks
                    </button>
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

                <!-- Simple File Editor (CLAUDE.md, nginx) -->
                <div x-show="(activeCategory === 'files' || activeCategory === 'system') && activeTab !== 'settings'" class="p-6">
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

                <!-- Advanced Settings Editor (settings.json only) -->
                <div x-show="activeTab === 'settings'" class="p-6 space-y-4">
                            <!-- Settings Header -->
                            <div class="bg-gray-800 p-4 rounded-lg border border-gray-700 flex justify-between items-center">
                                <div>
                                    <h2 class="text-xl font-semibold">Claude Settings</h2>
                                    <p class="text-sm text-gray-400 font-mono">/home/appuser/.claude/settings.json</p>
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        @click="settingsMode = 'structured'"
                                        :class="settingsMode === 'structured' ? 'bg-blue-600' : 'bg-gray-600'"
                                        class="px-3 py-2 hover:bg-opacity-80 rounded text-sm font-semibold">
                                        📋 Structured
                                    </button>
                                    <button
                                        @click="settingsMode = 'raw'"
                                        :class="settingsMode === 'raw' ? 'bg-blue-600' : 'bg-gray-600'"
                                        class="px-3 py-2 hover:bg-opacity-80 rounded text-sm font-semibold">
                                        {} Raw JSON
                                    </button>
                                </div>
                            </div>

                            <!-- Structured Mode -->
                            <div x-show="settingsMode === 'structured'" class="space-y-4">
                                <!-- Category Tabs -->
                                <div class="flex gap-2 border-b border-gray-700">
                                    <button
                                        @click="settingsTab = 'model'"
                                        :class="settingsTab === 'model' ? 'border-b-2 border-blue-500 text-blue-400' : 'text-gray-400'"
                                        class="px-4 py-2 font-medium">
                                        Model & Behavior
                                    </button>
                                    <button
                                        @click="settingsTab = 'permissions'"
                                        :class="settingsTab === 'permissions' ? 'border-b-2 border-blue-500 text-blue-400' : 'text-gray-400'"
                                        class="px-4 py-2 font-medium">
                                        Permissions
                                    </button>
                                </div>

                                <!-- Model & Behavior Tab -->
                                <div x-show="settingsTab === 'model'" class="space-y-4">
                                    <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                                        <h3 class="text-lg font-semibold mb-4">Model Configuration</h3>
                                        <div class="space-y-3">
                                            <div>
                                                <label class="block text-sm font-medium mb-1">Default Model</label>
                                                <select
                                                    x-model="structuredSettings.model"
                                                    class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                                                    <option value="">Auto (Default)</option>
                                                    <option value="claude-sonnet-4-5-20250929">Sonnet 4.5</option>
                                                    <option value="claude-3-7-sonnet-20250219">Sonnet 3.7</option>
                                                    <option value="claude-3-5-haiku-20241022">Haiku 3.5</option>
                                                    <option value="claude-opus-4-20250514">Opus 4</option>
                                                </select>
                                                <p class="text-xs text-gray-500 mt-1">Override the default model for all sessions</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                                        <h3 class="text-lg font-semibold mb-4">Behavior</h3>
                                        <div class="space-y-3">
                                            <div>
                                                <label class="block text-sm font-medium mb-1">Cleanup Period (days)</label>
                                                <input
                                                    type="number"
                                                    x-model="structuredSettings.cleanupPeriodDays"
                                                    min="1"
                                                    placeholder="30"
                                                    class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                                                <p class="text-xs text-gray-500 mt-1">How long to retain chat transcripts (default: 30 days)</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Permissions Tab -->
                                <div x-show="settingsTab === 'permissions'" class="space-y-4">
                                    <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                                        <h3 class="text-lg font-semibold mb-4">Permission Rules</h3>
                                        <p class="text-sm text-gray-400 mb-4">Configure which tools and operations Claude can use automatically. Use wildcards (*) for pattern matching.</p>

                                        <div class="space-y-4">
                                            <!-- Allow Rules -->
                                            <div>
                                                <label class="block text-sm font-medium mb-2">Allow (Auto-approve)</label>
                                                <textarea
                                                    x-model="permissionsAllowText"
                                                    rows="6"
                                                    placeholder="Bash(ls:*)&#10;Read(/workspace/**)&#10;Write(/workspace/**)"
                                                    class="config-editor w-full text-sm"></textarea>
                                                <p class="text-xs text-gray-500 mt-1">One rule per line. Example: Bash(git:*) or Read(/path/**)</p>
                                            </div>

                                            <!-- Deny Rules -->
                                            <div>
                                                <label class="block text-sm font-medium mb-2">Deny (Block)</label>
                                                <textarea
                                                    x-model="permissionsDenyText"
                                                    rows="4"
                                                    placeholder="Read(**/.env)&#10;Write(**/.git/**)"
                                                    class="config-editor w-full text-sm"></textarea>
                                                <p class="text-xs text-gray-500 mt-1">Explicitly block these operations</p>
                                            </div>

                                            <!-- Default Mode -->
                                            <div>
                                                <label class="block text-sm font-medium mb-1">Default Permission Mode</label>
                                                <select
                                                    x-model="structuredSettings.permissions.defaultMode"
                                                    class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                                                    <option value="">Ask (Default)</option>
                                                    <option value="acceptEdits">Accept Edits</option>
                                                    <option value="acceptAll">Accept All</option>
                                                </select>
                                                <p class="text-xs text-gray-500 mt-1">How to handle operations not in allow/deny lists</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Save Button -->
                                <div class="flex gap-3">
                                    <button
                                        @click="saveStructuredSettings()"
                                        :disabled="saving.settings"
                                        class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold">
                                        <span x-show="!saving.settings">💾 Save Settings</span>
                                        <span x-show="saving.settings">⏳ Saving...</span>
                                    </button>
                                    <button
                                        @click="loadStructuredSettings()"
                                        class="px-4 py-2 bg-gray-600 hover:bg-gray-700 rounded-lg font-semibold">
                                        🔄 Reload
                                    </button>
                                </div>
                            </div>

                            <!-- Raw JSON Mode -->
                            <div x-show="settingsMode === 'raw'" class="space-y-4">
                                <textarea
                                    x-model="contents.settings"
                                    class="config-editor w-full"
                                    rows="25"
                                    style="min-height: 500px;"></textarea>

                                <div class="flex gap-3">
                                    <button
                                        @click="saveConfig('settings')"
                                        :disabled="saving.settings"
                                        class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold">
                                        <span x-show="!saving.settings">💾 Save</span>
                                        <span x-show="saving.settings">⏳ Saving...</span>
                                    </button>
                                    <button
                                        @click="loadConfig('settings')"
                                        class="px-4 py-2 bg-gray-600 hover:bg-gray-700 rounded-lg font-semibold">
                                        🔄 Reload
                                    </button>
                                </div>
                            </div>
                        </div>
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

                <!-- Command Editor (Frontmatter + Prompt) -->
                <div x-show="activeCategory === 'commands' && activeCommand" class="p-6 space-y-4">
                    <!-- Command Header -->
                    <div class="bg-gray-800 p-4 rounded-lg border border-gray-700 flex justify-between items-center">
                        <div>
                            <h2 class="text-xl font-semibold font-mono">/<span x-text="commandData.frontmatter.name || commandData.filename?.replace('.md', '')"></span></h2>
                            <p class="text-sm text-gray-400 font-mono" x-text="activeCommand"></p>
                        </div>
                        <button
                            @click="showDeleteCommandConfirm = true"
                            class="px-3 py-2 bg-red-600 hover:bg-red-700 rounded-lg text-sm font-semibold">
                            🗑️ Delete
                        </button>
                    </div>

                    <!-- Dual Pane Editor -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Left: Frontmatter Form -->
                        <div class="space-y-4">
                            <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                                <h3 class="text-lg font-semibold mb-4">Frontmatter (Optional)</h3>

                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Allowed Tools (Optional)</label>
                                        <input
                                            type="text"
                                            x-model="commandData.frontmatter.allowedTools"
                                            placeholder="Read, Write, Edit, Bash"
                                            class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                                        <div class="text-xs text-gray-500 mt-2 space-y-1">
                                            <p>Restrict which tools Claude can use during this command. Leave empty for all tools.</p>
                                            <p class="font-mono bg-gray-950 px-2 py-1 rounded">Common: Read, Write, Edit, Bash, Glob, Grep, WebSearch</p>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium mb-1">Argument Hints</label>
                                        <input
                                            type="text"
                                            x-model="commandData.frontmatter.argumentHints"
                                            placeholder="[pr-number] [assignee]"
                                            maxlength="512"
                                            class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                                        <div class="text-xs text-gray-500 mt-2 space-y-1">
                                            <p>Shown during auto-completion to guide users on what parameters the command expects.</p>
                                            <p class="font-mono bg-gray-950 px-2 py-1 rounded">Example: add [tagId] | remove [tagId] | list</p>
                                            <p>Access arguments in prompt with <span class="font-mono bg-gray-950 px-1">$1</span>, <span class="font-mono bg-gray-950 px-1">$2</span>, or <span class="font-mono bg-gray-950 px-1">$ARGUMENTS</span></p>
                                            <a href="https://docs.claude.com/en/docs/claude-code/slash-commands" target="_blank" class="text-blue-400 hover:text-blue-300">
                                                📖 Documentation
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Command Prompt -->
                        <div class="space-y-2">
                            <label class="block text-sm font-medium">Command Prompt</label>
                            <textarea
                                x-model="commandData.prompt"
                                class="prompt-editor w-full"
                                rows="20"
                                style="min-height: 400px;"></textarea>
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="flex gap-3">
                        <button
                            @click="saveCommand()"
                            :disabled="savingCommand"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold">
                            <span x-show="!savingCommand">💾 Save Command</span>
                            <span x-show="savingCommand">⏳ Saving...</span>
                        </button>
                    </div>
                </div>

                <!-- Empty State for Commands -->
                <div x-show="activeCategory === 'commands' && !activeCommand" class="p-6">
                    <div class="max-w-md mx-auto text-center py-12">
                        <div class="text-6xl mb-4">⚡</div>
                        <h3 class="text-xl font-semibold mb-2">No Command Selected</h3>
                        <p class="text-gray-400 mb-6">Select a command from the sidebar or create a new one</p>
                        <button
                            @click="showCreateCommandModal = true"
                            class="px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-semibold">
                            + Create New Command
                        </button>
                    </div>
                </div>

                <!-- Hooks Editor -->
                <div x-show="activeCategory === 'hooks'" class="p-6 space-y-4">
                    <!-- Hooks Header -->
                    <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                        <h2 class="text-xl font-semibold mb-2">🪝 Hooks Configuration</h2>
                        <p class="text-sm text-gray-400">Event-driven automation that executes shell commands during Claude Code operations</p>
                    </div>

                    <!-- Documentation Panel -->
                    <div class="bg-gray-800 p-4 rounded-lg border border-gray-700 space-y-3">
                        <h3 class="text-lg font-semibold">Available Events</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                            <div class="bg-gray-900 p-2 rounded">
                                <span class="font-mono text-blue-400">PreToolUse</span> - Before tool execution
                            </div>
                            <div class="bg-gray-900 p-2 rounded">
                                <span class="font-mono text-blue-400">PostToolUse</span> - After tool completes
                            </div>
                            <div class="bg-gray-900 p-2 rounded">
                                <span class="font-mono text-blue-400">UserPromptSubmit</span> - User submits prompt
                            </div>
                            <div class="bg-gray-900 p-2 rounded">
                                <span class="font-mono text-blue-400">SessionStart</span> - Session begins
                            </div>
                            <div class="bg-gray-900 p-2 rounded">
                                <span class="font-mono text-blue-400">SessionEnd</span> - Session terminates
                            </div>
                            <div class="bg-gray-900 p-2 rounded">
                                <span class="font-mono text-blue-400">Stop</span> - Agent finishes
                            </div>
                        </div>

                        <h3 class="text-lg font-semibold mt-4">Environment Variables</h3>
                        <div class="space-y-1 text-sm text-gray-400">
                            <p><span class="font-mono bg-gray-950 px-1">$CLAUDE_PROJECT_DIR</span> - Project root path</p>
                            <p><span class="font-mono bg-gray-950 px-1">$CLAUDE_ENV_FILE</span> - Environment file (SessionStart only)</p>
                            <p><span class="font-mono bg-gray-950 px-1">$CLAUDE_CODE_REMOTE</span> - Remote execution indicator</p>
                        </div>

                        <a href="https://docs.claude.com/en/docs/claude-code/hooks" target="_blank" class="inline-block text-blue-400 hover:text-blue-300 text-sm mt-2">
                            📖 Full Documentation
                        </a>
                    </div>

                    <!-- JSON Editor -->
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <label class="block text-sm font-medium">Hooks Configuration (JSON)</label>
                            <button
                                @click="formatHooksJson()"
                                class="px-3 py-1 bg-gray-700 hover:bg-gray-600 rounded text-sm">
                                🔧 Format JSON
                            </button>
                        </div>
                        <textarea
                            x-model="hooksJson"
                            class="config-editor w-full font-mono text-sm"
                            rows="20"
                            style="min-height: 400px;"
                            placeholder='{\n  "PreToolUse": [\n    {\n      "matcher": "Write",\n      "hooks": [\n        {\n          "type": "command",\n          "command": "echo \'Writing file...\'",\n          "timeout": 60\n        }\n      ]\n    }\n  ]\n}'></textarea>
                        <p class="text-xs text-gray-500" x-show="hooksJsonError" x-text="hooksJsonError" class="text-red-400"></p>
                    </div>

                    <!-- Example Template -->
                    <details class="bg-gray-800 p-4 rounded-lg border border-gray-700">
                        <summary class="cursor-pointer font-semibold text-sm">📋 Example Template</summary>
                        <pre class="mt-3 text-xs bg-gray-950 p-3 rounded overflow-x-auto"><code>{
  "PreToolUse": [
    {
      "matcher": "Write|Edit",
      "hooks": [
        {
          "type": "command",
          "command": "echo 'File operation detected'",
          "timeout": 60
        }
      ]
    }
  ],
  "SessionStart": [
    {
      "matcher": "*",
      "hooks": [
        {
          "type": "command",
          "command": "[ -n \"$CLAUDE_ENV_FILE\" ] && echo 'export MY_VAR=value' >> \"$CLAUDE_ENV_FILE\"",
          "timeout": 30
        }
      ]
    }
  ]
}</code></pre>
                    </details>

                    <!-- Save Button -->
                    <div class="flex gap-3">
                        <button
                            @click="saveHooks()"
                            :disabled="savingHooks"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold">
                            <span x-show="!savingHooks">💾 Save Hooks</span>
                            <span x-show="savingHooks">⏳ Saving...</span>
                        </button>
                        <button
                            @click="loadHooks()"
                            class="px-4 py-2 bg-gray-600 hover:bg-gray-700 rounded-lg font-semibold">
                            🔄 Reload
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

        <!-- Delete Agent Confirmation Modal -->
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

        <!-- Create Command Modal -->
        <div x-show="showCreateCommandModal"
             @click.away="showCreateCommandModal = false"
             class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
             style="display: none;">
            <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-lg w-full mx-4 border border-gray-700">
                <h2 class="text-xl font-semibold mb-2">Create New Command</h2>
                <p class="text-sm text-gray-400 mb-4">Slash commands expand to custom prompts when invoked in chat.</p>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Name (lowercase, hyphens only)</label>
                        <input
                            type="text"
                            x-model="newCommand.name"
                            pattern="[a-z0-9-]+"
                            placeholder="review-pr"
                            class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded text-white">
                        <p class="text-xs text-gray-500 mt-1">Use with: <span class="font-mono bg-gray-950 px-1">/<span x-text="newCommand.name || 'review-pr'"></span></span></p>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button @click="createCommand()"
                            :disabled="creatingCommand || !newCommand.name"
                            class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed rounded-lg font-semibold">
                        <span x-show="!creatingCommand">Create</span>
                        <span x-show="creatingCommand">Creating...</span>
                    </button>
                    <button @click="showCreateCommandModal = false"
                            class="flex-1 px-4 py-2 bg-gray-600 hover:bg-gray-700 rounded-lg font-semibold">
                        Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Delete Command Confirmation Modal -->
        <div x-show="showDeleteCommandConfirm"
             @click.away="showDeleteCommandConfirm = false"
             class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
             style="display: none;">
            <div @click.stop class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 border border-gray-700">
                <h2 class="text-xl font-semibold text-gray-100 mb-4">⚠️ Delete Command?</h2>
                <p class="text-gray-300 mb-6">Are you sure you want to delete this command? This action cannot be undone.</p>

                <div class="flex gap-3">
                    <button @click="deleteCommand(); showDeleteCommandConfirm = false"
                            class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg font-semibold">
                        Delete
                    </button>
                    <button @click="showDeleteCommandConfirm = false"
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

                // Commands
                commands: [],
                activeCommand: null,
                commandData: {
                    frontmatter: { allowedTools: '', argumentHints: '' },
                    prompt: '',
                    filename: ''
                },
                savingCommand: false,

                // Create command
                showCreateCommandModal: false,
                newCommand: { name: '' },
                creatingCommand: false,

                // Hooks
                hooksJson: '',
                hooksJsonError: '',
                savingHooks: false,

                // Settings (structured mode)
                settingsMode: 'structured',
                settingsTab: 'model',
                structuredSettings: {
                    model: '',
                    cleanupPeriodDays: '',
                    permissions: {
                        allow: [],
                        deny: [],
                        defaultMode: ''
                    }
                },
                permissionsAllowText: '',
                permissionsDenyText: '',

                // UI state
                showDeleteConfirm: false,
                showDeleteCommandConfirm: false,
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

                async switchTab(id) {
                    this.activeTab = id;
                    this.activeAgent = null;
                    this.activeCommand = null;

                    // Load structured settings when switching to settings tab
                    if (id === 'settings' && this.settingsMode === 'structured') {
                        await this.loadStructuredSettings();
                    }
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
                // COMMANDS METHODS
                // =========================================

                async loadCommands() {
                    try {
                        const response = await fetch(`${baseUrl}/config/commands/list`);
                        const result = await response.json();
                        if (result.success) {
                            this.commands = result.commands;
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to load commands');
                    }
                },

                async loadCommand(filename) {
                    try {
                        const response = await fetch(`${baseUrl}/config/commands/read/${filename}`);
                        const result = await response.json();
                        if (result.success) {
                            this.activeCommand = filename;
                            this.commandData = {
                                frontmatter: {
                                    allowedTools: result.frontmatter.allowedTools || '',
                                    argumentHints: result.frontmatter.argumentHints || ''
                                },
                                prompt: result.prompt || '',
                                filename: filename
                            };
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to load command');
                    }
                },

                async createCommand() {
                    this.creatingCommand = true;
                    try {
                        const response = await fetch(`${baseUrl}/config/commands/create`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify(this.newCommand)
                        });
                        const result = await response.json();
                        if (result.success) {
                            this.showNotification('success', 'Command created successfully');
                            this.showCreateCommandModal = false;
                            this.newCommand = { name: '' };
                            await this.loadCommands();
                            await this.loadCommand(result.filename);
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to create command');
                    } finally {
                        this.creatingCommand = false;
                    }
                },

                async saveCommand() {
                    this.savingCommand = true;
                    try {
                        const response = await fetch(`${baseUrl}/config/commands/save/${this.activeCommand}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                allowedTools: this.commandData.frontmatter.allowedTools,
                                argumentHints: this.commandData.frontmatter.argumentHints,
                                prompt: this.commandData.prompt
                            })
                        });
                        const result = await response.json();
                        if (result.success) {
                            this.showNotification('success', 'Command saved successfully');
                            await this.loadCommands();
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to save command');
                    } finally {
                        this.savingCommand = false;
                    }
                },

                async deleteCommand() {
                    try {
                        const response = await fetch(`${baseUrl}/config/commands/delete/${this.activeCommand}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            }
                        });
                        const result = await response.json();
                        if (result.success) {
                            this.showNotification('success', 'Command deleted successfully');
                            this.activeCommand = null;
                            await this.loadCommands();
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to delete command');
                    }
                },

                // =========================================
                // HOOKS METHODS
                // =========================================

                async loadHooks() {
                    try {
                        const response = await fetch(`${baseUrl}/config/hooks`);
                        const result = await response.json();
                        if (result.success) {
                            this.hooksJson = JSON.stringify(result.hooks, null, 2);
                            this.hooksJsonError = '';
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to load hooks');
                    }
                },

                async saveHooks() {
                    this.savingHooks = true;
                    this.hooksJsonError = '';

                    try {
                        // Validate JSON
                        let hooks;
                        try {
                            hooks = JSON.parse(this.hooksJson || '{}');
                        } catch (e) {
                            this.hooksJsonError = 'Invalid JSON: ' + e.message;
                            this.savingHooks = false;
                            return;
                        }

                        const response = await fetch(`${baseUrl}/config/hooks`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ hooks })
                        });
                        const result = await response.json();
                        if (result.success) {
                            this.showNotification('success', 'Hooks saved successfully');
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to save hooks');
                    } finally {
                        this.savingHooks = false;
                    }
                },

                formatHooksJson() {
                    try {
                        const hooks = JSON.parse(this.hooksJson || '{}');
                        this.hooksJson = JSON.stringify(hooks, null, 2);
                        this.hooksJsonError = '';
                        this.showNotification('success', 'JSON formatted');
                    } catch (e) {
                        this.hooksJsonError = 'Invalid JSON: ' + e.message;
                    }
                },

                // =========================================
                // STRUCTURED SETTINGS METHODS
                // =========================================

                async loadStructuredSettings() {
                    try {
                        // Load raw JSON first
                        await this.loadConfig('settings');

                        // Parse into structured format
                        const settings = JSON.parse(this.contents.settings || '{}');

                        this.structuredSettings = {
                            model: settings.model || '',
                            cleanupPeriodDays: settings.cleanupPeriodDays || '',
                            permissions: {
                                allow: settings.permissions?.allow || [],
                                deny: settings.permissions?.deny || [],
                                defaultMode: settings.permissions?.defaultMode || ''
                            }
                        };

                        // Convert arrays to text (one per line)
                        this.permissionsAllowText = this.structuredSettings.permissions.allow.join('\n');
                        this.permissionsDenyText = this.structuredSettings.permissions.deny.join('\n');

                    } catch (error) {
                        this.showNotification('error', 'Failed to load structured settings');
                    }
                },

                async saveStructuredSettings() {
                    this.saving.settings = true;

                    try {
                        // Parse current settings to preserve other fields
                        const settings = JSON.parse(this.contents.settings || '{}');

                        // Update with structured values
                        if (this.structuredSettings.model) {
                            settings.model = this.structuredSettings.model;
                        } else {
                            delete settings.model;
                        }

                        if (this.structuredSettings.cleanupPeriodDays) {
                            settings.cleanupPeriodDays = parseInt(this.structuredSettings.cleanupPeriodDays);
                        } else {
                            delete settings.cleanupPeriodDays;
                        }

                        // Convert text to arrays (filter empty lines)
                        const allowRules = this.permissionsAllowText.split('\n')
                            .map(line => line.trim())
                            .filter(line => line.length > 0);
                        const denyRules = this.permissionsDenyText.split('\n')
                            .map(line => line.trim())
                            .filter(line => line.length > 0);

                        // Update permissions
                        if (!settings.permissions) {
                            settings.permissions = {};
                        }

                        if (allowRules.length > 0) {
                            settings.permissions.allow = allowRules;
                        }
                        if (denyRules.length > 0) {
                            settings.permissions.deny = denyRules;
                        }
                        if (this.structuredSettings.permissions.defaultMode) {
                            settings.permissions.defaultMode = this.structuredSettings.permissions.defaultMode;
                        } else {
                            delete settings.permissions?.defaultMode;
                        }

                        // Save to backend
                        const response = await fetch(`${baseUrl}/config/settings`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ content: JSON.stringify(settings, null, 2) })
                        });
                        const result = await response.json();

                        if (result.success) {
                            this.showNotification('success', 'Settings saved successfully');
                            // Reload to sync
                            await this.loadStructuredSettings();
                        } else {
                            this.showNotification('error', result.error);
                        }
                    } catch (error) {
                        this.showNotification('error', 'Failed to save settings');
                    } finally {
                        this.saving.settings = false;
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
