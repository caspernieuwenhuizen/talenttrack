/**
 * match-analysis.js — the one interactive control on the match-analysis
 * surface: reissuing the staff share link.
 *
 * Everything else on the page is a plain form that public.js already
 * submits over REST, so there is nothing else to script. Reissuing needs
 * its own handler because it is destructive in a way a link is not —
 * every URL previously handed out stops working — so it confirms first and
 * reloads afterwards, which is when the new link becomes visible.
 */
(function () {
    'use strict';

    var strings = window.TT_MatchAnalysis || {};

    function restBase() {
        return (window.TT && TT.rest_url) ? TT.rest_url : '/wp-json/talenttrack/v1/';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.tt-ma__rotate') : null;
        if (!btn) return;

        e.preventDefault();

        var path = btn.getAttribute('data-rest-path');
        if (!path) return;
        if (!window.confirm(strings.confirmRotate || 'Reissue the share link?')) return;

        btn.disabled = true;

        fetch(restBase() + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': (window.TT && TT.rest_nonce) || ''
            }
        }).then(function (res) {
            return res.json();
        }).then(function (json) {
            if (json && json.success) {
                if (window.ttFlash) { window.ttFlash.queue('success', strings.rotated || 'Link reissued.'); }
                window.location.reload();
                return;
            }
            throw new Error('rotate failed');
        }).catch(function () {
            btn.disabled = false;
            if (window.ttFlash) { window.ttFlash.add('error', strings.failed || 'The link could not be reissued.'); }
        });
    });
}());
