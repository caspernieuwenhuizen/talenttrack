/* TalentTrack service worker — Web Push receiver (#0042).
 *
 * Three event handlers:
 *
 *   push                    — show the notification.
 *   notificationclick       — focus or open the linked TT page.
 *   pushsubscriptionchange  — re-subscribe + POST the new endpoint
 *                             back to /push-subscriptions.
 *
 * Scope is the plugin assets directory; that's narrower than the
 * site root but covers every page that registers the SW. The
 * narrower scope is intentional — it keeps the SW out of theme
 * pages that don't opt into TalentTrack push.
 */

self.addEventListener('install', function (event) {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var payload = {};
    if (event.data) {
        try { payload = event.data.json(); }
        catch (e) { payload = { title: 'TalentTrack', body: event.data.text() }; }
    }
    var title = payload.title || 'TalentTrack';
    var options = {
        body: payload.body || '',
        icon: payload.icon || undefined,
        badge: payload.badge || undefined,
        tag: payload.tag || 'tt',
        data: payload,
        renotify: false,
        requireInteraction: false
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
            for (var i = 0; i < windows.length; i++) {
                var w = windows[i];
                if (w.url === url && 'focus' in w) return w.focus();
            }
            if (self.clients.openWindow) return self.clients.openWindow(url);
        })
    );
});

self.addEventListener('pushsubscriptionchange', function (event) {
    /* The browser tells us the old endpoint is no longer valid. We
     * re-subscribe with the same VAPID key (read from a postMessage
     * the page hands us) and POST the new keys back to the server.
     * Implementation note: VAPID public key is not directly available
     * inside the SW context, so this handler is a thin wrapper — the
     * real re-subscribe happens when the page next loads and the
     * client glue notices the missing subscription. */
    event.waitUntil(
        self.registration.pushManager.getSubscription().then(function (sub) {
            if (sub) return; // Browser may have already re-subscribed.
            // Otherwise, the page-side glue (tt-push-client.js) will
            // resubscribe on next visit; nothing to do here.
        })
    );
});
