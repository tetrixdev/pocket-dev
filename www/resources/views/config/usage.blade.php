@extends('layouts.config')

@section('title', 'Usage')

@section('content')
<div x-data="usageDashboard()" class="space-y-5 max-w-6xl">

    {{-- Header row --}}
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-100">Provider Usage</h2>
            <p class="text-sm text-gray-400 mt-0.5">Token usage and cost estimates across CLI providers.</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            {{-- Date range --}}
            <select x-model="days" @change="switchRange()" class="text-xs bg-gray-800 border border-gray-600 text-gray-300 rounded px-2 py-1.5">
                <option value="7">7 days</option>
                <option value="14">14 days</option>
                <option value="30">30 days</option>
                <option value="90">90 days</option>
            </select>

            {{-- Auto-refresh --}}
            <select x-model="autoRefreshValue" @change="setAutoRefresh($event.target.value)"
                    class="text-xs bg-gray-800 border border-gray-600 text-gray-300 rounded px-2 py-1.5">
                <option value="0">Auto-refresh: off</option>
                <option value="15">Auto-refresh: 15s</option>
                <option value="30">Auto-refresh: 30s</option>
                <option value="60">Auto-refresh: 1m</option>
                <option value="120">Auto-refresh: 2m</option>
                <option value="300">Auto-refresh: 5m</option>
            </select>
            <span class="text-xs font-mono w-7 text-right" x-show="autoRefreshValue != '0'" x-cloak
                  :class="countdown <= 5 ? 'text-amber-400' : 'text-gray-500'" x-text="countdown + 's'"></span>

            <button @click="refresh()" class="px-3 py-1.5 text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 rounded transition"
                    :disabled="loading" type="button" aria-label="Refresh usage data" title="Refresh usage data">
                <i class="fa-solid fa-arrows-rotate" :class="loading && 'animate-spin'"></i>
            </button>
        </div>
    </div>

    {{-- Loading --}}
    <div x-show="loading && !summary" class="flex items-center justify-center py-16">
        <svg class="animate-spin w-6 h-6 text-blue-400" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <span class="ml-3 text-sm text-gray-400">Loading usage data...</span>
    </div>

    {{-- ============================================================ --}}
    {{-- GRAND TOTALS BAR                                             --}}
    {{-- ============================================================ --}}
    <div x-show="summary" x-cloak class="bg-gradient-to-r from-gray-800/80 to-gray-800/40 border border-gray-700 rounded-lg px-5 py-3 flex items-center justify-between flex-wrap gap-3">
        <div class="text-sm text-gray-300 font-medium">
            <i class="fa-solid fa-calculator mr-1 text-gray-500"></i> Total
            <span class="text-gray-500 text-xs ml-1" x-text="'(' + days + 'd)'"></span>
        </div>
        <div class="flex items-center gap-6 text-xs">
            <div>
                <span class="text-gray-500">Today</span>
                <span class="text-gray-200 font-mono ml-1" x-text="fmt(summary?.totals?.today?.total_tokens)"></span>
                <span class="text-emerald-400 font-mono ml-1" x-text="'$' + (summary?.totals?.today?.api_equiv_cost || 0).toFixed(2)"></span>
            </div>
            <div>
                <span class="text-gray-500">7d</span>
                <span class="text-gray-200 font-mono ml-1" x-text="fmt(summary?.totals?.week?.total_tokens)"></span>
                <span class="text-emerald-400 font-mono ml-1" x-text="'$' + (summary?.totals?.week?.api_equiv_cost || 0).toFixed(2)"></span>
            </div>
            <div>
                <span class="text-gray-500" x-text="days + 'd'"></span>
                <span class="text-gray-200 font-mono ml-1" x-text="fmt(summary?.totals?.total?.total_tokens)"></span>
                <span class="text-emerald-400 font-mono ml-1" x-text="'$' + (summary?.totals?.total?.api_equiv_cost || 0).toFixed(2)"></span>
            </div>
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- PROVIDER CARDS                                               --}}
    {{-- ============================================================ --}}
    <div x-show="summary" x-cloak class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <template x-for="p in ['claude_code', 'codex', 'cursor_agent']" :key="p">
            <div class="bg-gray-800/50 border rounded-lg p-4 cursor-pointer transition-all"
                 :class="filterProvider === p ? 'border-blue-500 ring-1 ring-blue-500/30' : 'border-gray-700 hover:border-gray-600'"
                 @click="toggleProvider(p)">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-2 h-2 rounded-full" :class="providerColor(p)"></span>
                    <h3 class="text-sm font-medium text-gray-200" x-text="providerName(p)"></h3>
                    <i x-show="filterProvider === p" class="fa-solid fa-filter text-blue-400 text-[10px] ml-auto" x-cloak></i>
                </div>
                <div class="space-y-1.5 text-xs">
                    <div class="flex justify-between"><span class="text-gray-400">Today</span><span class="text-gray-200 font-mono" x-text="fmt(getStat(p, 'today', 'total_tokens'))"></span></div>
                    <div class="flex justify-between"><span class="text-gray-400">7 days</span><span class="text-gray-200 font-mono" x-text="fmt(getStat(p, 'week', 'total_tokens'))"></span></div>
                    <div x-show="days != 7" class="flex justify-between"><span class="text-gray-400" x-text="days + 'd'"></span><span class="text-gray-200 font-mono" x-text="fmt(getStat(p, 'total', 'total_tokens'))"></span></div>
                    <div class="border-t border-gray-700 pt-1.5 mt-1.5 space-y-1">
                        <div class="flex justify-between">
                            <span class="text-gray-500">~Cost today</span>
                            <span class="text-emerald-400 font-mono" x-text="'$' + getStat(p, 'today', 'api_equiv_cost').toFixed(2)"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500" x-text="'~Cost ' + days + 'd'"></span>
                            <span class="text-emerald-400 font-mono" x-text="'$' + getStat(p, 'total', 'api_equiv_cost').toFixed(2)"></span>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- ============================================================ --}}
    {{-- CLAUDE CODE RATE LIMITS                                      --}}
    {{-- ============================================================ --}}
    <div x-show="summary" x-cloak class="bg-gray-800/50 border border-gray-700 rounded-lg p-4">
        <h3 class="text-sm font-medium text-gray-200 mb-3">
            <i class="fa-solid fa-gauge-high mr-1 text-purple-400"></i> Claude Code Rate Limits
        </h3>
        <template x-if="limits && limits.available">
            <div class="space-y-3">
                <template x-for="lim in limitBars" :key="lim.label">
                    <div>
                        <div class="flex justify-between text-xs mb-1">
                            <span class="text-gray-400" x-text="lim.label"></span>
                            <span class="font-mono" :class="barTextColor(lim.util)">
                                <span x-text="Math.round(lim.util)"></span>%
                                <span class="text-gray-500 ml-2" x-text="'resets ' + resetIn(lim.resets)"></span>
                            </span>
                        </div>
                        <div class="w-full h-2 bg-gray-700 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500"
                                 :class="barBgColor(lim.util)"
                                 :style="'width:' + lim.util + '%'"></div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
        <template x-if="!limits || !limits.available">
            <p class="text-xs text-gray-500">
                <i class="fa-solid fa-circle-info mr-1"></i>
                <span x-text="limits?.reason === 'no_token' ? 'No OAuth token found. Authenticate Claude Code via OAuth.' : 'Unable to fetch rate limits.'"></span>
            </p>
        </template>
    </div>

    {{-- ============================================================ --}}
    {{-- CURSOR USAGE & PLAN                                          --}}
    {{-- ============================================================ --}}
    <div x-show="summary" x-cloak class="bg-gray-800/50 border border-gray-700 rounded-lg p-4">
        <h3 class="text-sm font-medium text-gray-200 mb-3">
            <i class="fa-solid fa-arrow-pointer mr-1 text-emerald-400"></i> Cursor Usage
        </h3>
        <template x-if="cursorData && cursorData.available">
            <div class="space-y-3">
                {{-- Plan info --}}
                <div class="flex items-center gap-3 text-xs">
                    <span class="text-gray-400">Plan:</span>
                    <span class="font-medium" :class="{
                        'text-gray-500': cursorData.plan.type === 'free',
                        'text-blue-400': cursorData.plan.type === 'pro',
                        'text-purple-400': cursorData.plan.type === 'pro_plus' || cursorData.plan.type === 'business',
                        'text-amber-400': cursorData.plan.type === 'ultra',
                    }" x-text="(cursorData.plan.type || 'unknown').replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())"></span>
                    <template x-if="cursorData.plan.is_yearly">
                        <span class="text-gray-500">(yearly)</span>
                    </template>
                    <template x-if="cursorData.plan.is_team">
                        <span class="bg-blue-900/30 text-blue-300 px-1.5 py-0.5 rounded text-[10px]">Team</span>
                    </template>
                </div>

                {{-- Usage bar (if max_requests available = paid plan) --}}
                <template x-if="cursorData.usage.max_requests">
                    <div>
                        <div class="flex justify-between text-xs mb-1">
                            <span class="text-gray-400">Requests this month</span>
                            <span class="font-mono" :class="barTextColor((cursorData.usage.requests_used / cursorData.usage.max_requests) * 100)">
                                <span x-text="cursorData.usage.requests_used"></span> / <span x-text="cursorData.usage.max_requests"></span>
                            </span>
                        </div>
                        <div class="w-full h-2 bg-gray-700 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500"
                                 :class="barBgColor((cursorData.usage.requests_used / cursorData.usage.max_requests) * 100)"
                                 :style="'width:' + Math.min(100, (cursorData.usage.requests_used / cursorData.usage.max_requests) * 100) + '%'"></div>
                        </div>
                    </div>
                </template>

                {{-- Token count + billing cycle --}}
                <div class="flex items-center gap-6 text-xs">
                    <template x-if="cursorData.usage.tokens_used > 0">
                        <div>
                            <span class="text-gray-400">Tokens used:</span>
                            <span class="text-gray-200 font-mono ml-1" x-text="fmt(cursorData.usage.tokens_used)"></span>
                        </div>
                    </template>
                    <div>
                        <span class="text-gray-400">Requests total:</span>
                        <span class="text-gray-200 font-mono ml-1" x-text="cursorData.usage.requests_total"></span>
                    </div>
                    <template x-if="cursorData.usage.start_of_month">
                        <div>
                            <span class="text-gray-400">Cycle started:</span>
                            <span class="text-gray-200 ml-1" x-text="new Date(cursorData.usage.start_of_month).toLocaleDateString()"></span>
                        </div>
                    </template>
                </div>
            </div>
        </template>
        <template x-if="!cursorData || !cursorData.available">
            <p class="text-xs text-gray-500">
                <i class="fa-solid fa-circle-info mr-1"></i>
                <span x-text="cursorData?.reason === 'no_credentials' ? 'No Cursor auth found. Run cursor agent login first.' : 'Unable to fetch Cursor usage data.'"></span>
            </p>
        </template>
    </div>

    {{-- ============================================================ --}}
    {{-- CHART with filter controls                                   --}}
    {{-- ============================================================ --}}
    <div x-show="summary" x-cloak class="bg-gray-800/50 border border-gray-700 rounded-lg p-4">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
            <h3 class="text-sm font-medium text-gray-200">
                <i class="fa-solid fa-chart-bar mr-1 text-blue-400"></i>
                Token History
            </h3>
            <div class="flex items-center gap-2">
                <select x-model="chartGroupBy" @change="renderChart()" class="text-xs bg-gray-800 border border-gray-600 text-gray-300 rounded px-2 py-1">
                    <option value="provider">By Provider</option>
                    <option value="model">By Model</option>
                </select>
                <select x-model="chartMetric" @change="renderChart()" class="text-xs bg-gray-800 border border-gray-600 text-gray-300 rounded px-2 py-1">
                    <option value="tokens">Tokens</option>
                    <option value="cost">~Cost ($)</option>
                </select>
            </div>
        </div>
        <div class="relative" style="height: 260px;">
            <canvas x-ref="chart"></canvas>
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- MODEL BREAKDOWN TABLE                                        --}}
    {{-- ============================================================ --}}
    <div x-show="summary && Object.keys(summary.by_model || {}).length > 0" x-cloak class="bg-gray-800/50 border border-gray-700 rounded-lg p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-medium text-gray-200">
                <i class="fa-solid fa-microchip mr-1 text-gray-400"></i> By Model
            </h3>
            <div class="flex items-center gap-2">
                <select x-model="tablePeriod" class="text-xs bg-gray-800 border border-gray-600 text-gray-300 rounded px-2 py-1">
                    <option value="today">Today</option>
                    <option value="week">7 days</option>
                    <option value="total">All</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-gray-500 border-b border-gray-700">
                        <th class="text-left py-2 pr-3 cursor-pointer select-none" @click="sortBy('model')">
                            Model <i class="fa-solid fa-sort text-gray-600 ml-0.5"></i>
                        </th>
                        <th class="text-left py-2 px-2 cursor-pointer select-none" @click="sortBy('provider')">
                            Provider <i class="fa-solid fa-sort text-gray-600 ml-0.5"></i>
                        </th>
                        <th class="text-right py-2 px-2 cursor-pointer select-none" @click="sortBy('input')">
                            Input <i class="fa-solid fa-sort text-gray-600 ml-0.5"></i>
                        </th>
                        <th class="text-right py-2 px-2 cursor-pointer select-none" @click="sortBy('output')">
                            Output <i class="fa-solid fa-sort text-gray-600 ml-0.5"></i>
                        </th>
                        <th class="text-right py-2 px-2 cursor-pointer select-none" @click="sortBy('total')">
                            Total <i class="fa-solid fa-sort text-gray-600 ml-0.5"></i>
                        </th>
                        <th class="text-right py-2 px-2 cursor-pointer select-none" @click="sortBy('convos')">
                            Convos <i class="fa-solid fa-sort text-gray-600 ml-0.5"></i>
                        </th>
                        <th class="text-right py-2 pl-2 cursor-pointer select-none" @click="sortBy('cost')">
                            ~Cost <i class="fa-solid fa-sort text-gray-600 ml-0.5"></i>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in modelRows" :key="row.model">
                        <tr class="border-b border-gray-700/50 hover:bg-gray-700/20 cursor-pointer"
                            :class="filterModel === row.model && 'bg-blue-900/20'"
                            @click="toggleModel(row.model)">
                            <td class="py-2 pr-3 font-mono text-gray-200">
                                <span class="inline-block w-2 h-2 rounded-full mr-1.5" :class="providerColor(row.provider)"></span>
                                <span x-text="row.model"></span>
                            </td>
                            <td class="py-2 px-2 text-gray-400" x-text="providerName(row.provider)"></td>
                            <td class="text-right py-2 px-2 font-mono text-gray-300" x-text="fmt(row.input)"></td>
                            <td class="text-right py-2 px-2 font-mono text-gray-300" x-text="fmt(row.output)"></td>
                            <td class="text-right py-2 px-2 font-mono text-gray-200" x-text="fmt(row.total)"></td>
                            <td class="text-right py-2 px-2 font-mono text-gray-300" x-text="row.convos"></td>
                            <td class="text-right py-2 pl-2 font-mono text-emerald-400" x-text="'$' + row.cost.toFixed(2)"></td>
                        </tr>
                    </template>
                </tbody>
                <tfoot x-show="modelRows.length > 1">
                    <tr class="border-t border-gray-600 text-gray-300 font-medium">
                        <td class="py-2 pr-3" colspan="2">Total</td>
                        <td class="text-right py-2 px-2 font-mono" x-text="fmt(modelRows.reduce((s,r) => s + r.input, 0))"></td>
                        <td class="text-right py-2 px-2 font-mono" x-text="fmt(modelRows.reduce((s,r) => s + r.output, 0))"></td>
                        <td class="text-right py-2 px-2 font-mono" x-text="fmt(modelRows.reduce((s,r) => s + r.total, 0))"></td>
                        <td class="text-right py-2 px-2 font-mono" x-text="modelRows.reduce((s,r) => s + r.convos, 0)"></td>
                        <td class="text-right py-2 pl-2 font-mono text-emerald-400" x-text="'$' + modelRows.reduce((s,r) => s + r.cost, 0).toFixed(2)"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div x-show="filterProvider || filterModel" class="mt-3 flex items-center gap-2">
            <span class="text-xs text-gray-500">Active filters:</span>
            <template x-if="filterProvider">
                <span class="text-xs bg-blue-900/30 text-blue-300 px-2 py-0.5 rounded-full inline-flex items-center gap-1">
                    <span x-text="providerName(filterProvider)"></span>
                    <button @click="filterProvider = null; refresh()" class="hover:text-white" type="button" aria-label="Clear provider filter">&times;</button>
                </span>
            </template>
            <template x-if="filterModel">
                <span class="text-xs bg-purple-900/30 text-purple-300 px-2 py-0.5 rounded-full inline-flex items-center gap-1">
                    <span x-text="filterModel"></span>
                    <button @click="filterModel = null; refresh()" class="hover:text-white" type="button" aria-label="Clear model filter">&times;</button>
                </span>
            </template>
            <button @click="filterProvider = null; filterModel = null; refresh()" class="text-xs text-gray-500 hover:text-gray-300 ml-1">Clear all</button>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function usageDashboard() {
    return {
        // Data
        summary: null,
        limits: null,
        cursorData: null,
        loading: true,

        // Filters
        days: 14,
        filterProvider: null,
        filterModel: null,

        // Auto-refresh ('0' = off, '15'/'30'/'60'/'120'/'300' = seconds)
        autoRefreshValue: '30',
        countdown: 30,
        _timer: null,
        _refreshInFlight: false,

        // Chart
        chart: null,
        chartGroupBy: 'provider',
        chartMetric: 'tokens',

        // Table
        tablePeriod: 'week',
        sortKey: 'total',
        sortDir: -1, // -1 = desc

        // ============================================================
        // Lifecycle
        // ============================================================

        init() {
            this.refresh();
            this.startTimer();
        },

        destroy() {
            if (this._timer) clearInterval(this._timer);
            if (this.chart) this.chart.destroy();
        },

        startTimer() {
            if (this._timer) clearInterval(this._timer);
            const interval = parseInt(this.autoRefreshValue);
            if (!interval) return; // 0 = off
            this.countdown = interval;
            this._timer = setInterval(() => {
                this.countdown--;
                if (this.countdown <= 0) this.refresh();
            }, 1000);
        },

        setAutoRefresh(value) {
            this.autoRefreshValue = value;
            if (this._timer) clearInterval(this._timer);
            if (value !== '0') {
                this.startTimer();
            }
        },

        switchRange() {
            this.summary = null;
            this.refresh();
        },

        toggleProvider(p) {
            this.filterProvider = this.filterProvider === p ? null : p;
            this.filterModel = null;
            this.refresh();
        },

        toggleModel(m) {
            this.filterModel = this.filterModel === m ? null : m;
            this.refresh();
        },

        // ============================================================
        // Data fetching
        // ============================================================

        async refresh() {
            if (this._refreshInFlight) return;
            this._refreshInFlight = true;
            if (!this.summary) this.loading = true;
            const interval = parseInt(this.autoRefreshValue);
            this.countdown = interval || 30;

            try {
                let url = '/api/usage/summary?days=' + this.days;
                if (this.filterProvider) url += '&provider=' + this.filterProvider;
                if (this.filterModel) url += '&model=' + encodeURIComponent(this.filterModel);

                const fetchJson = async (endpoint) => {
                    const res = await fetch(endpoint);
                    if (!res.ok) throw new Error(`HTTP ${res.status} from ${endpoint}`);
                    return res.json();
                };

                const [s, l, c] = await Promise.allSettled([
                    fetchJson(url),
                    fetchJson('/api/usage/claude-limits'),
                    fetchJson('/api/usage/cursor-limits'),
                ]);

                if (s.status === 'fulfilled') this.summary = s.value;
                this.limits = l.status === 'fulfilled' ? l.value : { available: false, reason: 'fetch_failed' };
                this.cursorData = c.status === 'fulfilled' ? c.value : { available: false, reason: 'fetch_failed' };
            } catch (e) {
                console.error('Usage refresh failed:', e);
            } finally {
                this.loading = false;
                this._refreshInFlight = false;
                this.$nextTick(() => this.renderChart());
            }
        },

        // ============================================================
        // Computed: rate limit bars
        // ============================================================

        get limitBars() {
            if (!this.limits?.available) return [];
            const bars = [];
            const add = (key, label) => {
                const d = this.limits[key];
                if (d && d.utilization != null) bars.push({ label, util: d.utilization, resets: d.resets_at });
            };
            add('five_hour', '5-hour window');
            add('seven_day', '7-day window');
            add('seven_day_sonnet', '7-day Sonnet');
            add('seven_day_opus', '7-day Opus');
            return bars;
        },

        // ============================================================
        // Computed: model table rows
        // ============================================================

        get modelRows() {
            if (!this.summary?.by_model) return [];
            const rows = [];
            for (const [model, data] of Object.entries(this.summary.by_model)) {
                const p = data[this.tablePeriod];
                if (!p) continue;
                rows.push({
                    model,
                    provider: data.provider,
                    input: p.input_tokens,
                    output: p.output_tokens,
                    total: p.total_tokens,
                    convos: p.conversations,
                    cost: p.api_equiv_cost,
                });
            }

            const key = this.sortKey;
            const dir = this.sortDir;
            rows.sort((a, b) => {
                const va = a[key], vb = b[key];
                if (typeof va === 'string') return dir * va.localeCompare(vb);
                return dir * (va - vb);
            });

            return rows;
        },

        sortBy(key) {
            if (this.sortKey === key) {
                this.sortDir *= -1;
            } else {
                this.sortKey = key;
                this.sortDir = key === 'model' || key === 'provider' ? 1 : -1;
            }
        },

        // ============================================================
        // Chart
        // ============================================================

        renderChart() {
            const canvas = this.$refs.chart;
            if (!canvas || !this.summary?.by_day) return;
            if (this.chart) this.chart.destroy();

            const data = this.summary.by_day;
            const dates = [...new Set(data.map(r => r.date))].sort();

            let groups;
            if (this.chartGroupBy === 'model') {
                groups = [...new Set(data.map(r => r.model))].sort();
            } else {
                groups = [...new Set(data.map(r => r.provider_type))].sort();
            }

            const palette = [
                { bg: 'rgba(139, 92, 246, 0.7)', border: '#8b5cf6' },
                { bg: 'rgba(59, 130, 246, 0.7)',  border: '#3b82f6' },
                { bg: 'rgba(16, 185, 129, 0.7)',  border: '#10b981' },
                { bg: 'rgba(245, 158, 11, 0.7)',  border: '#f59e0b' },
                { bg: 'rgba(236, 72, 153, 0.7)',  border: '#ec4899' },
                { bg: 'rgba(99, 102, 241, 0.7)',  border: '#6366f1' },
                { bg: 'rgba(20, 184, 166, 0.7)',  border: '#14b8a6' },
                { bg: 'rgba(249, 115, 22, 0.7)',  border: '#f97316' },
            ];

            const isCost = this.chartMetric === 'cost';

            const datasets = groups.map((g, i) => ({
                label: this.chartGroupBy === 'model' ? g : this.providerName(g),
                data: dates.map(d => {
                    const matchField = this.chartGroupBy === 'model' ? 'model' : 'provider_type';
                    const rows = data.filter(r => r.date === d && r[matchField] === g);
                    if (isCost) return rows.reduce((s, r) => s + (Number(r.api_equiv_cost) || 0), 0);
                    return rows.reduce((s, r) => s + Number(r.input_tokens) + Number(r.output_tokens), 0);
                }),
                backgroundColor: palette[i % palette.length].bg,
                borderColor: palette[i % palette.length].border,
                borderWidth: 1,
            }));

            const self = this;
            this.chart = new Chart(canvas, {
                type: 'bar',
                data: { labels: dates.map(d => d.slice(5)), datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { color: '#9ca3af', boxWidth: 12, padding: 12, font: { size: 10 } },
                        },
                        tooltip: {
                            callbacks: {
                                label: ctx => {
                                    if (isCost) return ctx.dataset.label + ': $' + ctx.raw.toFixed(2);
                                    return ctx.dataset.label + ': ' + self.fmt(ctx.raw) + ' tokens';
                                }
                            }
                        }
                    },
                    scales: {
                        x: { stacked: true, ticks: { color: '#6b7280', font: { size: 10 } }, grid: { color: 'rgba(75,85,99,0.3)' } },
                        y: {
                            stacked: true,
                            ticks: {
                                color: '#6b7280',
                                font: { size: 10 },
                                callback: v => isCost ? '$' + self.fmt(v) : self.fmt(v),
                            },
                            grid: { color: 'rgba(75,85,99,0.3)' },
                        },
                    },
                },
            });
        },

        // ============================================================
        // Helpers
        // ============================================================

        fmt(n) {
            n = Number(n) || 0;
            if (n >= 1_000_000_000) return (n / 1_000_000_000).toFixed(1) + 'B';
            if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
            if (n >= 1_000) return (n / 1_000).toFixed(1) + 'K';
            return String(Math.round(n));
        },

        resetIn(iso) {
            if (!iso) return '';
            const ms = new Date(iso) - Date.now();
            if (ms <= 0) return 'now';
            const h = Math.floor(ms / 3600000);
            const m = Math.floor((ms % 3600000) / 60000);
            if (h >= 24) return 'in ' + Math.floor(h / 24) + 'd ' + (h % 24) + 'h';
            return 'in ' + h + 'h ' + m + 'm';
        },

        barBgColor(pct)  { return pct >= 80 ? 'bg-red-500' : pct >= 60 ? 'bg-amber-500' : 'bg-emerald-500'; },
        barTextColor(pct) { return pct >= 80 ? 'text-red-400' : pct >= 60 ? 'text-amber-400' : 'text-emerald-400'; },

        providerName(p) { return { claude_code: 'Claude Code', codex: 'Codex', cursor_agent: 'Cursor' }[p] || p; },
        providerColor(p) { return { claude_code: 'bg-purple-500', codex: 'bg-blue-500', cursor_agent: 'bg-emerald-500' }[p] || 'bg-gray-500'; },

        getStat(provider, period, key) {
            return this.summary?.by_provider?.[provider]?.[period]?.[key] || 0;
        },
    };
}
</script>
@endpush
