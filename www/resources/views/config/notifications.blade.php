@extends('layouts.config')

@section('title', 'Notifications')

@push('scripts')
<script src="/webpush.js"></script>
@endpush

@section('content')
<div x-data="notificationSettings()" x-init="init()" class="space-y-8">

    {{-- Browser Support Check --}}
    <template x-if="!supported">
        <div class="bg-red-900/40 border border-red-700 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <i class="fa-solid fa-triangle-exclamation text-red-400 mt-0.5"></i>
                <div>
                    <p class="text-red-200 font-medium text-sm">Push notifications not supported</p>
                    <p class="text-red-300 text-xs mt-1">This browser does not support the Web Push API. Use Chrome, Edge, or Firefox.</p>
                </div>
            </div>
        </div>
    </template>

    {{-- Permission Denied Warning --}}
    <template x-if="supported && permission === 'denied'">
        <div class="bg-amber-900/40 border border-amber-700 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <i class="fa-solid fa-bell-slash text-amber-400 mt-0.5"></i>
                <div>
                    <p class="text-amber-200 font-medium text-sm">Notifications blocked</p>
                    <p class="text-amber-300 text-xs mt-1">Click the lock icon in your browser's address bar to allow notifications.</p>
                </div>
            </div>
        </div>
    </template>

    {{-- This Browser --}}
    <template x-if="supported && permission !== 'denied'">
        <section>
            <h2 class="text-lg font-semibold mb-4 text-gray-200">This browser</h2>
            <div class="bg-gray-800 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-medium text-white" x-text="browserName"></h3>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="w-1.5 h-1.5 rounded-full" :class="subscribed ? 'bg-green-400' : 'bg-gray-500'"></span>
                            <span class="text-sm" :class="subscribed ? 'text-green-400' : 'text-gray-500'" x-text="subscribed ? 'Active' : 'Inactive'"></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <template x-if="subscribed">
                            <button
                                @click="doTest()"
                                :disabled="testing"
                                class="px-3 py-1.5 text-sm text-gray-300 hover:text-white border border-gray-600 hover:border-gray-500 rounded transition-colors disabled:opacity-50"
                            >
                                <span x-text="testing ? 'Sending...' : 'Test'"></span>
                            </button>
                        </template>
                        <button
                            @click="subscribed ? doUnsubscribe() : doSubscribe()"
                            :disabled="loading"
                            class="px-3 py-1.5 rounded text-sm font-medium transition-colors disabled:opacity-50"
                            :class="subscribed
                                ? 'text-gray-300 hover:text-white border border-gray-600 hover:border-red-500 hover:text-red-400'
                                : 'bg-blue-600 hover:bg-blue-700 text-white'"
                        >
                            <template x-if="loading">
                                <span class="flex items-center gap-2">
                                    <svg class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none">
                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                    </svg>
                                    <span>...</span>
                                </span>
                            </template>
                            <template x-if="!loading">
                                <span x-text="subscribed ? 'Disable' : 'Enable'"></span>
                            </template>
                        </button>
                    </div>
                </div>

                {{-- Error / Success messages --}}
                <template x-if="error">
                    <div class="mt-3 bg-red-900/30 border border-red-800 rounded p-2.5 text-xs text-red-300">
                        <span x-text="error"></span>
                    </div>
                </template>
                <template x-if="success">
                    <div class="mt-3 bg-green-900/30 border border-green-800 rounded p-2.5 text-xs text-green-300">
                        <span x-text="success"></span>
                    </div>
                </template>
            </div>
        </section>
    </template>

    {{-- Registered Devices --}}
    <section>
        <h2 class="text-lg font-semibold mb-4 text-gray-200">Registered devices</h2>
        <p class="text-sm text-gray-400 mb-4">All browsers and devices that will receive notifications.</p>

        <template x-if="devicesLoading">
            <div class="bg-gray-800 rounded-lg p-4">
                <div class="flex items-center gap-2 text-gray-400 text-sm">
                    <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                    Loading...
                </div>
            </div>
        </template>

        <template x-if="!devicesLoading && devices.length === 0">
            <div class="bg-gray-800 rounded-lg p-4">
                <p class="text-gray-500 text-sm">No devices registered yet.</p>
            </div>
        </template>

        <template x-if="!devicesLoading && devices.length > 0">
            <div class="space-y-2">
                <template x-for="device in devices" :key="device.id">
                    <div class="bg-gray-800 rounded-lg p-4 flex items-center justify-between">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-medium text-white text-sm" x-text="formatUserAgent(device.user_agent)"></h3>
                            <p class="text-xs text-gray-500 mt-0.5">
                                Registered <span x-text="formatDate(device.created_at)"></span>
                            </p>
                        </div>
                        <button
                            @click="removeDevice(device.id)"
                            class="ml-4 text-gray-500 hover:text-red-400 transition-colors p-1.5"
                            title="Remove device"
                        >
                            <i class="fa-solid fa-trash-can text-xs"></i>
                        </button>
                    </div>
                </template>
            </div>
        </template>
    </section>

    {{-- When to Notify --}}
    <section>
        <h2 class="text-lg font-semibold mb-4 text-gray-200">When to notify</h2>
        <p class="text-sm text-gray-400 mb-4">Choose which events trigger a push notification.</p>

        <div class="space-y-2">
            <div class="bg-gray-800 rounded-lg p-4">
                <label class="flex items-center justify-between cursor-pointer">
                    <div>
                        <span class="text-sm font-medium text-white">Agent completed</span>
                        <p class="text-xs text-gray-400 mt-0.5">When a conversation finishes processing</p>
                    </div>
                    <input type="checkbox" x-model="settings.notify_on_complete" @change="saveSettings()"
                           class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500 focus:ring-offset-0">
                </label>
            </div>

            <div class="bg-gray-800 rounded-lg p-4">
                <label class="flex items-center justify-between cursor-pointer">
                    <div>
                        <span class="text-sm font-medium text-white">Agent failed</span>
                        <p class="text-xs text-gray-400 mt-0.5">When a conversation fails or errors out</p>
                    </div>
                    <input type="checkbox" x-model="settings.notify_on_failure" @change="saveSettings()"
                           class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500 focus:ring-offset-0">
                </label>
            </div>

            <div class="bg-gray-800 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <label for="min_duration" class="text-sm font-medium text-white">Minimum duration</label>
                        <p class="text-xs text-gray-400 mt-0.5">Only notify if the task took longer than this (seconds). Set to 0 to always notify.</p>
                    </div>
                    <input id="min_duration" type="number" min="0" step="1" x-model.number="settings.min_duration_seconds" @change="saveSettings()"
                           class="w-20 px-3 py-1.5 bg-gray-700 border border-gray-600 rounded text-sm text-white text-center focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section>
        <h2 class="text-sm font-medium mb-2 text-gray-400">How it works</h2>
        <ul class="text-xs text-gray-500 space-y-1 list-disc list-inside">
            <li>Uses the Web Push API, no app installation needed</li>
            <li>Works on Chrome, Edge, and Firefox (desktop and Android)</li>
            <li>Each browser subscribes independently</li>
            <li>Notifications are sent even when the browser tab is closed</li>
            <li>iOS Safari is not supported (requires installed PWA)</li>
        </ul>
    </section>
</div>

<script>
function notificationSettings() {
    return {
        supported: false,
        permission: 'default',
        subscribed: false,
        loading: false,
        testing: false,
        error: null,
        success: null,
        devices: [],
        devicesLoading: true,
        browserName: '',
        settings: {
            notify_on_complete: true,
            notify_on_failure: true,
            min_duration_seconds: 5,
        },

        async init() {
            this.supported = window.PocketDevPush?.isSupported() ?? false;
            this.permission = window.PocketDevPush?.getPermissionStatus() ?? 'unsupported';
            this.browserName = this.detectBrowser();

            if (this.supported) {
                const browserStatus = await window.PocketDevPush.getSubscriptionStatus();

                if (browserStatus.subscribed) {
                    const serverResponse = await fetch('/api/push/subscriptions', { credentials: 'same-origin' });
                    const serverData = await serverResponse.json();
                    const serverEndpoints = (serverData.subscriptions || []).map(s => s.endpoint);
                    const serverKnows = serverEndpoints.includes(browserStatus.endpoint);

                    if (serverKnows) {
                        this.subscribed = true;
                    } else {
                        try {
                            await window.PocketDevPush.subscribe();
                            this.subscribed = true;
                        } catch (e) {
                            this.subscribed = false;
                            this.error = 'Browser subscription exists but server sync failed: ' + e.message;
                        }
                    }
                } else {
                    this.subscribed = false;
                }
            }

            this.loadDevices();
            this.loadSettings();
        },

        async doSubscribe() {
            this.loading = true;
            this.error = null;
            this.success = null;
            try {
                await window.PocketDevPush.subscribe();
                this.subscribed = true;
                this.permission = Notification.permission;
                this.success = 'Notifications enabled for this browser';
                this.loadDevices();
            } catch (e) {
                this.error = e.message;
                this.permission = Notification.permission;
            } finally {
                this.loading = false;
            }
        },

        async doUnsubscribe() {
            this.loading = true;
            this.error = null;
            this.success = null;
            try {
                await window.PocketDevPush.unsubscribe();
                this.subscribed = false;
                this.success = 'Notifications disabled for this browser';
                this.loadDevices();
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async doTest() {
            this.testing = true;
            this.error = null;
            this.success = null;
            try {
                const result = await window.PocketDevPush.sendTest();
                if (result.error) {
                    this.error = result.error;
                } else {
                    this.success = result.message || 'Test notification sent';
                }
            } catch (e) {
                this.error = e.message;
            } finally {
                this.testing = false;
            }
        },

        async loadDevices() {
            this.devicesLoading = true;
            try {
                const response = await fetch('/api/push/subscriptions', { credentials: 'same-origin' });
                const data = await response.json();
                this.devices = data.subscriptions || [];
            } catch (e) {
                this.devices = [];
            } finally {
                this.devicesLoading = false;
            }
        },

        async removeDevice(id) {
            try {
                const response = await fetch(`/api/push/subscriptions/${id}`, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                });

                if (!response.ok) {
                    this.error = 'Failed to remove device';
                    return;
                }

                this.devices = this.devices.filter(d => d.id !== id);

                if (this.supported) {
                    const status = await window.PocketDevPush.getSubscriptionStatus();
                    this.subscribed = status.subscribed;
                }
            } catch (e) {
                this.error = 'Failed to remove device';
            }
        },

        async loadSettings() {
            try {
                const response = await fetch('/api/push/settings', { credentials: 'same-origin' });
                if (response.ok) {
                    const data = await response.json();
                    this.settings = { ...this.settings, ...data };
                }
            } catch (e) { /* Use defaults */ }
        },

        async saveSettings() {
            try {
                const response = await fetch('/api/push/settings', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify(this.settings),
                });
                if (!response.ok) {
                    this.error = 'Failed to save settings';
                }
            } catch (e) {
                this.error = 'Failed to save settings';
            }
        },

        detectBrowser() {
            const ua = navigator.userAgent;
            if (ua.includes('Edg/')) return 'Microsoft Edge';
            if (ua.includes('Chrome/') && !ua.includes('Edg/')) return 'Google Chrome';
            if (ua.includes('Firefox/')) return 'Mozilla Firefox';
            if (ua.includes('Safari/') && !ua.includes('Chrome/')) return 'Safari (limited support)';
            return 'Unknown browser';
        },

        formatUserAgent(ua) {
            if (!ua) return 'Unknown device';
            if (ua.includes('Edg/')) return 'Edge — ' + this.extractOS(ua);
            if (ua.includes('Chrome/') && !ua.includes('Edg/')) return 'Chrome — ' + this.extractOS(ua);
            if (ua.includes('Firefox/')) return 'Firefox — ' + this.extractOS(ua);
            return ua.substring(0, 60);
        },

        extractOS(ua) {
            if (ua.includes('Android')) return 'Android';
            if (ua.includes('Windows')) return 'Windows';
            if (ua.includes('Mac OS')) return 'macOS';
            if (ua.includes('Linux')) return 'Linux';
            if (ua.includes('iPhone') || ua.includes('iPad')) return 'iOS';
            return 'Unknown';
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const now = new Date();
            const diffMs = now - date;
            const diffDays = Math.floor(diffMs / 86400000);
            if (diffDays === 0) return 'today';
            if (diffDays === 1) return 'yesterday';
            if (diffDays < 30) return `${diffDays} days ago`;
            return date.toLocaleDateString();
        },
    };
}
</script>
@endsection
