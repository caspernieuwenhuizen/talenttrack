/**
 * TalentTrack — flash-message JS layer
 * #0019 Sprint 1 session 3
 *
 * Progressive enhancement over the server-rendered banners that
 * FlashMessages::render() outputs. With JS:
 *   - The `×` dismiss link is intercepted; we fade the banner out in
 *     place and DELETE it via POST to the dismiss URL the server
 *     already embedded. No page reload.
 *   - Success banners auto-fade after 5 seconds.
 *
 * Without JS the plain server-rendered `×` link still works — it
 * navigates to `?tt_flash_dismiss=<id>` and is cleared on the server
 * before the next render.
 */
(function(){
    'use strict';

    var AUTO_DISMISS_MS = 5000;

    function dismissFlash(flashEl) {
        if (!flashEl || flashEl.classList.contains('tt-flash-dismissing')) return;
        flashEl.classList.add('tt-flash-dismissing');
        setTimeout(function() {
            if (flashEl.parentNode) flashEl.parentNode.removeChild(flashEl);
        }, 300);
    }

    function wireFlash(flashEl) {
        // Intercept the `×` dismiss link so it doesn't reload the page.
        var link = flashEl.querySelector('a[href*="tt_flash_dismiss"]');
        if (link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                // Use fetch (no body — GET) to nudge the server to clear
                // the transient. We don't wait for it before animating.
                fetch(link.href, { credentials: 'same-origin' }).catch(function(){});
                dismissFlash(flashEl);
            });
        }
        // Auto-dismiss success banners.
        if (flashEl.classList.contains('tt-flash-success')) {
            setTimeout(function() { dismissFlash(flashEl); }, AUTO_DISMISS_MS);
        }
    }

    // #1478 — one-shot flashes queued across a navigation. The save
    // handler queues a "Saved" toast before redirecting; it's drained
    // and shown once on the destination page so the confirmation
    // survives the redirect.
    var QUEUE_KEY = 'ttFlashQueue';

    function drainFlashQueue() {
        var q;
        try {
            q = JSON.parse(sessionStorage.getItem(QUEUE_KEY) || '[]');
            sessionStorage.removeItem(QUEUE_KEY);
        } catch (e) { return; }
        if (!Array.isArray(q)) return;
        q.forEach(function(item) {
            if (item && item.message && window.ttFlash) {
                window.ttFlash.add(item.type || 'info', item.message);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard .tt-flash').forEach(wireFlash);
        drainFlashQueue();
    });

    // Expose a tiny add-flash helper so other scripts can push transient
    // banners without a page reload (e.g. the REST save handler on
    // success, in session 4+).
    window.ttFlash = {
        add: function(type, message) {
            var stack = document.querySelector('.tt-dashboard .tt-flash-stack');
            if (!stack) {
                var dashboard = document.querySelector('.tt-dashboard');
                if (!dashboard) return;
                stack = document.createElement('div');
                stack.className = 'tt-flash-stack';
                dashboard.insertBefore(stack, dashboard.children[1] || null);
            }
            var flash = document.createElement('div');
            flash.className = 'tt-flash tt-flash-' + (type || 'info');
            var dismissLabel = (window.TT && window.TT.i18n && window.TT.i18n.dismiss) || 'Dismiss';
            flash.innerHTML = '<span style="flex:1;"></span><a href="#" aria-label="' + dismissLabel.replace(/"/g, '&quot;') + '" style="color:inherit;text-decoration:none;opacity:0.7;">×</a>';
            flash.querySelector('span').textContent = message;
            flash.querySelector('a').addEventListener('click', function(e) { e.preventDefault(); dismissFlash(flash); });
            stack.appendChild(flash);
            if (type === 'success') setTimeout(function() { dismissFlash(flash); }, AUTO_DISMISS_MS);
        },

        /**
         * #1478 — queue a one-shot flash to show after the next page
         * load. Used by the save handler so the "Saved" confirmation
         * survives the post-save redirect and appears on the
         * destination page. Falls back to an in-place flash when
         * sessionStorage isn't available.
         */
        queue: function(type, message) {
            try {
                var q = JSON.parse(sessionStorage.getItem(QUEUE_KEY) || '[]');
                if (!Array.isArray(q)) q = [];
                q.push({ type: type || 'info', message: String(message || '') });
                sessionStorage.setItem(QUEUE_KEY, JSON.stringify(q));
            } catch (e) {
                window.ttFlash.add(type, message);
            }
        },

        /**
         * Push a transient toast positioned near a specific DOM anchor —
         * the action that triggered it. Falls back to ttFlash.add() if the
         * anchor is missing or detached from the document. Intended for
         * "Role revoked" / "Goal deleted" feedback right where the user
         * clicked instead of in a page-level banner.
         */
        addNear: function(anchor, type, message) {
            if (!anchor || !anchor.getBoundingClientRect) {
                window.ttFlash.add(type, message);
                return;
            }
            var rect = anchor.getBoundingClientRect();
            // If the anchor is no longer in the document (e.g. the row was
            // removed before this call), fall back to the page-level flash.
            if (rect.width === 0 && rect.height === 0) {
                window.ttFlash.add(type, message);
                return;
            }
            var toast = document.createElement('div');
            toast.className = 'tt-flash-near tt-flash-near-' + (type || 'info');
            toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
            toast.textContent = message;
            // Fixed positioning so scrolling doesn't drift the toast.
            toast.style.position = 'fixed';
            toast.style.top = Math.max(8, rect.top - 8) + 'px';
            toast.style.left = Math.min(window.innerWidth - 280, rect.right + 8) + 'px';
            toast.style.zIndex = '100001';
            document.body.appendChild(toast);
            requestAnimationFrame(function() {
                toast.classList.add('tt-flash-near-visible');
            });
            // Auto-dismiss: 4s for success/info, 6s for error.
            var ttl = type === 'error' ? 6000 : 4000;
            setTimeout(function() {
                toast.classList.remove('tt-flash-near-visible');
                setTimeout(function() {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 300);
            }, ttl);
        }
    };
})();
