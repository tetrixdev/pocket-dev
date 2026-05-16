/**
 * PocketDev Service Worker - Web Push Notifications
 *
 * Handles incoming push events and notification clicks.
 * Registered from the Notifications settings page.
 */

self.addEventListener('push', (event) => {
    const data = event.data?.json() ?? {};

    const options = {
        body: data.body ?? 'Task completed',
        icon: '/favicon.ico',
        badge: '/badge-72.png',
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
                    client.focus();
                    client.navigate(fullUrl);
                    return;
                }
            }
            // No existing tab found, open a new one
            return clients.openWindow(fullUrl);
        })
    );
});
