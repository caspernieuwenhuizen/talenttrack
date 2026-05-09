/*
 * frontend-archive-button.js (v3.110.53)
 *
 * Wires up Archive buttons on detail pages. The button carries:
 *   data-tt-archive-rest-path="players/123"
 *   data-tt-archive-confirm="Archive this player? They can be restored later."
 *   data-tt-archive-redirect="https://.../wp-admin/?tt_view=players"
 *
 * Click → confirm() → fetch DELETE /wp-json/talenttrack/v1/<rest_path>
 * with X-WP-Nonce → on success, redirect to the list URL. On failure,
 * shows the error in an alert(). Nonce + REST root come from window.TT
 * (set by public.js on every dashboard page).
 */
(function () {
    'use strict';

    var globals = window.TT || {};
    var rest_root  = globals.rest_url   || '';
    var rest_nonce = globals.rest_nonce || '';

    function init() {
        var buttons = document.querySelectorAll('[data-tt-archive-rest-path]');
        Array.prototype.forEach.call(buttons, function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                if (btn.disabled) return;

                var confirm_text = btn.getAttribute('data-tt-archive-confirm') || 'Archive this record?';
                if (!window.confirm(confirm_text)) return;

                var path     = btn.getAttribute('data-tt-archive-rest-path') || '';
                var redirect = btn.getAttribute('data-tt-archive-redirect') || '';
                if (!path || !rest_root || !rest_nonce) {
                    window.alert('Archive failed: REST configuration missing.');
                    return;
                }

                btn.disabled = true;
                fetch(rest_root + path, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-WP-Nonce': rest_nonce
                    }
                }).then(function (res) {
                    return res.json().then(function (body) {
                        return { status: res.status, body: body };
                    }).catch(function () {
                        return { status: res.status, body: null };
                    });
                }).then(function (r) {
                    var ok = r.status >= 200 && r.status < 300 && (!r.body || r.body.success !== false);
                    if (ok) {
                        if (redirect) {
                            window.location.assign(redirect);
                        } else {
                            window.location.reload();
                        }
                        return;
                    }
                    btn.disabled = false;
                    var msg = 'Archive failed.';
                    if (r.body && r.body.errors && r.body.errors[0] && r.body.errors[0].message) {
                        msg = r.body.errors[0].message;
                    }
                    window.alert(msg);
                }).catch(function () {
                    btn.disabled = false;
                    window.alert('Network error. Please try again.');
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
