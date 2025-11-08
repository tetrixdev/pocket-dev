// Config page Alpine.js component
export function configApp(configs) {
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

        // Skills
        skills: [],
        activeSkill: null,
        skillFiles: [],
        activeSkillFile: null,
        skillFileContent: '',
        skillData: {
            frontmatter: { name: '', description: '', 'allowed-tools': '' },
            content: ''
        },
        savingSkillFile: false,

        // Create skill
        showCreateSkillModal: false,
        newSkill: { name: '', description: '', allowedTools: '' },
        creatingSkill: false,
        showDeleteSkillConfirm: false,

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
                const response = await fetch(`${window.location.origin}/config/${id}`);
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
                const response = await fetch(`${window.location.origin}/config/${id}`, {
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
                const response = await fetch(`${window.location.origin}/config/agents/list`);
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
                const response = await fetch(`${window.location.origin}/config/agents/read/${filename}`);
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
                const response = await fetch(`${window.location.origin}/config/agents/create`, {
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
                const response = await fetch(`${window.location.origin}/config/agents/save/${this.activeAgent}`, {
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
                const response = await fetch(`${window.location.origin}/config/agents/delete/${this.activeAgent}`, {
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
                const response = await fetch(`${window.location.origin}/config/commands/list`);
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
                const response = await fetch(`${window.location.origin}/config/commands/read/${filename}`);
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
                const response = await fetch(`${window.location.origin}/config/commands/create`, {
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
                const response = await fetch(`${window.location.origin}/config/commands/save/${this.activeCommand}`, {
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
                const response = await fetch(`${window.location.origin}/config/commands/delete/${this.activeCommand}`, {
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
                const response = await fetch(`${window.location.origin}/config/hooks`);
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

                const response = await fetch(`${window.location.origin}/config/hooks`, {
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
        // SKILLS METHODS
        // =========================================

        async loadSkills() {
            try {
                const response = await fetch(`${window.location.origin}/config/skills/list`);
                const result = await response.json();
                if (result.success) {
                    this.skills = result.skills;
                }
            } catch (error) {
                this.showNotification('error', 'Failed to load skills');
            }
        },

        async loadSkill(skillName) {
            try {
                // Load skill directory structure
                const response = await fetch(`${window.location.origin}/config/skills/read/${skillName}`);
                const result = await response.json();
                if (result.success) {
                    this.activeSkill = skillName;
                    this.skillFiles = result.files;

                    // Auto-load SKILL.md
                    await this.loadSkillFile('SKILL.md');
                }
            } catch (error) {
                this.showNotification('error', 'Failed to load skill');
            }
        },

        async loadSkillFile(filePath) {
            try {
                const response = await fetch(`${window.location.origin}/config/skills/file/${this.activeSkill}/${filePath}`);
                const result = await response.json();
                if (result.success) {
                    this.activeSkillFile = filePath;
                    this.skillFileContent = result.content;

                    // Parse SKILL.md if applicable
                    if (result.parsed) {
                        this.skillData = result.parsed;
                    }
                }
            } catch (error) {
                this.showNotification('error', 'Failed to load file');
            }
        },

        async createSkill() {
            this.creatingSkill = true;
            try {
                const response = await fetch(`${window.location.origin}/config/skills/create`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.newSkill)
                });
                const result = await response.json();
                if (result.success) {
                    this.showNotification('success', 'Skill created successfully');
                    this.showCreateSkillModal = false;
                    this.newSkill = { name: '', description: '', allowedTools: '' };
                    await this.loadSkills();
                    await this.loadSkill(result.skillName);
                } else {
                    this.showNotification('error', result.error);
                }
            } catch (error) {
                this.showNotification('error', 'Failed to create skill');
            } finally {
                this.creatingSkill = false;
            }
        },

        async saveSkillFile() {
            this.savingSkillFile = true;
            try {
                const response = await fetch(`${window.location.origin}/config/skills/file/${this.activeSkill}/${this.activeSkillFile}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ content: this.skillFileContent })
                });
                const result = await response.json();
                if (result.success) {
                    this.showNotification('success', 'File saved successfully');
                    await this.loadSkill(this.activeSkill);
                } else {
                    this.showNotification('error', result.error);
                }
            } catch (error) {
                this.showNotification('error', 'Failed to save file');
            } finally {
                this.savingSkillFile = false;
            }
        },

        async deleteSkillFile() {
            if (!confirm('Are you sure you want to delete this file?')) {
                return;
            }

            try {
                const response = await fetch(`${window.location.origin}/config/skills/file/${this.activeSkill}/${this.activeSkillFile}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                const result = await response.json();
                if (result.success) {
                    this.showNotification('success', 'File deleted successfully');
                    this.activeSkillFile = null;
                    this.skillFileContent = '';
                    await this.loadSkill(this.activeSkill);
                } else {
                    this.showNotification('error', result.error);
                }
            } catch (error) {
                this.showNotification('error', 'Failed to delete file');
            }
        },

        async deleteSkill() {
            try {
                const response = await fetch(`${window.location.origin}/config/skills/delete/${this.activeSkill}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                const result = await response.json();
                if (result.success) {
                    this.showNotification('success', 'Skill deleted successfully');
                    this.activeSkill = null;
                    await this.loadSkills();
                } else {
                    this.showNotification('error', result.error);
                }
            } catch (error) {
                this.showNotification('error', 'Failed to delete skill');
            }
        },

        renderSkillFiles(files) {
            if (!files || files.length === 0) {
                return '<div class="text-gray-500 text-sm">No files</div>';
            }

            let html = '';
            files.forEach(file => {
                if (file.type === 'directory') {
                    html += `<div class="text-gray-400 font-semibold text-sm pl-2">📁 ${file.name}/</div>`;
                    if (file.children && file.children.length > 0) {
                        html += '<div class="pl-4">' + this.renderSkillFiles(file.children) + '</div>';
                    }
                } else {
                    const isActive = this.activeSkillFile === file.path;
                    html += `<button
                        onclick="this.__x.$data.loadSkillFile('${file.path}')"
                        class="w-full text-left text-sm px-2 py-1 rounded hover:bg-gray-700 ${isActive ? 'bg-gray-700 text-blue-400' : 'text-gray-300'}">
                        📄 ${file.name}
                    </button>`;
                }
            });
            return html;
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
                const response = await fetch(`${window.location.origin}/config/settings`, {
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
