/*
 * Persona dashboard runtime (#0060)
 *
 * Wires the role-switcher pill to POST /me/active-persona so the choice
 * persists across sessions via tt_user_meta. The legacy sessionStorage
 * lens (in DashboardShortcode's user-menu switcher) keeps working as a
 * transient overlay; the pill here writes to the durable layer.
 */
(function () {
    'use strict';

    var cfg = window.TT_PersonaDashboard || {};
    if (!cfg.rest_url || !cfg.rest_nonce) return;

    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest && ev.target.closest('[data-tt-pd-active-persona]');
        if (!btn) return;
        var persona = btn.getAttribute('data-tt-pd-active-persona');
        if (!persona) return;
        ev.preventDefault();

        fetch(cfg.rest_url + 'me/active-persona', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.rest_nonce
            },
            body: JSON.stringify({ persona: persona })
        }).then(function (res) {
            if (res.ok) {
                window.location.reload();
            }
        }).catch(function () {
            // Fail silent — user can re-click; legacy sessionStorage path
            // is still available via the user-menu switcher in the header.
        });
    });
})();
