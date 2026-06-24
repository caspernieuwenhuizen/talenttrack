/**
 * TalentTrack — Parent accounts: link / unlink a parent WP account on a
 * player (#1815).
 *
 * Calls the resource-oriented endpoints with the REST nonce:
 *   POST   /players/{id}/parents  { wp_user_id }      → link parent
 *   DELETE /players/{id}/parents/{parent_user_id}     → unlink parent
 *
 * Presentation only — the rules (no double-bind, role grant/revoke) live in
 * ParentAccountService behind the endpoint.
 */
(function () {
    'use strict';

    var BOOT = window.TT_PARENT_ACCOUNTS || null;

    function i18n(key, fallback) {
        return (BOOT && BOOT.i18n && BOOT.i18n[key]) || fallback;
    }

    function restBase() {
        return ((BOOT && BOOT.rest_url) || '/wp-json/talenttrack/v1/players/').replace(/\/+$/, '/');
    }

    function setMsg(el, text, isError) {
        if (!el) return;
        el.textContent = text || '';
        el.classList.remove('tt-pa-msg--error', 'tt-pa-msg--ok');
        if (text) el.classList.add(isError ? 'tt-pa-msg--error' : 'tt-pa-msg--ok');
    }

    function call(path, method, body, btn, msgEl) {
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
                setMsg(msgEl, m, true);
                if (btn) btn.disabled = false;
            }
        }).catch(function () {
            setMsg(msgEl, i18n('network_error', 'Network error. Please try again.'), true);
            if (btn) btn.disabled = false;
        });
    }

    function onClick(e) {
        var linkBtn = e.target.closest('.tt-pp-link-btn');
        if (linkBtn) {
            e.preventDefault();
            var playerSel = document.getElementById('tt-pp-player');
            var userSel = document.getElementById('tt-pp-user');
            var pid = playerSel ? parseInt(playerSel.value, 10) : 0;
            var uid = userSel ? parseInt(userSel.value, 10) : 0;
            var addMsg = document.querySelector('[data-tt-parent-add-msg]');
            if (!pid) { setMsg(addMsg, i18n('pick_player', 'Choose a player first.'), true); return; }
            if (!uid) { setMsg(addMsg, i18n('pick_user', 'Choose an account to link first.'), true); return; }
            call(encodeURIComponent(pid) + '/parents', 'POST', { wp_user_id: uid }, linkBtn, addMsg);
            return;
        }

        var unlinkBtn = e.target.closest('.tt-pp-chip-unlink');
        if (unlinkBtn) {
            e.preventDefault();
            var upid = unlinkBtn.getAttribute('data-player-id');
            var uparent = unlinkBtn.getAttribute('data-parent-id');
            var rowMsg = document.querySelector('.tt-pa-msg[data-parent-id="' + uparent + '"]');
            var doIt = function () {
                call(encodeURIComponent(upid) + '/parents/' + encodeURIComponent(uparent), 'DELETE', null, unlinkBtn, rowMsg);
            };
            var msg = i18n('confirm_unlink', 'Unlink this parent from the player?');
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
