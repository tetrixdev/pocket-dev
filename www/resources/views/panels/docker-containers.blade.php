<div x-data="{
    panelStateId: @js($panelStateId ?? null),
    containers: [],
    loading: true,
    error: null,
    lastUpdated: null,
    autoRefresh: false,
    autoRefreshInterval: null,
    expandedProjects: {},
    openDropdown: null,
    dropdownPos: { top: 0, left: 0 },
    actionLoading: {},
    actionMessage: {},

    // Logs view state
    viewingLogs: false,
    logsTitle: '',
    logsContent: '',
    logsLines: [],
    logsLoading: false,
    logsError: null,
    logsTail: 100,
    logsParams: {},
    copyMessage: null,

    init() {
        this.fetchContainers();
    },

    destroy() {
        if (this.autoRefreshInterval) {
            clearInterval(this.autoRefreshInterval);
            this.autoRefreshInterval = null;
        }
    },

    getShortName(container, group) {
        if (!group._prefix && group._prefix !== '') {
            const names = group.containers.map(c => c.name);
            if (names.length <= 1) {
                group._prefix = '';
            } else {
                let prefix = names[0];
                for (const name of names.slice(1)) {
                    while (name.indexOf(prefix) !== 0 && prefix.length > 0) {
                        prefix = prefix.slice(0, -1);
                    }
                }
                const lastSep = Math.max(prefix.lastIndexOf('-'), prefix.lastIndexOf('_'));
                group._prefix = lastSep > 0 ? prefix.slice(0, lastSep + 1) : prefix;
            }
        }
        return container.name.slice(group._prefix.length) || container.name;
    },

    get groupedContainers() {
        const groups = {};
        this.containers.forEach(c => {
            const project = c.project || 'other';
            if (!groups[project]) {
                groups[project] = {
                    name: project,
                    containers: [],
                    running: 0,
                    total: 0,
                    healthy: 0,
                    unhealthy: 0,
                    ports: [],
                    working_dir: c.working_dir || '',
                    isProtected: project === 'pocket-dev'
                };
            }
            groups[project].containers.push(c);
            groups[project].total++;
            if (c.running) groups[project].running++;
            if (c.health === 'healthy') groups[project].healthy++;
            if (c.health === 'unhealthy') groups[project].unhealthy++;

            if (!groups[project].working_dir && c.working_dir) {
                groups[project].working_dir = c.working_dir;
            }

            if (c.ports) {
                c.ports.forEach(p => {
                    // Match both IPv4 (0.0.0.0:PORT) and IPv6 ([::]:PORT) bindings
                    if (p.includes('0.0.0.0:') || p.includes('[::]:')) {
                        const match = p.match(/(?:0\.0\.0\.0|\[::\]):(\d+)->(\d+)/);
                        if (match && !groups[project].ports.includes(match[1])) {
                            groups[project].ports.push(match[1]);
                        }
                    }
                });
            }
        });

        return Object.values(groups).sort((a, b) => {
            if (a.running > 0 && b.running === 0) return -1;
            if (a.running === 0 && b.running > 0) return 1;
            return a.name.localeCompare(b.name);
        });
    },

    async fetchContainers() {
        this.loading = true;
        this.error = null;

        try {
            const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'refresh', params: {} })
            });
            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }
            const result = await response.json();

            if (result.ok && result.data) {
                this.containers = result.data.containers || [];
                this.lastUpdated = new Date().toLocaleTimeString();
            } else {
                this.error = result.error || 'Failed to fetch container data';
            }
        } catch (e) {
            this.error = e.message;
        } finally {
            this.loading = false;
        }
    },

    toggleAutoRefresh() {
        this.autoRefresh = !this.autoRefresh;
        if (this.autoRefresh) {
            this.autoRefreshInterval = setInterval(() => this.fetchContainers(), 5000);
        } else {
            clearInterval(this.autoRefreshInterval);
            this.autoRefreshInterval = null;
        }
    },

    toggleProject(name) {
        this.expandedProjects[name] = !this.expandedProjects[name];
    },

    toggleDropdown(name, event) {
        if (this.openDropdown === name) {
            this.openDropdown = null;
            return;
        }
        const btn = event.currentTarget;
        const rect = btn.getBoundingClientRect();
        this.dropdownPos = {
            top: rect.bottom + 4,
            left: rect.right - 170
        };
        this.openDropdown = name;
    },

    closeDropdowns() {
        this.openDropdown = null;
    },

    async runAction(group, action) {
        this.openDropdown = null;
        this.actionLoading[group.name] = true;
        this.actionMessage[group.name] = null;

        try {
            const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: action,
                    params: {
                        project: group.name,
                        working_dir: group.working_dir
                    }
                })
            });
            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }
            const result = await response.json();

            if (result.ok && result.data) {
                this.actionMessage[group.name] = { type: 'success', text: result.data.message || 'Done' };
            } else {
                this.actionMessage[group.name] = { type: 'error', text: result.error || 'Action failed' };
            }
            setTimeout(() => this.fetchContainers(), 1500);
        } catch (e) {
            this.actionMessage[group.name] = { type: 'error', text: e.message };
        } finally {
            this.actionLoading[group.name] = false;
            setTimeout(() => { this.actionMessage[group.name] = null; }, 6000);
        }
    },

    getProjectStatus(group) {
        if (group.running === 0) return 'stopped';
        if (group.unhealthy > 0) return 'unhealthy';
        if (group.running < group.total) return 'partial';
        return 'healthy';
    },

    getProjectStatusColor(group) {
        const s = this.getProjectStatus(group);
        return s === 'stopped' ? 'text-gray-400' : s === 'unhealthy' ? 'text-red-400' : s === 'partial' ? 'text-yellow-400' : 'text-green-400';
    },

    getProjectDotColor(group) {
        const s = this.getProjectStatus(group);
        return s === 'stopped' ? 'bg-gray-500' : s === 'unhealthy' ? 'bg-red-400' : s === 'partial' ? 'bg-yellow-400' : 'bg-green-400';
    },

    getContainerDotColor(c) {
        if (!c.running) return 'bg-gray-500';
        if (c.health === 'unhealthy') return 'bg-red-400';
        if (c.health === 'healthy') return 'bg-green-400';
        return 'bg-blue-400';
    },

    getContainerStatusColor(c) {
        if (!c.running) return 'text-gray-400';
        if (c.health === 'unhealthy') return 'text-red-400';
        if (c.health === 'healthy') return 'text-green-400';
        return 'text-blue-400';
    },

    async openLogs(group, container = null) {
        this.openDropdown = null;
        this.viewingLogs = true;
        this.logsLoading = true;
        this.logsError = null;
        this.logsContent = '';
        this.logsLines = [];
        this.logsTail = 100;
        this.logsParams = {
            project: group.name,
            working_dir: group.working_dir,
            container: container?.name || null
        };
        this.logsTitle = container ? container.name : `${group.name} (all services)`;
        await this.fetchLogs();
    },

    async fetchLogs() {
        this.logsLoading = true;
        this.logsError = null;

        try {
            const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'logs',
                    params: { ...this.logsParams, tail: this.logsTail }
                })
            });
            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }
            const result = await response.json();

            if (result.ok && result.data) {
                this.logsContent = result.data.logs || '(no logs)';
                this.logsLines = this.parseLogLines(this.logsContent);
            } else {
                this.logsError = result.error || 'Failed to fetch logs';
            }
        } catch (e) {
            this.logsError = e.message;
        } finally {
            this.logsLoading = false;
        }
    },

    parseLogLines(content) {
        return content.split('\n').map(line => {
            // Determine line type for color coding
            const lower = line.toLowerCase();
            let type = 'normal';
            if (/\b(error|fatal|panic|exception|fail(ed)?)\b/i.test(line)) {
                type = 'error';
            } else if (/\b(warn(ing)?)\b/i.test(line)) {
                type = 'warning';
            } else if (/\b(info)\b/i.test(line)) {
                type = 'info';
            } else if (/\b(debug|trace)\b/i.test(line)) {
                type = 'debug';
            } else if (line.startsWith('===') && line.endsWith('===')) {
                type = 'header';
            }
            return { text: line, type };
        });
    },

    getLineClass(type) {
        switch (type) {
            case 'error': return 'text-red-400 bg-red-500/10';
            case 'warning': return 'text-yellow-400 bg-yellow-500/5';
            case 'info': return 'text-blue-300';
            case 'debug': return 'text-gray-500';
            case 'header': return 'text-purple-400 font-semibold bg-purple-500/10';
            default: return 'text-gray-300';
        }
    },

    async loadMoreLogs() {
        this.logsTail = Math.min(this.logsTail + 200, 1000);
        await this.fetchLogs();
    },

    async copyLogs() {
        try {
            await navigator.clipboard.writeText(this.logsContent);
            this.copyMessage = 'Copied!';
        } catch (e) {
            this.copyMessage = 'Copy failed';
        }
        setTimeout(() => { this.copyMessage = null; }, 2000);
    },

    closeLogs() {
        this.viewingLogs = false;
        this.logsContent = '';
        this.logsLines = [];
        this.logsParams = {};
    }
}" @click="closeDropdowns()" class="h-full flex flex-col bg-gray-900 text-gray-100">

    {{-- Fixed dropdown menu (rendered at body level to avoid overflow clipping) --}}
    <template x-if="openDropdown">
        <div
            @click.stop
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="fixed z-[9999] bg-gray-700 rounded-lg shadow-xl border border-gray-600 py-1 min-w-[170px]"
            :style="`top: ${dropdownPos.top}px; left: ${dropdownPos.left}px;`"
        >
            <template x-for="group in groupedContainers.filter(g => g.name === openDropdown)" :key="group.name">
                <div>
                    {{-- View Logs (always available) --}}
                    <button
                        @click="openLogs(group)"
                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-gray-200 hover:bg-gray-600 transition-colors"
                    >
                        <i class="fa-solid fa-file-lines text-blue-400 w-4 text-center text-xs"></i>
                        <span>View Logs</span>
                    </button>

                    {{-- Divider before control actions (if not protected and has actions) --}}
                    <div x-show="!group.isProtected && (group.running < group.total || group.running > 0)" class="border-t border-gray-600 my-1"></div>

                    {{-- Start (show when not all running, not for protected) --}}
                    <button
                        x-show="!group.isProtected && group.running < group.total"
                        @click="runAction(group, 'start')"
                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-gray-200 hover:bg-gray-600 transition-colors"
                    >
                        <i class="fa-solid fa-play text-green-400 w-4 text-center text-xs"></i>
                        <span>Start</span>
                    </button>

                    {{-- Restart (show when at least some running, not for protected) --}}
                    <button
                        x-show="!group.isProtected && group.running > 0"
                        @click="runAction(group, 'restart')"
                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-gray-200 hover:bg-gray-600 transition-colors"
                    >
                        <i class="fa-solid fa-rotate text-yellow-400 w-4 text-center text-xs"></i>
                        <span>Restart</span>
                    </button>

                    {{-- Divider --}}
                    <div x-show="!group.isProtected && group.running > 0" class="border-t border-gray-600 my-1"></div>

                    {{-- Stop (show when at least some running, not for protected) --}}
                    <button
                        x-show="!group.isProtected && group.running > 0"
                        @click="runAction(group, 'stop')"
                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-red-400 hover:bg-gray-600 transition-colors"
                    >
                        <i class="fa-solid fa-stop w-4 text-center text-xs"></i>
                        <span>Stop</span>
                    </button>
                </div>
            </template>
        </div>
    </template>

    {{-- Container List View --}}
    <div x-show="!viewingLogs" class="h-full flex flex-col">
        {{-- Header - Two rows on mobile --}}
        <div class="flex-none px-4 py-3 border-b border-gray-700 bg-gray-800/50">
            {{-- Row 1: Title --}}
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fa-brands fa-docker text-blue-400 text-lg"></i>
                    <h2 class="text-base sm:text-lg font-semibold">Docker Containers</h2>
                    <span class="text-xs sm:text-sm text-gray-400" x-show="groupedContainers.length > 0" x-text="`(${groupedContainers.length})`"></span>
                </div>
                {{-- Desktop: buttons inline --}}
                <div class="hidden sm:flex items-center gap-2">
                    <span class="text-xs text-gray-500" x-show="lastUpdated" x-text="`Updated: ${lastUpdated}`"></span>
                    <button
                        @click.stop="toggleAutoRefresh()"
                        :class="autoRefresh ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                        class="px-2 py-1 text-xs rounded transition-colors flex items-center gap-1"
                        title="Auto-refresh every 5s"
                    >
                        <i class="fa-solid fa-arrows-rotate text-[10px]" :class="autoRefresh && 'animate-spin'"></i>
                        Auto
                    </button>
                    <button
                        @click.stop="fetchContainers()"
                        :disabled="loading"
                        class="px-3 py-1 text-xs bg-gray-700 text-gray-300 rounded hover:bg-gray-600 transition-colors disabled:opacity-50"
                    >
                        <span x-show="!loading">Refresh</span>
                        <span x-show="loading">Loading...</span>
                    </button>
                </div>
            </div>
            {{-- Row 2: Mobile controls --}}
            <div class="flex sm:hidden items-center justify-between mt-2 pt-2 border-t border-gray-700/50">
                <span class="text-[10px] text-gray-500" x-show="lastUpdated" x-text="`Updated: ${lastUpdated}`"></span>
                <div class="flex items-center gap-2">
                    <button
                        @click.stop="toggleAutoRefresh()"
                        :class="autoRefresh ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-300'"
                        class="px-2 py-1 text-xs rounded transition-colors flex items-center gap-1"
                    >
                        <i class="fa-solid fa-arrows-rotate text-[10px]" :class="autoRefresh && 'animate-spin'"></i>
                        Auto
                    </button>
                    <button
                        @click.stop="fetchContainers()"
                        :disabled="loading"
                        class="px-2 py-1 text-xs bg-gray-700 text-gray-300 rounded transition-colors disabled:opacity-50"
                    >
                        <i class="fa-solid fa-sync text-[10px]" x-show="!loading"></i>
                        <x-spinner class="text-[10px]" x-show="loading" x-cloak />
                    </button>
                </div>
            </div>
        </div>

        {{-- Content --}}
        <div class="flex-1 overflow-auto p-4">
            {{-- Loading state --}}
            <div x-show="loading && containers.length === 0" class="flex items-center justify-center h-32">
                <div class="flex items-center gap-2 text-gray-400">
                    <x-spinner />
                    <span>Loading containers...</span>
                </div>
            </div>

            {{-- Error state --}}
            <div x-show="error" class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 text-red-400">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span x-text="error"></span>
                </div>
            </div>

            {{-- Empty state --}}
            <div x-show="!loading && !error && containers.length === 0" class="flex flex-col items-center justify-center h-32 text-gray-500">
                <i class="fa-solid fa-box-open text-3xl mb-2"></i>
                <span>No containers found</span>
            </div>

            {{-- Project groups --}}
            <div x-show="groupedContainers.length > 0" class="space-y-3">
                <template x-for="group in groupedContainers" :key="group.name">
                    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
                        {{-- Project header --}}
                        <div
                            @click="toggleProject(group.name)"
                            class="p-3 cursor-pointer hover:bg-gray-750 transition-colors"
                        >
                            {{-- Row 1: Main info --}}
                            <div class="flex items-center gap-2 sm:gap-3">
                                {{-- Expand icon --}}
                                <i
                                    class="fa-solid fa-chevron-right text-[10px] text-gray-500 transition-transform w-3 flex-shrink-0"
                                    :class="expandedProjects[group.name] && 'rotate-90'"
                                ></i>

                                {{-- Status dot --}}
                                <div class="w-2 h-2 rounded-full flex-shrink-0" :class="getProjectDotColor(group)"></div>

                                {{-- Project name --}}
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <span class="font-medium text-gray-100 truncate" x-text="group.name"></span>

                                    {{-- Action message --}}
                                    <template x-if="actionMessage[group.name]">
                                        <span
                                            class="px-2 py-0.5 text-xs rounded animate-pulse flex-shrink-0"
                                            :class="actionMessage[group.name]?.type === 'error' ? 'bg-red-500/20 text-red-400' : 'bg-green-500/20 text-green-400'"
                                            x-text="actionMessage[group.name]?.text"
                                        ></span>
                                    </template>
                                </div>

                                {{-- Container count --}}
                                <div class="text-xs sm:text-sm flex-shrink-0" :class="getProjectStatusColor(group)">
                                    <span x-text="`${group.running}/${group.total}`"></span>
                                </div>

                                {{-- Desktop: Health badges --}}
                                <div class="hidden sm:flex items-center gap-1.5 flex-shrink-0">
                                    <template x-if="group.healthy > 0">
                                        <span class="px-1.5 py-0.5 text-xs rounded bg-green-500/20 text-green-400" x-text="`${group.healthy} healthy`"></span>
                                    </template>
                                    <template x-if="group.unhealthy > 0">
                                        <span class="px-1.5 py-0.5 text-xs rounded bg-red-500/20 text-red-400" x-text="`${group.unhealthy} unhealthy`"></span>
                                    </template>
                                </div>

                                {{-- Desktop: External ports --}}
                                <div class="hidden sm:flex items-center gap-1 flex-shrink-0" x-show="group.ports.length > 0">
                                    <template x-for="port in group.ports" :key="port">
                                        <span class="text-xs bg-blue-500/20 text-blue-300 px-1.5 py-0.5 rounded" x-text="`:${port}`"></span>
                                    </template>
                                </div>

                                {{-- Action button (available for all projects) --}}
                                <div @click.stop class="flex-shrink-0">
                                    <button
                                        @click="toggleDropdown(group.name, $event)"
                                        class="p-1.5 rounded hover:bg-gray-600 text-gray-400 hover:text-gray-200 transition-colors"
                                        :class="actionLoading[group.name] && 'pointer-events-none'"
                                    >
                                        <template x-if="!actionLoading[group.name]">
                                            <i class="fa-solid fa-ellipsis-vertical w-4 text-center"></i>
                                        </template>
                                        <template x-if="actionLoading[group.name]">
                                            <x-spinner class="w-4 text-center text-blue-400" />
                                        </template>
                                    </button>
                                </div>
                            </div>

                            {{-- Row 2: Mobile - Health badges and ports --}}
                            <div class="flex sm:hidden items-center gap-2 mt-1.5 ml-7" x-show="group.healthy > 0 || group.unhealthy > 0 || group.ports.length > 0">
                                {{-- Health badges --}}
                                <template x-if="group.healthy > 0">
                                    <span class="px-1.5 py-0.5 text-[10px] rounded bg-green-500/20 text-green-400" x-text="`${group.healthy} healthy`"></span>
                                </template>
                                <template x-if="group.unhealthy > 0">
                                    <span class="px-1.5 py-0.5 text-[10px] rounded bg-red-500/20 text-red-400" x-text="`${group.unhealthy} unhealthy`"></span>
                                </template>
                                {{-- Ports --}}
                                <template x-for="port in group.ports" :key="port">
                                    <span class="text-[10px] bg-blue-500/20 text-blue-300 px-1.5 py-0.5 rounded" x-text="`:${port}`"></span>
                                </template>
                            </div>
                        </div>

                        {{-- Expanded containers --}}
                        <div x-show="expandedProjects[group.name]" x-collapse class="border-t border-gray-700">
                            <template x-for="container in group.containers" :key="container.id">
                                <div class="px-4 py-2 border-b border-gray-700/50 last:border-b-0 hover:bg-gray-750/50">
                                    {{-- Row 1: Main container info --}}
                                    <div class="flex items-center gap-2 sm:gap-3">
                                        {{-- Indent + status dot --}}
                                        <div class="w-3 flex-shrink-0"></div>
                                        <div class="w-2 h-2 rounded-full flex-shrink-0" :class="getContainerDotColor(container)"></div>

                                        {{-- Container short name + image --}}
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm text-gray-200 truncate" x-text="getShortName(container, group)"></div>
                                            <div class="text-xs text-gray-500 truncate" x-text="container.image"></div>
                                        </div>

                                        {{-- Status --}}
                                        <div class="text-[10px] sm:text-xs whitespace-nowrap flex-shrink-0" :class="getContainerStatusColor(container)" x-text="container.status"></div>

                                        {{-- Desktop: Ports --}}
                                        <div class="hidden sm:flex items-center gap-1 flex-shrink-0">
                                            <template x-for="port in container.ports" :key="port">
                                                <span class="text-xs bg-gray-700 text-gray-400 px-1.5 py-0.5 rounded" x-text="port"></span>
                                            </template>
                                        </div>

                                        {{-- Logs button --}}
                                        <button
                                            @click.stop="openLogs(group, container)"
                                            class="p-1 text-gray-500 hover:text-blue-400 transition-colors flex-shrink-0"
                                            title="View container logs"
                                        >
                                            <i class="fa-solid fa-file-lines text-xs"></i>
                                        </button>
                                    </div>

                                    {{-- Row 2: Mobile - Ports (if any) --}}
                                    <div class="flex sm:hidden items-center gap-1 mt-1 ml-7 flex-wrap" x-show="container.ports && container.ports.length > 0">
                                        <template x-for="port in container.ports" :key="port">
                                            <span class="text-[10px] bg-gray-700 text-gray-400 px-1.5 py-0.5 rounded" x-text="port"></span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Logs View (Full Screen) --}}
    <div x-show="viewingLogs" x-cloak class="h-full flex flex-col">
        {{-- Logs Header --}}
        <div class="flex-none border-b border-gray-700 bg-gray-800/50">
            {{-- Row 1: Back + Title + Actions --}}
            <div class="flex items-center gap-2 p-2 md:p-3">
                {{-- Back button --}}
                <button @click="closeLogs()"
                        class="p-1.5 hover:bg-gray-700 rounded text-gray-400 hover:text-white transition-colors flex-shrink-0"
                        title="Back to containers">
                    <i class="fa-solid fa-arrow-left text-sm"></i>
                </button>

                {{-- Title --}}
                <div class="flex items-center gap-2 flex-1 min-w-0">
                    <i class="fa-solid fa-file-lines text-blue-400 flex-shrink-0"></i>
                    <span class="font-medium text-sm truncate" x-text="logsTitle"></span>
                </div>

                {{-- Desktop: Actions inline --}}
                <div class="hidden sm:flex items-center gap-2 flex-shrink-0">
                    <span class="text-xs text-gray-500" x-text="`${logsTail} lines`"></span>
                    <button
                        @click="loadMoreLogs()"
                        :disabled="logsLoading || logsTail >= 1000"
                        class="px-2 py-1 text-xs bg-gray-700 text-gray-300 rounded hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        <i class="fa-solid fa-plus mr-1"></i>More
                    </button>
                    <button
                        @click="fetchLogs()"
                        :disabled="logsLoading"
                        class="px-2 py-1 text-xs bg-gray-700 text-gray-300 rounded hover:bg-gray-600 disabled:opacity-50 transition-colors"
                        title="Refresh"
                    >
                        <i class="fa-solid fa-sync" :class="logsLoading && 'fa-spin'"></i>
                    </button>
                    <button
                        @click="copyLogs()"
                        class="px-2 py-1 text-xs bg-gray-700 text-gray-300 rounded hover:bg-gray-600 transition-colors"
                        title="Copy to clipboard"
                    >
                        <span x-show="!copyMessage"><i class="fa-solid fa-copy"></i></span>
                        <span x-show="copyMessage" x-text="copyMessage" x-cloak></span>
                    </button>
                </div>
            </div>

            {{-- Row 2: Mobile actions --}}
            <div class="flex sm:hidden items-center justify-between px-2 pb-2 gap-2">
                <span class="text-[10px] text-gray-500" x-text="`${logsTail} lines`"></span>
                <div class="flex items-center gap-1.5">
                    <button
                        @click="loadMoreLogs()"
                        :disabled="logsLoading || logsTail >= 1000"
                        class="px-2 py-1 text-xs bg-gray-700 text-gray-300 rounded disabled:opacity-50"
                    >
                        <i class="fa-solid fa-plus"></i>
                    </button>
                    <button
                        @click="fetchLogs()"
                        :disabled="logsLoading"
                        class="px-2 py-1 text-xs bg-gray-700 text-gray-300 rounded disabled:opacity-50"
                    >
                        <i class="fa-solid fa-sync" :class="logsLoading && 'fa-spin'"></i>
                    </button>
                    <button
                        @click="copyLogs()"
                        class="px-2 py-1 text-xs bg-gray-700 text-gray-300 rounded"
                    >
                        <span x-show="!copyMessage"><i class="fa-solid fa-copy"></i></span>
                        <span x-show="copyMessage" x-text="copyMessage" x-cloak></span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Logs Content --}}
        <div class="flex-1 overflow-auto">
            {{-- Loading --}}
            <div x-show="logsLoading && logsLines.length === 0" class="flex items-center justify-center h-full">
                <div class="flex items-center gap-2 text-gray-400">
                    <x-spinner />
                    <span>Loading logs...</span>
                </div>
            </div>

            {{-- Error --}}
            <div x-show="logsError && !logsLoading" class="p-4">
                <div class="bg-red-500/10 border border-red-500/30 rounded p-4 text-red-400">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i>
                    <span x-text="logsError"></span>
                </div>
            </div>

            {{-- Log Lines - single horizontal scroll for all content --}}
            <div x-show="!logsLoading || logsLines.length > 0" class="font-mono text-xs min-w-max">
                <template x-for="(line, idx) in logsLines" :key="idx">
                    <div
                        class="px-3 py-px hover:bg-white/5 whitespace-nowrap"
                        :class="getLineClass(line.type)"
                        x-text="line.text || ' '"
                    ></div>
                </template>
            </div>
        </div>
    </div>
</div>
