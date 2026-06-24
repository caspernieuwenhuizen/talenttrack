/**
 * TalentTrack — scouting-visit archive action (#1764)
 *
 * Wires the "Archive visit" page-action button on the scouting-visit
 * detail view to the existing DELETE /scouting-visits/{id} endpoint
 * (soft-delete / archive). Confirms first, sends the nonce, then
 * returns the user to the scouting-visits list with a success notice.
 *
 * The REST route already enforces the capability + row-ownership check;
 * this is presentation only — no business logic.
 */
(function () {
    'use strict';

    var BOOT = window.TT_SCOUTING_VISIT_ARCHIVE || null;

    function i18n(key, fallback) {
        return (BOOT && BOOT.i18n && BOOT.i18n[key]) || fallback;
    }

    function archive(id, btn) {
        var rest = (BOOT && BOOT.rest_url) || '/wp-json/talenttrack/v1/scouting-visits/';
        var headers = { 'Accept': 'application/json' };
        if (BOOT && BOOT.rest_nonce) headers['X-WP-Nonce'] = BOOT.rest_nonce;
        if (btn) btn.disabled = true;

        fetch(rest.replace(/\/+$/, '/') + encodeURIComponent(id), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: headers
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        }).then(function (r) {
            if (r.ok && r.json && r.json.success) {
                window.location.href = (BOOT && BOOT.redirect_url) || window.location.href;
            } else {
                var msg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message)
                    || i18n('error_generic', 'Could not archive the visit. Please try again.');
                window.alert(msg);
                if (btn) btn.disabled = false;
            }
        }).catch(function () {
            window.alert(i18n('network_error', 'Network error. Please try again.'));
            if (btn) btn.disabled = false;
        });
    }

    function onClick(e) {
        var btn = e.target.closest('[data-tt-archive-visit]');
        if (!btn) return;
        e.preventDefault();
        var id = btn.getAttribute('data-tt-archive-visit');
        if (!id) return;

        var msg = i18n('confirm', 'Archive this scouting visit? It will be removed from the list.');
        if (typeof window.ttConfirm === 'function') {
            window.ttConfirm({ message: msg, danger: true }).then(function (ok) {
                if (ok) archive(id, btn);
            });
        } else if (window.confirm(msg)) {
            archive(id, btn);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.tt-dashboard').forEach(function (root) {
            root.addEventListener('click', onClick);
        });
    });
})();
