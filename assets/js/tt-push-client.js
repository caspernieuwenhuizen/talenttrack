/* TalentTrack push client glue (#0042).
 *
 * Wired by PushModule via wp_localize_script — the global TT_PUSH
 * carries:
 *   - vapidPublic  base64url-encoded P-256 public key
 *   - swUrl        absolute URL of the service worker file
 *   - restUrl      /wp-json/talenttrack/v1/push-subscriptions
 *   - nonce        X-WP-Nonce for the REST call
 *
 * Behaviour:
 *   1. Register the SW (if not already), scope = same path as swUrl.
 *   2. Read existing PushSubscription. If present, POST it back to
 *      keep `last_seen_at` fresh.
 *   3. Expose window.TT.push.subscribe() — called by the onboarding
 *      banner's "Enable notifications" button (Sprint 4). We never
 *      auto-prompt for notification permission — Safari requires
 *      permission to come from a user gesture, and Chrome's user-
 *      facing UX guidelines flag auto-prompt as a dark pattern.
 */
(function () {
    if (typeof TT_PUSH !== 'object' || !TT_PUSH || !TT_PUSH.vapidPublic || !TT_PUSH.swUrl) return;
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

    function urlBase64ToUint8Array(b64) {
        var padded = b64.replace(/-/g, '+').replace(/_/g, '/');
        var pad = padded.length % 4;
        if (pad) padded += '='.repeat(4 - pad);
        var raw = atob(padded);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function postSubscription(sub) {
        if (!sub) return Promise.resolve();
        var json = sub.toJSON();
        return fetch(TT_PUSH.restUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': TT_PUSH.nonce
            },
            body: JSON.stringify({
                endpoint: json.endpoint,
                keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
                user_agent: navigator.userAgent
            })
        });
    }

    function ensureRegistration() {
        return navigator.serviceWorker.getRegistration(TT_PUSH.swUrl).then(function (reg) {
            if (reg) return reg;
            return navigator.serviceWorker.register(TT_PUSH.swUrl);
        });
    }

    function registerExistingSubscriptionIfAny() {
        return ensureRegistration().then(function (reg) {
            return reg.pushManager.getSubscription().then(function (sub) {
                if (sub) return postSubscription(sub);
            });
        }).catch(function (e) {
            if (window.console && console.warn) console.warn('TT push register skip:', e);
        });
    }

    function subscribeNow() {
        return ensureRegistration().then(function (reg) {
            return reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(TT_PUSH.vapidPublic)
            });
        }).then(function (sub) {
            return postSubscription(sub).then(function () { return sub; });
        });
    }

    function unsubscribeNow() {
        return ensureRegistration().then(function (reg) {
            return reg.pushManager.getSubscription().then(function (sub) {
                if (!sub) return false;
                var endpoint = sub.endpoint;
                return sub.unsubscribe().then(function () {
                    /* Server-side delete by endpoint isn't an exposed
                     * route — the daily prune handles cleanup. The
                     * browser-side unsubscribe is what matters here. */
                    return endpoint;
                });
            });
        });
    }

    window.TT = window.TT || {};
    window.TT.push = {
        subscribe: subscribeNow,
        unsubscribe: unsubscribeNow,
        permission: function () { return (window.Notification && Notification.permission) || 'default'; }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerExistingSubscriptionIfAny);
    } else {
        registerExistingSubscriptionIfAny();
    }
})();
