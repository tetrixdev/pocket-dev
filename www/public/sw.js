/**
 * PocketDev Service Worker - Web Push Notifications
 *
 * Handles incoming push events and notification clicks.
 * Registered from the Notifications settings page.
 */

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data?.json() ?? {};
    } catch (e) {
        // Malformed payload, use defaults
    }

    const options = {
        body: data.body ?? 'Task completed',
        icon: '/favicon.ico',
        data: { url: data.url ?? '/' },
        tag: data.tag ?? 'pocketdev-complete',
        renotify: true,
        requireInteraction: false,
    };

    event.waitUntil(
        self.registration.showNotification(data.title ?? 'PocketDev', options)
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url ?? '/';
    const fullUrl = self.location.origin + url;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Try to focus an existing PocketDev tab
            for (const client of clientList) {
                if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                    return client.focus().then(() => {
                        if ('navigate' in client) {
                            return client.navigate(fullUrl);
                        }
                        return clients.openWindow(fullUrl);
                    });
                }
            }
            // No existing tab found, open a new one
            return clients.openWindow(fullUrl);
        })
    );
});
