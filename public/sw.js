// Ghes service worker — handles incoming web push notifications.
self.addEventListener('push', function (event) {
    if (!event.data) {
        return;
    }

    let payload = {};
    try {
        payload = event.data.json();
    } catch (e) {
        payload = { title: 'Ghes', body: event.data.text() };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'Ghes', {
            body: payload.body || '',
            icon: '/images/logo-dark.png',
            badge: '/images/logo-dark.png',
            data: { url: payload.url || '/dashboard' },
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    event.waitUntil(clients.openWindow(event.notification.data.url || '/dashboard'));
});
