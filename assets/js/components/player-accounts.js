/**
 * TalentTrack — Player accounts: link / unlink a WP user to a player (#1771)
 *
 * Calls the resource-oriented endpoints with the REST nonce:
 *   POST   /players/{id}/account  { wp_user_id }   → link
 *   DELETE /players/{id}/account                   → unlink
 *
 * Presentation only — all the rules (already-linked guard, role grant /
 * revoke) live in PlayerAccountService behind the endpoint.
 */
(function () {
    'use strict';

    var BOOT = window.TT_PLAYER_ACCOUNTS || null;

    function i18n(key, fallback) {
        return (BOOT && BOOT.i18n && BOOT.i18n[key]) || fallback;
    }

    function restBase() {
        return ((BOOT && BOOT.rest_url) || '/wp-json/talenttrack/v1/players/').replace(/\/+$/, '/');
    }

    function msgEl(playerId) {
        return document.querySelector('.tt-pa-msg[data-player-id="' + playerId + '"]');
    }

    function setMsg(playerId, text, isError) {
        var el = msgEl(playerId);
        if (!el) return;
        el.textContent = text || '';
        el.classList.remove('tt-pa-msg--error', 'tt-pa-msg--ok');
        if (text) el.classList.add(isError ? 'tt-pa-msg--error' : 'tt-pa-msg--ok');
    }

    function call(path, method, body, btn, playerId) {
        var headers = { 'Accept': 'application/json' };
        if (BOOT && BOOT.rest_nonce) headers['X-WP-Nonce'] = BOOT.rest_nonce;
        if (body) headers['Content-Type'] = 'application/json';
        if (btn) btn.disabled = true;

        return fetch(restBase() + path, {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: body ? JSON.stringify(body) : undefined
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        }).then(function (r) {
            if (r.ok && r.json && r.json.success) {
                window.location.reload();
            } else {
                var m = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message)
                    || i18n('error_generic', 'Something went wrong. Please try again.');
                setMsg(playerId, m, true);
                if (btn) btn.disabled = false;
            }
        }).catch(function () {
            setMsg(playerId, i18n('network_error', 'Network error. Please try again.'), true);
            if (btn) btn.disabled = false;
        });
    }

    function onClick(e) {
        var linkBtn = e.target.closest('.tt-pa-link-btn');
        if (linkBtn) {
            e.preventDefault();
            var pid = linkBtn.getAttribute('data-player-id');
            var sel = document.getElementById('tt-pa-user-' + pid);
            var uid = sel ? parseInt(sel.value, 10) : 0;
            if (!uid) {
                setMsg(pid, i18n('pick_user', 'Choose an account to link first.'), true);
                return;
            }
            call(encodeURIComponent(pid) + '/account', 'POST', { wp_user_id: uid }, linkBtn, pid);
            return;
        }

        var unlinkBtn = e.target.closest('.tt-pa-unlink');
        if (unlinkBtn) {
            e.preventDefault();
            var upid = unlinkBtn.getAttribute('data-player-id');
            var doIt = function () { call(encodeURIComponent(upid) + '/account', 'DELETE', null, unlinkBtn, upid); };
            var msg = i18n('confirm_unlink', 'Unlink this account from the player?');
            if (typeof window.ttConfirm === 'function') {
                window.ttConfirm({ message: msg, danger: true }).then(function (ok) { if (ok) doIt(); });
            } else if (window.confirm(msg)) {
                doIt();
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.tt-dashboard').forEach(function (root) {
            root.addEventListener('click', onClick);
        });
    });
})();
