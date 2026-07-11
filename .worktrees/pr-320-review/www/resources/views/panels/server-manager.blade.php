<div x-data="{
    panelStateId: @js($panelStateId ?? null),

    // State
    workspaceId: @js($parameters['workspace_id'] ?? $state['workspaceId'] ?? null),
    activeTab: @js($state['activeTab'] ?? 'servers'),
    servers: [],
    selectedServer: @js($state['selectedServer'] ?? null),
    apps: [],
    githubApps: [],
    sshKey: null,
    loading: true,
    appsLoading: false,
    githubLoading: false,
    error: null,
    actionMessage: null,
    actionMessageTimeout: null,

    // Per-server loading states: { serverId: 'testing' | 'detecting' | null }
    serverLoading: {},
    // Per-server/app action loading
    actionLoading: {},

    // Modals
    showAddServer: false,
    showSshKey: false,
    showLogs: false,
    showDeploy: false,
    showAddDomain: false,
    logs: '',
    logsApp: null,

    // Deploy form
    deployApp: null,
    deployConfig: null,
    deployForm: { server_id: '', domain: '', env_content: '' },
    deploying: false,

    // Domain form
    domainApp: null,
    domainForm: { domain: '', upstream: '' },

    // Forms
    serverForm: { name: '', host: '', ssh_user: 'admin', ssh_port: 22 },
    saving: false,

    async init() {
        if (!this.workspaceId) {
            this.error = 'No workspace selected. Open panel with workspace_id parameter.';
            this.loading = false;
            return;
        }
        await this.loadServers();
        if (this.selectedServer) {
            await this.loadApps(this.selectedServer);
        } else {
            this.probeAllServers();
        }
        // Load GitHub apps in background
        this.loadGitHubApps();
    },

    async doAction(action, params = {}) {
        const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, params: { workspace_id: this.workspaceId, ...params } })
        });
        return await response.json();
    },

    async loadServers() {
        this.loading = true;
        this.error = null;
        try {
            const result = await this.doAction('listServers');
            if (result.ok && result.data) {
                this.servers = result.data.servers || [];
            } else {
                this.error = result.data?.output || result.error || 'Failed to load servers';
            }
        } catch (e) {
            this.error = e.message;
        }
        this.loading = false;
    },

    probeAllServers() {
        for (const server of this.servers) {
            this.probeServer(server);
        }
    },

    async probeServer(server) {
        const idx = this.servers.findIndex(s => s.id === server.id);
        if (idx === -1) return;

        this.serverLoading[server.id] = 'testing';
        try {
            const testResult = await this.doAction('testServer', { server_id: server.id });
            if (testResult.ok && testResult.data) {
                this.servers[idx].status = testResult.data.success ? 'unchecked' : 'error';
                this.servers[idx].last_connection_error = testResult.data.success ? null : 'Connection failed';

                if (testResult.data.success) {
                    this.serverLoading[server.id] = 'detecting';
                    const detectResult = await this.doAction('detectServer', { server_id: server.id });
                    if (detectResult.ok && detectResult.data?.detected) {
                        const d = detectResult.data.detected;
                        this.servers[idx].has_vps_setup = d.has_vps_setup;
                        this.servers[idx].vps_setup_mode = d.vps_setup_mode;
                        this.servers[idx].has_proxy_nginx = d.has_proxy_nginx;
                        this.servers[idx].proxy_nginx_version = d.proxy_nginx_version;
                        this.servers[idx].status = detectResult.data.server?.status || this.computeStatus(d);
                    }
                }
            }
        } catch (e) {
            console.error('Probe failed:', e);
            this.servers[idx].status = 'error';
        }
        this.serverLoading[server.id] = null;
    },

    computeStatus(detected) {
        if (!detected.has_vps_setup) return 'needs_vps_setup';
        if (!detected.has_proxy_nginx && detected.vps_setup_mode === 'public') return 'needs_proxy';
        return 'ready';
    },

    async loadGitHubApps() {
        this.githubLoading = true;
        try {
            const result = await this.doAction('scanGitHubApps');
            if (result.ok && result.data) {
                this.githubApps = result.data.apps || [];
            }
        } catch (e) {
            console.error('GitHub scan failed:', e);
        }
        this.githubLoading = false;
    },

    async addServer() {
        if (!this.serverForm.name || !this.serverForm.host) return;
        this.saving = true;
        this.error = null;
        try {
            const result = await this.doAction('addServer', this.serverForm);
            if (result.ok && result.data && !result.data.is_error) {
                this.showAddServer = false;
                const newServerData = result.data.server;
                this.serverForm = { name: '', host: '', ssh_user: 'admin', ssh_port: 22 };
                this.flashMessage('Server added');
                const newServer = {
                    id: newServerData.id,
                    name: newServerData.name,
                    host: newServerData.host,
                    ssh_user: newServerData.ssh_user || 'admin',
                    ssh_port: newServerData.ssh_port || 22,
                    status: 'unchecked',
                    has_vps_setup: null,
                    vps_setup_mode: null,
                    has_proxy_nginx: null,
                    proxy_nginx_version: null,
                };
                this.servers.push(newServer);
                this.probeServer(newServer);
            } else {
                this.error = result.data?.output || result.error || 'Failed to add server';
            }
        } catch (e) {
            this.error = e.message;
        }
        this.saving = false;
    },

    async installVpsSetup(server, mode) {
        this.actionLoading[server.id] = 'vps';
        this.error = null;
        try {
            const result = await this.doAction('installVpsSetup', { server_id: server.id, mode });
            if (result.ok && result.data && !result.data.is_error) {
                this.flashMessage(`VPS Setup installed on ${server.name}`);
                const idx = this.servers.findIndex(s => s.id === server.id);
                if (idx !== -1) {
                    this.servers[idx].has_vps_setup = true;
                    this.servers[idx].vps_setup_mode = mode;
                    this.servers[idx].status = mode === 'public' ? 'needs_proxy' : 'ready';
                }
            } else {
                this.error = result.data?.output || 'Install failed';
            }
        } catch (e) {
            this.error = e.message;
        }
        this.actionLoading[server.id] = null;
    },

    async installProxy(server) {
        this.actionLoading[server.id] = 'proxy';
        this.error = null;
        try {
            const result = await this.doAction('installProxy', { server_id: server.id });
            if (result.ok && result.data && !result.data.is_error) {
                this.flashMessage(`Proxy-nginx installed on ${server.name}`);
                const idx = this.servers.findIndex(s => s.id === server.id);
                if (idx !== -1) {
                    this.servers[idx].has_proxy_nginx = true;
                    this.servers[idx].status = 'ready';
                }
            } else {
                this.error = result.data?.output || 'Install failed';
            }
        } catch (e) {
            this.error = e.message;
        }
        this.actionLoading[server.id] = null;
    },

    async removeServer(server) {
        if (!confirm(`Remove server '${server.name}'?`)) return;
        this.actionLoading[server.id] = 'remove';
        this.error = null;
        try {
            const result = await this.doAction('removeServer', { server_id: server.id });
            if (result.ok && result.data && !result.data.is_error) {
                this.flashMessage(`Server ${server.name} removed`);
                if (this.selectedServer === server.id) {
                    this.selectedServer = null;
                    this.apps = [];
                }
                this.servers = this.servers.filter(s => s.id !== server.id);
            } else {
                this.error = result.data?.output || 'Remove failed';
            }
        } catch (e) {
            this.error = e.message;
        }
        this.actionLoading[server.id] = null;
    },

    async selectServer(server) {
        this.selectedServer = server.id;
        this.syncState();
        await this.loadApps(server.id);
    },

    async loadApps(serverId) {
        this.appsLoading = true;
        try {
            const result = await this.doAction('listApps', { server_id: serverId });
            if (result.ok && result.data) {
                this.apps = result.data.applications || [];
            }
        } catch (e) {
            console.error(e);
        }
        this.appsLoading = false;
    },

    async appAction(app, action) {
        this.actionLoading[app.id] = action;
        this.error = null;
        try {
            const result = await this.doAction(`app${action.charAt(0).toUpperCase() + action.slice(1)}`, { app_id: app.id });
            if (result.ok && result.data && !result.data.is_error) {
                this.flashMessage(`${app.name} ${action} successful`);
                await this.loadApps(this.selectedServer);
            } else {
                this.error = result.data?.output || `${action} failed`;
            }
        } catch (e) {
            this.error = e.message;
        }
        this.actionLoading[app.id] = null;
    },

    async viewLogs(app) {
        this.logsApp = app;
        this.logs = 'Loading...';
        this.showLogs = true;
        try {
            const result = await this.doAction('appLogs', { app_id: app.id, lines: 200 });
            if (result.ok && result.data) {
                this.logs = result.data.logs || 'No logs available';
            } else {
                this.logs = result.data?.output || result.error || 'Failed to load logs';
            }
        } catch (e) {
            this.logs = e.message;
        }
    },

    async openDeployModal(ghApp) {
        this.deployApp = ghApp;
        this.deployConfig = null;
        this.deployForm = { server_id: '', domain: '', env_content: '' };
        this.showDeploy = true;

        // Fetch deploy config
        try {
            const result = await this.doAction('getDeployConfig', { owner: ghApp.owner, repo: ghApp.repo });
            if (result.ok && result.data) {
                this.deployConfig = result.data;
                this.deployForm.env_content = result.data.env_example || '';
            }
        } catch (e) {
            console.error(e);
        }
    },

    async deployToServer() {
        if (!this.deployForm.server_id || !this.deployApp) return;
        this.deploying = true;
        this.error = null;
        try {
            const result = await this.doAction('deployApp', {
                server_id: this.deployForm.server_id,
                owner: this.deployApp.owner,
                repo: this.deployApp.repo,
                domain: this.deployForm.domain,
                env_content: this.deployForm.env_content,
            });
            if (result.ok && result.data && !result.data.is_error) {
                this.flashMessage(`${this.deployApp.repo} deployed successfully`);
                this.showDeploy = false;
                // Refresh apps if viewing that server
                if (this.selectedServer === this.deployForm.server_id) {
                    await this.loadApps(this.selectedServer);
                }
            } else {
                this.error = result.data?.output || result.error || 'Deployment failed';
            }
        } catch (e) {
            this.error = e.message;
        }
        this.deploying = false;
    },

    openAddDomainModal(app) {
        this.domainApp = app;
        this.domainForm = { domain: '', upstream: app.slug + '-nginx' };
        this.showAddDomain = true;
    },

    async addDomain() {
        if (!this.domainForm.domain || !this.domainApp) return;
        this.saving = true;
        this.error = null;
        try {
            const result = await this.doAction('addDomain', {
                app_id: this.domainApp.id,
                domain: this.domainForm.domain,
                upstream: this.domainForm.upstream,
            });
            if (result.ok && result.data && !result.data.is_error) {
                this.flashMessage(`Domain ${this.domainForm.domain} added`);
                this.showAddDomain = false;
                await this.loadApps(this.selectedServer);
            } else {
                this.error = result.data?.output || 'Failed to add domain';
            }
        } catch (e) {
            this.error = e.message;
        }
        this.saving = false;
    },

    async requestSsl(app, domain) {
        this.actionLoading[app.id] = 'ssl';
        this.error = null;
        try {
            const result = await this.doAction('requestSsl', { app_id: app.id, domain });
            if (result.ok && result.data && !result.data.is_error) {
                this.flashMessage(`SSL certificate obtained for ${domain || app.primary_domain}`);
                await this.loadApps(this.selectedServer);
            } else {
                this.error = result.data?.output || result.error || 'SSL request failed';
            }
        } catch (e) {
            this.error = e.message;
        }
        this.actionLoading[app.id] = null;
    },

    async showSshKeyModal() {
        this.sshKey = null;
        this.showSshKey = true;
        try {
            const result = await this.doAction('showPublicKey');
            if (result.ok && result.data) {
                this.sshKey = result.data.public_key || null;
            }
        } catch (e) {
            this.error = e.message;
        }
    },

    async generateSshKey() {
        try {
            const result = await this.doAction('generateSshKey');
            if (result.ok && result.data) {
                this.sshKey = result.data.public_key || null;
                this.flashMessage('SSH key generated');
            }
        } catch (e) {
            this.error = e.message;
        }
    },

    async copySshKey() {
        if (this.sshKey) {
            await navigator.clipboard.writeText(this.sshKey);
            this.flashMessage('SSH key copied');
        }
    },

    goBack() {
        this.selectedServer = null;
        this.apps = [];
        this.syncState();
    },

    setTab(tab) {
        this.activeTab = tab;
        this.syncState();
    },

    flashMessage(msg) {
        this.actionMessage = msg;
        if (this.actionMessageTimeout) clearTimeout(this.actionMessageTimeout);
        this.actionMessageTimeout = setTimeout(() => { this.actionMessage = null; }, 3000);
    },

    syncState() {
        fetch(`/api/panel/${this.panelStateId}/state`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                state: { selectedServer: this.selectedServer, workspaceId: this.workspaceId, activeTab: this.activeTab },
                merge: true
            })
        });
    },

    statusColor(status) {
        const colors = {
            ready: 'text-green-400',
            running: 'text-green-400',
            error: 'text-red-400',
            unchecked: 'text-gray-500',
            needs_vps_setup: 'text-yellow-400',
            needs_proxy: 'text-yellow-400',
            stopped: 'text-gray-500',
            deploying: 'text-blue-400',
        };
        return colors[status] || 'text-gray-400';
    },

    statusIcon(status) {
        const icons = {
            ready: 'fa-circle-check',
            running: 'fa-circle-check',
            error: 'fa-circle-xmark',
            unchecked: 'fa-circle-question',
            needs_vps_setup: 'fa-circle-exclamation',
            needs_proxy: 'fa-circle-exclamation',
            stopped: 'fa-circle-stop',
            deploying: 'fa-circle-notch fa-spin',
        };
        return icons[status] || 'fa-circle';
    },

    getSelectedServer() {
        return this.servers.find(s => s.id === this.selectedServer);
    },

    getReadyServers() {
        return this.servers.filter(s => s.status === 'ready');
    },

    isServerLoading(serverId) {
        return !!this.serverLoading[serverId];
    },

    getServerLoadingText(serverId) {
        const state = this.serverLoading[serverId];
        if (state === 'testing') return 'Testing...';
        if (state === 'detecting') return 'Detecting...';
        return '';
    },

    formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
}" class="h-full flex flex-col text-gray-100 text-sm">

    {{-- Header --}}
    <div class="flex items-center justify-between p-3 border-b border-white/5">
        <div class="flex items-center gap-2">
            <template x-if="selectedServer">
                <button @click="goBack()" class="p-1 -ml-1 rounded hover:bg-white/10 text-gray-400 hover:text-white transition-colors">
                    <i class="fa-solid fa-arrow-left text-xs"></i>
                </button>
            </template>
            <i class="fa-solid fa-server text-blue-400"></i>
            <span class="font-medium" x-text="selectedServer ? getSelectedServer()?.name || 'Server' : 'Server Manager'"></span>
            <span x-show="!loading && !selectedServer && activeTab === 'servers'" class="text-gray-500 text-xs" x-text="'(' + servers.length + ')'"></span>
        </div>
        <div class="flex items-center gap-2">
            <template x-if="!selectedServer">
                <button @click="showSshKeyModal()" class="p-1.5 rounded hover:bg-white/10 text-gray-400 hover:text-yellow-400 transition-colors" title="SSH Key">
                    <i class="fa-solid fa-key text-xs"></i>
                </button>
            </template>
            <template x-if="!selectedServer && activeTab === 'servers'">
                <button @click="showAddServer = true" class="p-1.5 rounded hover:bg-white/10 text-gray-400 hover:text-green-400 transition-colors" title="Add Server">
                    <i class="fa-solid fa-plus text-xs"></i>
                </button>
            </template>
            <button @click="activeTab === 'github' ? loadGitHubApps() : (selectedServer ? loadApps(selectedServer) : probeAllServers())"
                    class="p-1.5 rounded hover:bg-white/10 text-gray-400 hover:text-white transition-colors" title="Refresh">
                <i class="fa-solid fa-arrows-rotate text-xs"></i>
            </button>
        </div>
    </div>

    {{-- Tabs (only when not viewing server details) --}}
    <div x-show="!selectedServer" x-cloak class="flex border-b border-white/5">
        <button @click="setTab('servers')"
                :class="activeTab === 'servers' ? 'text-blue-400 border-blue-400' : 'text-gray-500 border-transparent hover:text-gray-300'"
                class="flex-1 px-4 py-2 text-xs font-medium border-b-2 transition-colors">
            <i class="fa-solid fa-server mr-1"></i> Servers
        </button>
        <button @click="setTab('github')"
                :class="activeTab === 'github' ? 'text-blue-400 border-blue-400' : 'text-gray-500 border-transparent hover:text-gray-300'"
                class="flex-1 px-4 py-2 text-xs font-medium border-b-2 transition-colors">
            <i class="fa-brands fa-github mr-1"></i> Available Apps
            <span x-show="githubApps.length > 0" class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-white/10" x-text="githubApps.length"></span>
        </button>
    </div>

    {{-- Success message --}}
    <div x-show="actionMessage" x-cloak x-transition.opacity
         class="mx-3 mt-2 px-3 py-1.5 bg-green-500/15 border border-green-500/20 text-green-400 rounded text-xs">
        <i class="fa-solid fa-check mr-1"></i><span x-text="actionMessage"></span>
    </div>

    {{-- Error --}}
    <div x-show="error" x-cloak class="mx-3 mt-2 px-3 py-1.5 bg-red-500/15 border border-red-500/20 text-red-400 rounded text-xs">
        <i class="fa-solid fa-triangle-exclamation mr-1"></i><span x-text="error"></span>
        <button @click="error = null" class="float-right text-red-500 hover:text-red-300">&times;</button>
    </div>

    {{-- Loading --}}
    <div x-show="loading || appsLoading" x-cloak class="flex-1 flex items-center justify-center">
        <div class="flex items-center gap-2 text-gray-500">
            <svg class="animate-spin" style="width:1em;height:1em" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <span class="text-xs" x-text="appsLoading ? 'Loading applications...' : 'Loading servers...'"></span>
        </div>
    </div>

    {{-- SERVER LIST VIEW --}}
    <div x-show="!loading && !selectedServer && activeTab === 'servers'" x-cloak class="flex-1 overflow-y-auto p-3">
        <div x-show="servers.length === 0 && !error" class="flex items-center justify-center p-8">
            <div class="text-center text-gray-400 max-w-sm">
                <i class="fa-solid fa-server text-4xl mb-4 block text-gray-600"></i>
                <p class="font-medium text-gray-300 mb-2">No servers configured</p>
                <p class="text-xs mb-4">Add a server to start managing your infrastructure.</p>
                <button @click="showAddServer = true" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded-lg text-sm font-medium transition-colors">
                    <i class="fa-solid fa-plus mr-1"></i> Add Server
                </button>
            </div>
        </div>
        <div class="flex flex-col gap-2">
            <template x-for="server in servers" :key="server.id">
                <div class="bg-white/[0.03] hover:bg-white/[0.05] border border-white/5 hover:border-white/10 rounded-lg p-3 transition-colors">
                    <div class="flex items-center justify-between gap-2 cursor-pointer" @click="selectServer(server)">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <template x-if="isServerLoading(server.id)">
                                <div class="flex items-center gap-1.5 min-w-[70px]">
                                    <svg class="animate-spin text-blue-400" style="width:14px;height:14px" viewBox="0 0 24 24" fill="none">
                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                    </svg>
                                    <span class="text-[10px] text-blue-400" x-text="getServerLoadingText(server.id)"></span>
                                </div>
                            </template>
                            <template x-if="!isServerLoading(server.id)">
                                <i class="fa-solid" :class="statusIcon(server.status) + ' ' + statusColor(server.status)"></i>
                            </template>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-200 truncate" x-text="server.name"></span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded" :class="server.vps_setup_mode === 'private' ? 'bg-purple-500/15 text-purple-400' : 'bg-blue-500/15 text-blue-400'"
                                          x-show="server.has_vps_setup" x-text="server.vps_setup_mode"></span>
                                </div>
                                <div class="text-[11px] text-gray-500 font-mono" x-text="server.ssh_user + '@' + server.host"></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span x-show="server.has_proxy_nginx" class="text-[10px] px-1.5 py-0.5 rounded bg-green-500/15 text-green-400">
                                proxy <span x-text="server.proxy_nginx_version || ''"></span>
                            </span>
                            <i class="fa-solid fa-chevron-right text-[10px] text-gray-600"></i>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 mt-2 pt-2 border-t border-white/5" @click.stop>
                        <template x-if="!server.has_vps_setup && server.status !== 'unchecked' && server.status !== 'error'">
                            <div class="flex gap-1">
                                <button @click="installVpsSetup(server, 'public')" :disabled="!!actionLoading[server.id]"
                                        class="px-2 py-1 text-[10px] rounded bg-blue-500/20 hover:bg-blue-500/30 text-blue-400 transition-colors disabled:opacity-50">
                                    <i class="fa-solid" :class="actionLoading[server.id] === 'vps' ? 'fa-circle-notch fa-spin' : 'fa-download'"></i> VPS (public)
                                </button>
                                <button @click="installVpsSetup(server, 'private')" :disabled="!!actionLoading[server.id]"
                                        class="px-2 py-1 text-[10px] rounded bg-purple-500/20 hover:bg-purple-500/30 text-purple-400 transition-colors disabled:opacity-50">
                                    VPS (private)
                                </button>
                            </div>
                        </template>
                        <template x-if="server.has_vps_setup && !server.has_proxy_nginx && server.vps_setup_mode === 'public'">
                            <button @click="installProxy(server)" :disabled="!!actionLoading[server.id]"
                                    class="px-2 py-1 text-[10px] rounded bg-green-500/20 hover:bg-green-500/30 text-green-400 transition-colors disabled:opacity-50">
                                <i class="fa-solid" :class="actionLoading[server.id] === 'proxy' ? 'fa-circle-notch fa-spin' : 'fa-shield-halved'"></i> Install Proxy
                            </button>
                        </template>
                        <template x-if="server.status === 'error'">
                            <span class="text-[10px] text-red-400/70 italic">Connection failed</span>
                        </template>
                        <div class="flex-1"></div>
                        <button @click="removeServer(server)" :disabled="!!actionLoading[server.id]"
                                class="px-2 py-1 text-[10px] rounded bg-white/5 hover:bg-red-500/20 text-gray-500 hover:text-red-400 transition-colors disabled:opacity-50">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- GITHUB APPS VIEW --}}
    <div x-show="!loading && !selectedServer && activeTab === 'github'" x-cloak class="flex-1 overflow-y-auto p-3">
        <div x-show="githubLoading" class="flex items-center justify-center py-8">
            <div class="flex items-center gap-2 text-gray-500">
                <svg class="animate-spin" style="width:1em;height:1em" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                </svg>
                <span class="text-xs">Scanning GitHub repositories...</span>
            </div>
        </div>
        <div x-show="!githubLoading && githubApps.length === 0" class="text-center text-gray-500 py-8 text-xs">
            No deployable apps found in GitHub.
        </div>
        <div x-show="!githubLoading" class="flex flex-col gap-2">
            <template x-for="app in githubApps" :key="app.full_name">
                <div class="bg-white/[0.03] hover:bg-white/[0.05] border border-white/5 hover:border-white/10 rounded-lg p-3 transition-colors">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <i class="fa-brands fa-github text-gray-400"></i>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-200 truncate" x-text="app.repo"></span>
                                    <span x-show="app.visibility === 'private'" class="text-[10px] px-1.5 py-0.5 rounded bg-yellow-500/15 text-yellow-400">private</span>
                                </div>
                                <div class="text-[11px] text-gray-500" x-text="app.owner"></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-[10px] text-gray-500" x-text="formatDate(app.updated_at)"></span>
                            <button @click="openDeployModal(app)"
                                    :disabled="getReadyServers().length === 0"
                                    class="px-3 py-1.5 text-[11px] rounded-lg font-medium transition-colors"
                                    :class="getReadyServers().length === 0
                                        ? 'bg-white/5 text-gray-600 cursor-not-allowed'
                                        : 'bg-blue-600 hover:bg-blue-500 text-white'">
                                <i class="fa-solid fa-rocket mr-1"></i> Deploy
                            </button>
                        </div>
                    </div>
                    <div x-show="app.description" class="mt-2 text-[11px] text-gray-400 truncate" x-text="app.description"></div>
                </div>
            </template>
        </div>
        <div x-show="!githubLoading && getReadyServers().length === 0 && githubApps.length > 0"
             class="mt-4 p-3 bg-yellow-500/10 border border-yellow-500/20 rounded-lg text-xs text-yellow-400">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            No servers are ready for deployment. Set up a server with VPS Setup and Proxy first.
        </div>
    </div>

    {{-- APPLICATIONS VIEW (Server Detail) --}}
    <div x-show="!loading && !appsLoading && selectedServer" x-cloak class="flex-1 overflow-y-auto p-3">
        <div x-show="apps.length === 0" class="text-center text-gray-500 py-8 text-xs">
            No applications deployed on this server.
            <div class="mt-2">
                <button @click="activeTab = 'github'; goBack()" class="text-blue-400 hover:underline">
                    Browse available apps
                </button>
            </div>
        </div>
        <div class="flex flex-col gap-2">
            <template x-for="app in apps" :key="app.id">
                <div class="bg-white/[0.03] border border-white/5 rounded-lg p-3">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <i class="fa-solid" :class="statusIcon(app.status) + ' ' + statusColor(app.status)"></i>
                            <div class="min-w-0">
                                <div class="font-medium text-gray-200 truncate" x-text="app.name"></div>
                                <div class="text-[10px] text-gray-500 font-mono truncate" x-text="app.primary_domain || app.deploy_path"></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <span x-show="app.ssl_enabled" class="text-[10px] px-1.5 py-0.5 rounded bg-green-500/15 text-green-400">
                                <i class="fa-solid fa-lock"></i> SSL
                            </span>
                        </div>
                    </div>
                    {{-- Domains list --}}
                    <div x-show="app.domains && app.domains.length > 0" class="mt-2 flex flex-wrap gap-1">
                        <template x-for="domain in app.domains" :key="domain">
                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-white/5 text-gray-400 font-mono" x-text="domain"></span>
                        </template>
                    </div>
                    {{-- App actions --}}
                    <div class="flex items-center gap-1 mt-2 pt-2 border-t border-white/5">
                        <template x-if="app.status === 'stopped'">
                            <button @click="appAction(app, 'start')" :disabled="!!actionLoading[app.id]"
                                    class="px-2 py-1 text-[10px] rounded bg-green-500/20 hover:bg-green-500/30 text-green-400 transition-colors disabled:opacity-50">
                                <i class="fa-solid" :class="actionLoading[app.id] === 'start' ? 'fa-circle-notch fa-spin' : 'fa-play'"></i> Start
                            </button>
                        </template>
                        <template x-if="app.status === 'running'">
                            <button @click="appAction(app, 'stop')" :disabled="!!actionLoading[app.id]"
                                    class="px-2 py-1 text-[10px] rounded bg-red-500/20 hover:bg-red-500/30 text-red-400 transition-colors disabled:opacity-50">
                                <i class="fa-solid" :class="actionLoading[app.id] === 'stop' ? 'fa-circle-notch fa-spin' : 'fa-stop'"></i> Stop
                            </button>
                        </template>
                        <button @click="appAction(app, 'restart')" :disabled="!!actionLoading[app.id]"
                                class="px-2 py-1 text-[10px] rounded bg-white/5 hover:bg-white/10 text-gray-400 hover:text-white transition-colors disabled:opacity-50">
                            <i class="fa-solid" :class="actionLoading[app.id] === 'restart' ? 'fa-circle-notch fa-spin' : 'fa-arrows-rotate'"></i>
                        </button>
                        <button @click="viewLogs(app)"
                                class="px-2 py-1 text-[10px] rounded bg-white/5 hover:bg-white/10 text-gray-400 hover:text-white transition-colors">
                            <i class="fa-solid fa-file-lines"></i>
                        </button>
                        <button @click="openAddDomainModal(app)"
                                class="px-2 py-1 text-[10px] rounded bg-white/5 hover:bg-white/10 text-gray-400 hover:text-white transition-colors">
                            <i class="fa-solid fa-globe"></i> Domain
                        </button>
                        <template x-if="app.primary_domain && !app.ssl_enabled">
                            <button @click="requestSsl(app)" :disabled="!!actionLoading[app.id]"
                                    class="px-2 py-1 text-[10px] rounded bg-yellow-500/20 hover:bg-yellow-500/30 text-yellow-400 transition-colors disabled:opacity-50">
                                <i class="fa-solid" :class="actionLoading[app.id] === 'ssl' ? 'fa-circle-notch fa-spin' : 'fa-lock'"></i> SSL
                            </button>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- ADD SERVER MODAL --}}
    <div x-show="showAddServer" x-cloak
         class="absolute inset-0 bg-black/70 z-50 flex items-start justify-center pt-12 px-3"
         @click.self="showAddServer = false">
        <div class="w-full max-w-sm bg-gray-900 border border-white/10 rounded-xl shadow-2xl" @click.stop>
            <div class="flex items-center justify-between px-4 py-3 border-b border-white/5">
                <span class="font-medium text-sm">Add Server</span>
                <button @click="showAddServer = false" class="text-gray-500 hover:text-white text-lg leading-none">&times;</button>
            </div>
            <div class="p-4 flex flex-col gap-3">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Name</label>
                    <input type="text" x-model="serverForm.name" placeholder="PROD-01"
                           class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-blue-500/50">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Host (IP or hostname)</label>
                    <input type="text" x-model="serverForm.host" placeholder="91.99.211.185"
                           class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-blue-500/50">
                </div>
                <div class="flex gap-3">
                    <div class="flex-1">
                        <label class="block text-xs text-gray-400 mb-1">SSH User</label>
                        <input type="text" x-model="serverForm.ssh_user" placeholder="admin"
                               class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-blue-500/50">
                    </div>
                    <div class="w-20">
                        <label class="block text-xs text-gray-400 mb-1">Port</label>
                        <input type="number" x-model.number="serverForm.ssh_port"
                               class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-gray-200 focus:outline-none focus:border-blue-500/50">
                    </div>
                </div>
            </div>
            <div class="px-4 pb-4">
                <button @click="addServer()"
                        :disabled="saving || !serverForm.name || !serverForm.host"
                        class="w-full py-2.5 rounded-lg text-sm font-medium transition-colors"
                        :class="saving || !serverForm.name || !serverForm.host
                            ? 'bg-white/5 text-gray-600 cursor-not-allowed'
                            : 'bg-blue-600 hover:bg-blue-500 text-white'">
                    <span x-show="!saving">Add Server</span>
                    <span x-show="saving" x-cloak>
                        <svg class="animate-spin inline" style="width:1em;height:1em" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        Adding...
                    </span>
                </button>
            </div>
        </div>
    </div>

    {{-- SSH KEY MODAL --}}
    <div x-show="showSshKey" x-cloak
         class="absolute inset-0 bg-black/70 z-50 flex items-start justify-center pt-12 px-3"
         @click.self="showSshKey = false">
        <div class="w-full max-w-md bg-gray-900 border border-white/10 rounded-xl shadow-2xl" @click.stop>
            <div class="flex items-center justify-between px-4 py-3 border-b border-white/5">
                <span class="font-medium text-sm">SSH Key</span>
                <button @click="showSshKey = false" class="text-gray-500 hover:text-white text-lg leading-none">&times;</button>
            </div>
            <div class="p-4">
                <div x-show="!sshKey" class="text-center py-4">
                    <p class="text-gray-400 text-sm mb-4">No SSH key generated for this workspace.</p>
                    <button @click="generateSshKey()" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded-lg text-sm font-medium transition-colors">
                        <i class="fa-solid fa-key mr-1"></i> Generate SSH Key
                    </button>
                </div>
                <div x-show="sshKey" x-cloak>
                    <p class="text-gray-400 text-xs mb-2">Add this public key to your servers:</p>
                    <div class="bg-black/30 rounded-lg p-3 font-mono text-[11px] text-gray-300 break-all" x-text="sshKey"></div>
                    <div class="flex gap-2 mt-3">
                        <button @click="copySshKey()" class="flex-1 py-2 bg-white/5 hover:bg-white/10 rounded-lg text-sm text-gray-300 transition-colors">
                            <i class="fa-solid fa-copy mr-1"></i> Copy
                        </button>
                        <button @click="generateSshKey()" class="px-4 py-2 bg-white/5 hover:bg-white/10 rounded-lg text-sm text-gray-300 transition-colors">
                            <i class="fa-solid fa-arrows-rotate mr-1"></i> Regenerate
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- LOGS MODAL --}}
    <div x-show="showLogs" x-cloak
         class="absolute inset-0 bg-black/70 z-50 flex items-start justify-center pt-8 px-3"
         @click.self="showLogs = false">
        <div class="w-full max-w-2xl h-[70vh] bg-gray-900 border border-white/10 rounded-xl shadow-2xl flex flex-col" @click.stop>
            <div class="flex items-center justify-between px-4 py-3 border-b border-white/5 shrink-0">
                <span class="font-medium text-sm">Logs: <span x-text="logsApp?.name"></span></span>
                <button @click="showLogs = false" class="text-gray-500 hover:text-white text-lg leading-none">&times;</button>
            </div>
            <div class="flex-1 overflow-auto p-3">
                <pre class="font-mono text-[11px] text-gray-300 whitespace-pre-wrap" x-text="logs"></pre>
            </div>
        </div>
    </div>

    {{-- DEPLOY MODAL --}}
    <div x-show="showDeploy" x-cloak
         class="absolute inset-0 bg-black/70 z-50 flex items-start justify-center pt-8 px-3 overflow-y-auto"
         @click.self="showDeploy = false">
        <div class="w-full max-w-lg bg-gray-900 border border-white/10 rounded-xl shadow-2xl my-4" @click.stop>
            <div class="flex items-center justify-between px-4 py-3 border-b border-white/5">
                <span class="font-medium text-sm">Deploy <span x-text="deployApp?.repo"></span></span>
                <button @click="showDeploy = false" class="text-gray-500 hover:text-white text-lg leading-none">&times;</button>
            </div>
            <div class="p-4 flex flex-col gap-4">
                {{-- Server selection --}}
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Deploy to Server</label>
                    <select x-model="deployForm.server_id"
                            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-gray-200 focus:outline-none focus:border-blue-500/50">
                        <option value="">Select a server...</option>
                        <template x-for="server in getReadyServers()" :key="server.id">
                            <option :value="server.id" x-text="server.name + ' (' + server.host + ')'"></option>
                        </template>
                    </select>
                </div>

                {{-- Domain --}}
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Domain (optional)</label>
                    <input type="text" x-model="deployForm.domain" placeholder="myapp.example.com"
                           class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-blue-500/50">
                    <p class="text-[10px] text-gray-500 mt-1">Leave empty to configure later</p>
                </div>

                {{-- Environment variables --}}
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Environment Variables</label>
                    <div x-show="!deployConfig" class="text-gray-500 text-xs py-4 text-center">
                        <svg class="animate-spin inline mr-1" style="width:1em;height:1em" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        Loading configuration...
                    </div>
                    <textarea x-show="deployConfig" x-model="deployForm.env_content" rows="10"
                              class="w-full bg-black/30 border border-white/10 rounded-lg px-3 py-2 text-[11px] font-mono text-gray-300 focus:outline-none focus:border-blue-500/50 resize-none"
                              placeholder="APP_KEY=&#10;DB_PASSWORD=&#10;..."></textarea>
                </div>
            </div>
            <div class="px-4 pb-4">
                <button @click="deployToServer()"
                        :disabled="deploying || !deployForm.server_id"
                        class="w-full py-2.5 rounded-lg text-sm font-medium transition-colors"
                        :class="deploying || !deployForm.server_id
                            ? 'bg-white/5 text-gray-600 cursor-not-allowed'
                            : 'bg-blue-600 hover:bg-blue-500 text-white'">
                    <span x-show="!deploying"><i class="fa-solid fa-rocket mr-1"></i> Deploy</span>
                    <span x-show="deploying" x-cloak>
                        <svg class="animate-spin inline" style="width:1em;height:1em" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        Deploying...
                    </span>
                </button>
            </div>
        </div>
    </div>

    {{-- ADD DOMAIN MODAL --}}
    <div x-show="showAddDomain" x-cloak
         class="absolute inset-0 bg-black/70 z-50 flex items-start justify-center pt-12 px-3"
         @click.self="showAddDomain = false">
        <div class="w-full max-w-sm bg-gray-900 border border-white/10 rounded-xl shadow-2xl" @click.stop>
            <div class="flex items-center justify-between px-4 py-3 border-b border-white/5">
                <span class="font-medium text-sm">Add Domain</span>
                <button @click="showAddDomain = false" class="text-gray-500 hover:text-white text-lg leading-none">&times;</button>
            </div>
            <div class="p-4 flex flex-col gap-3">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Domain</label>
                    <input type="text" x-model="domainForm.domain" placeholder="myapp.example.com"
                           class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-blue-500/50">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Upstream Container</label>
                    <input type="text" x-model="domainForm.upstream"
                           class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-blue-500/50">
                    <p class="text-[10px] text-gray-500 mt-1">The container name that handles requests (usually {app}-nginx)</p>
                </div>
            </div>
            <div class="px-4 pb-4">
                <button @click="addDomain()"
                        :disabled="saving || !domainForm.domain"
                        class="w-full py-2.5 rounded-lg text-sm font-medium transition-colors"
                        :class="saving || !domainForm.domain
                            ? 'bg-white/5 text-gray-600 cursor-not-allowed'
                            : 'bg-blue-600 hover:bg-blue-500 text-white'">
                    <span x-show="!saving">Add Domain</span>
                    <span x-show="saving" x-cloak>
                        <svg class="animate-spin inline" style="width:1em;height:1em" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        Adding...
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
