/**
 * frontend-alerts.js (#3034) — the alert banner's "Not today" control.
 *
 * The snooze endpoint and its repository side have been in place since
 * #2632; nothing on the banner was wired to them. This is the wiring and
 * nothing else: no state of its own, no polling, no re-render. The row is
 * removed on success, and the bar with it once the last row goes, because a
 * banner region with nothing in it still occupies a margin.
 *
 * Snoozing is not muting. The occurrence stays in the table and keeps being
 * reconciled, so it returns when the snooze lapses if the condition is still
 * true — which is what makes offering a one-day snooze safe.
 */
(function () {
    'use strict';

    // Same base + nonce convention as public.js. rest_url's shape differs
    // between pretty and plain permalinks, so build on what the server
    // handed us rather than assuming a path.
    function restUrl(path) {
        var base = (window.TT && TT.rest_url) ? TT.rest_url : '/wp-json/talenttrack/v1/';
        return base.replace(/\/+$/, '/') + path.replace(/^\/+/, '');
    }

    function removeRow(btn) {
        var row = btn.closest('.tt-alert');
        var bar = btn.closest('.tt-alert-bar');
        if (row) row.remove();
        if (bar && !bar.querySelector('.tt-alert')) bar.remove();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-tt-alert-snooze]');
        if (!btn || btn.getAttribute('aria-busy') === 'true') return;

        var uuid = btn.getAttribute('data-tt-alert-snooze');
        if (!uuid) return;

        e.preventDefault();
        btn.setAttribute('aria-busy', 'true');

        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (window.TT && TT.rest_nonce) headers['X-WP-Nonce'] = TT.rest_nonce;

        fetch(restUrl('alerts/' + encodeURIComponent(uuid) + '/snooze'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify({ duration: btn.getAttribute('data-tt-alert-duration') || 'day' })
        }).then(function (res) {
            if (!res.ok) throw new Error('snooze failed');
            removeRow(btn);
        }).catch(function () {
            // Leave the row in place. A row that stays put after a failed
            // click is honest; one that disappears and returns on the next
            // page load looks like the alert re-fired.
            btn.removeAttribute('aria-busy');
        });
    });
})();
