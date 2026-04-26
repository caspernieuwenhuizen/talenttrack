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

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard .tt-flash').forEach(wireFlash);
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
            flash.innerHTML = '<span style="flex:1;"></span><a href="#" aria-label="Dismiss" style="color:inherit;text-decoration:none;opacity:0.7;">×</a>';
            flash.querySelector('span').textContent = message;
            flash.querySelector('a').addEventListener('click', function(e) { e.preventDefault(); dismissFlash(flash); });
            stack.appendChild(flash);
            if (type === 'success') setTimeout(function() { dismissFlash(flash); }, AUTO_DISMISS_MS);
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
