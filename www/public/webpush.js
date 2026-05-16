/**
 * PocketDev Web Push - Registration & Subscription Management
 *
 * Exposes window.PocketDevPush with methods for use in the Notifications settings page.
 * Does NOT auto-subscribe — the user must explicitly enable notifications from Settings.
 */

const PocketDevPush = {
    /**
     * Check if Web Push is supported in this browser.
     */
    isSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    },

    /**
     * Get current notification permission status.
     * Returns: 'granted', 'denied', or 'default'
     */
    getPermissionStatus() {
        if (!this.isSupported()) return 'unsupported';
        return Notification.permission;
    },

    /**
     * Check if service worker is registered and has an active push subscription.
     */
    async getSubscriptionStatus() {
        if (!this.isSupported()) {
            return { subscribed: false, reason: 'unsupported' };
        }

        try {
            const registration = await navigator.serviceWorker.getRegistration('/sw.js');
            if (!registration) {
                return { subscribed: false, reason: 'no-sw' };
            }

            const subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                return { subscribed: false, reason: 'no-subscription' };
            }

            return { subscribed: true, endpoint: subscription.endpoint };
        } catch (e) {
            return { subscribed: false, reason: 'error', error: e.message };
        }
    },

    /**
     * Register service worker, request permission, and subscribe to push.
     * Returns the subscription object or throws an error.
     */
    async subscribe() {
        if (!this.isSupported()) {
            throw new Error('Push notifications are not supported in this browser');
        }

        // Register service worker and wait for it to be active
        await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        const registration = await navigator.serviceWorker.ready;

        // Request permission
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new Error(`Notification permission ${permission}`);
        }

        // Get VAPID public key from server
        const keyResponse = await fetch('/api/push/vapid-key', {
            credentials: 'same-origin',
        });

        if (!keyResponse.ok) {
            const err = await keyResponse.json().catch(() => ({}));
            throw new Error(err.error || 'Failed to fetch VAPID key');
        }

        const { public_key } = await keyResponse.json();

        // Convert VAPID key to Uint8Array
        const applicationServerKey = this._urlBase64ToUint8Array(public_key);

        // Subscribe to push
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey,
        });

        // Send subscription to backend
        const subJson = subscription.toJSON();
        const saveResponse = await fetch('/api/push/subscribe', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                endpoint: subJson.endpoint,
                public_key: subJson.keys.p256dh,
                auth_token: subJson.keys.auth,
            }),
        });

        if (!saveResponse.ok) {
            throw new Error('Failed to save subscription on server');
        }

        return await saveResponse.json();
    },

    /**
     * Unsubscribe from push notifications (this browser only).
     */
    async unsubscribe() {
        const registration = await navigator.serviceWorker.getRegistration('/sw.js');
        if (!registration) return false;

        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) return false;

        // Unsubscribe from browser
        await subscription.unsubscribe();

        // Remove from backend
        const response = await fetch('/api/push/unsubscribe', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        });

        if (!response.ok) {
            throw new Error('Failed to remove subscription on server');
        }

        return true;
    },

    /**
     * Send a test notification.
     */
    async sendTest() {
        const response = await fetch('/api/push/test', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        });

        if (!response.ok) {
            throw new Error(`Server responded with ${response.status}`);
        }

        return await response.json();
    },

    /**
     * Helper: Convert URL-safe base64 string to Uint8Array.
     */
    _urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    },
};

// Expose globally for use in Alpine.js components
window.PocketDevPush = PocketDevPush;
