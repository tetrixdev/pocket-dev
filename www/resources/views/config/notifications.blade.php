@extends('layouts.config')

@section('title', 'Notifications')

@push('scripts')
<script src="/webpush.js"></script>
@endpush

@section('content')
<div x-data="notificationSettings()" x-init="init()" class="space-y-6 max-w-3xl">
    {{-- Header --}}
    <div>
        <h1 class="text-2xl font-bold text-white mb-1">Notifications</h1>
        <p class="text-gray-400 text-sm">Get notified when an agent completes a task, even with the browser closed.</p>
    </div>

    {{-- Browser Support Check --}}
    <template x-if="!supported">
        <div class="bg-red-900/40 border border-red-700 rounded-lg p-4">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-triangle-exclamation text-red-400"></i>
                <div>
                    <p class="text-red-200 font-medium">Push notifications not supported</p>
                    <p class="text-red-300 text-sm mt-1">This browser does not support the Web Push API. Use Chrome, Edge, or Firefox for push notification support.</p>
                </div>
            </div>
        </div>
    </template>

    {{-- Permission Denied Warning --}}
    <template x-if="supported && permission === 'denied'">
        <div class="bg-amber-900/40 border border-amber-700 rounded-lg p-4">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-bell-slash text-amber-400"></i>
                <div>
                    <p class="text-amber-200 font-medium">Notifications blocked</p>
                    <p class="text-amber-300 text-sm mt-1">You previously blocked notifications for this site. To enable them, click the lock icon in your browser's address bar and allow notifications.</p>
                </div>
            </div>
        </div>
    </template>

    {{-- Main Section: Enable/Disable --}}
    <template x-if="supported && permission !== 'denied'">
        <section class="bg-gray-800 rounded-lg p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-white">This browser</h2>
                    <p class="text-gray-400 text-sm mt-0.5" x-text="browserName"></p>
                </div>

                {{-- Subscribe/Unsubscribe Button --}}
                <button
                    @click="subscribed ? doUnsubscribe() : doSubscribe()"
                    :disabled="loading"
                    class="px-4 py-2 rounded text-sm font-medium transition-colors disabled:opacity-50"
                    :class="subscribed
                        ? 'bg-red-600 hover:bg-red-700 text-white'
                        : 'bg-blue-600 hover:bg-blue-700 text-white'"
                >
                    <template x-if="loading">
                        <span class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                            <span x-text="subscribed ? 'Disabling...' : 'Enabling...'"></span>
                        </span>
                    </template>
                    <template x-if="!loading">
                        <span x-text="subscribed ? 'Disable notifications' : 'Enable notifications'"></span>
                    </template>
                </button>
            </div>

            {{-- Status indicator --}}
            <div class="flex items-center gap-2 text-sm">
                <span class="w-2 h-2 rounded-full" :class="subscribed ? 'bg-green-500' : 'bg-gray-500'"></span>
                <span :class="subscribed ? 'text-green-300' : 'text-gray-400'" x-text="subscribed ? 'Active — you will receive push notifications' : 'Inactive — no notifications from this browser'"></span>
            </div>

            {{-- Test button --}}
            <template x-if="subscribed">
                <button
                    @click="doTest()"
                    :disabled="testing"
                    class="text-sm text-blue-400 hover:text-blue-300 underline underline-offset-2 disabled:opacity-50"
                >
                    <span x-text="testing ? 'Sending...' : 'Send test notification'"></span>
                </button>
            </template>

            {{-- Error display --}}
            <template x-if="error">
                <div class="bg-red-900/30 border border-red-800 rounded p-3 text-sm text-red-300">
                    <span x-text="error"></span>
                </div>
            </template>

            {{-- Success display --}}
            <template x-if="success">
                <div class="bg-green-900/30 border border-green-800 rounded p-3 text-sm text-green-300">
                    <span x-text="success"></span>
                </div>
            </template>
        </section>
    </template>

    {{-- Registered Devices --}}
    <section class="bg-gray-800 rounded-lg p-5 space-y-4">
        <h2 class="text-lg font-semibold text-white">Registered devices</h2>
        <p class="text-gray-400 text-sm">All browsers and devices that will receive notifications.</p>

        <template x-if="devicesLoading">
            <div class="flex items-center gap-2 text-gray-400 text-sm py-4">
                <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                </svg>
                Loading...
            </div>
        </template>

        <template x-if="!devicesLoading && devices.length === 0">
            <p class="text-gray-500 text-sm py-4">No devices registered yet.</p>
        </template>

        <template x-if="!devicesLoading && devices.length > 0">
            <div class="divide-y divide-gray-700">
                <template x-for="device in devices" :key="device.id">
                    <div class="flex items-center justify-between py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-white truncate" x-text="formatUserAgent(device.user_agent)"></p>
                            <p class="text-xs text-gray-500 mt-0.5">
                                Registered <span x-text="formatDate(device.created_at)"></span>
                            </p>
                        </div>
                        <button
                            @click="removeDevice(device.id)"
                            class="ml-3 text-gray-400 hover:text-red-400 transition-colors p-1"
                            title="Remove device"
                        >
                            <i class="fa-solid fa-trash-can text-sm"></i>
                        </button>
                    </div>
                </template>
            </div>
        </template>
    </section>

    {{-- Notification Events Configuration --}}
    <section class="bg-gray-800 rounded-lg p-5 space-y-4">
        <h2 class="text-lg font-semibold text-white">When to notify</h2>
        <p class="text-gray-400 text-sm">Choose which events trigger a push notification.</p>

        <div class="space-y-3">
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" x-model="settings.notify_on_complete" @change="saveSettings()"
                       class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500">
                <div>
                    <span class="text-sm text-white">Agent completed</span>
                    <p class="text-xs text-gray-500">When a conversation finishes processing</p>
                </div>
            </label>

            <label class="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" x-model="settings.notify_on_failure" @change="saveSettings()"
                       class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500">
                <div>
                    <span class="text-sm text-white">Agent failed</span>
                    <p class="text-xs text-gray-500">When a conversation fails or errors out</p>
                </div>
            </label>

            <div class="pt-2">
                <label class="block text-sm text-gray-300 mb-1">Minimum duration (seconds)</label>
                <p class="text-xs text-gray-500 mb-2">Only notify if the task took longer than this. Set to 0 to always notify.</p>
                <input type="number" min="0" step="1" x-model.number="settings.min_duration_seconds" @change="saveSettings()"
                       class="w-24 px-3 py-1.5 bg-gray-700 border border-gray-600 rounded text-sm text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section class="bg-gray-800/50 rounded-lg p-5">
        <h3 class="text-sm font-medium text-gray-300 mb-2">How it works</h3>
        <ul class="text-xs text-gray-500 space-y-1 list-disc list-inside">
            <li>Uses the Web Push API — no app installation needed</li>
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
            // Check browser support
            this.supported = window.PocketDevPush?.isSupported() ?? false;
            this.permission = window.PocketDevPush?.getPermissionStatus() ?? 'unsupported';
            this.browserName = this.detectBrowser();

            // Check current subscription status
            if (this.supported) {
                const status = await window.PocketDevPush.getSubscriptionStatus();
                this.subscribed = status.subscribed;
            }

            // Load devices list
            this.loadDevices();

            // Load notification settings
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

                // Re-check if current browser is still subscribed
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
            } catch (e) {
                // Use defaults
            }
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
