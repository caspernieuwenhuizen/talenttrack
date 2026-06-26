/*
 * frontend-spond.js — FrontendSpondView (#1936).
 *
 * Wires the credentials form, Test connection / Disconnect buttons, the
 * per-team "Refresh now" buttons, and the API base-URL override on the
 * frontend Spond view to the REST surface:
 *   POST   /wp-json/talenttrack/v1/spond/credentials
 *   DELETE /wp-json/talenttrack/v1/spond/credentials
 *   POST   /wp-json/talenttrack/v1/spond/test
 *   POST   /wp-json/talenttrack/v1/spond/base-url
 *   POST   /wp-json/talenttrack/v1/teams/{id}/spond/sync
 *
 * The view composes the payload here; the controller decides (keep-on-
 * blank password, the live login, the override write live server-side).
 * The password is sent on save/test but never read back into the DOM.
 * Strings come from the localised TT_Spond object — no hard-coded
 * English.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-tt-spond]');
    if (!root) return;

    var cfg = window.TT_Spond || {};
    var i18n = cfg.i18n || {};
    var rest = ((window.TT && window.TT.rest_url) || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');
    var nonce = (window.TT && window.TT.rest_nonce) || (window.wpApiSettings && window.wpApiSettings.nonce) || '';

    var msg = root.querySelector('[data-tt-spond-msg]');

    function headers() {
        var h = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
        if (nonce) h['X-WP-Nonce'] = nonce;
        return h;
    }

    function firstError(json) {
        return (json && json.errors && json.errors[0] && json.errors[0].message) || '';
    }

    function setMsg(text, kind) {
        if (!msg) return;
        msg.className = 'tt-spond__form-msg' + (kind ? ' tt-' + kind : '');
        msg.textContent = text || '';
    }

    function post(path, body, method) {
        return fetch(rest + path, {
            method: method || 'POST',
            credentials: 'same-origin',
            headers: headers(),
            body: JSON.stringify(body || {})
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        });
    }

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 700);
    }

    // ---- Save credentials ----------------------------------------------
    var credForm = root.querySelector('[data-tt-spond-creds-form]');
    if (credForm) {
        credForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = credForm.querySelector('.tt-save-btn');
            if (btn) btn.setAttribute('data-state', 'saving');
            setMsg('', '');

            var fd = new FormData(credForm);
            post('spond/credentials', {
                email: String(fd.get('email') || ''),
                password: String(fd.get('password') || '')
            }).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    if (btn) btn.setAttribute('data-state', 'saved');
                    setMsg(i18n.saved || 'Saved.', 'success');
                    reloadSoon();
                } else {
                    if (btn) btn.setAttribute('data-state', 'error');
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                    setTimeout(function () { if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
                }
            }).catch(function () {
                if (btn) btn.setAttribute('data-state', 'error');
                setMsg(i18n.network_error || 'Network error.', 'error');
                setTimeout(function () { if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
            });
        });
    }

    // ---- Test connection -----------------------------------------------
    var testBtn = root.querySelector('[data-tt-spond-test]');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            setMsg('', '');
            var body = {};
            if (credForm) {
                var fd = new FormData(credForm);
                body = { email: String(fd.get('email') || ''), password: String(fd.get('password') || '') };
            }
            post('spond/test', body).then(function (r) {
                testBtn.disabled = false;
                if (r.ok && r.json && r.json.success) {
                    setMsg(i18n.test_ok || 'Login successful.', 'success');
                } else {
                    setMsg(firstError(r.json) || i18n.test_failed || 'Login failed.', 'error');
                }
            }).catch(function () {
                testBtn.disabled = false;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    }

    // ---- Disconnect ----------------------------------------------------
    var disconnectBtn = root.querySelector('[data-tt-spond-disconnect]');
    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', function () {
            if (!window.confirm(i18n.disconnect_confirm || 'Disconnect Spond?')) return;
            disconnectBtn.disabled = true;
            setMsg('', '');
            post('spond/credentials', {}, 'DELETE').then(function (r) {
                disconnectBtn.disabled = false;
                if (r.ok && r.json && r.json.success) {
                    setMsg(i18n.disconnected || 'Disconnected.', 'success');
                    reloadSoon();
                } else {
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                disconnectBtn.disabled = false;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    }

    // ---- API base-URL override -----------------------------------------
    var baseForm = root.querySelector('[data-tt-spond-baseurl-form]');
    if (baseForm) {
        baseForm.addEventListener('submit', function (e) {
            e.preventDefault();
            setMsg('', '');
            var fd = new FormData(baseForm);
            post('spond/base-url', { api_base_url: String(fd.get('api_base_url') || '') }).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    setMsg(i18n.base_url_saved || 'Endpoint saved.', 'success');
                    reloadSoon();
                } else {
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    }

    // ---- Per-team Refresh now ------------------------------------------
    root.querySelectorAll('[data-tt-spond-refresh]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var teamId = parseInt(btn.getAttribute('data-team-id') || '0', 10);
            if (!teamId) return;
            var original = btn.textContent;
            btn.disabled = true;
            btn.textContent = i18n.refreshing || 'Refreshing…';
            setMsg('', '');
            post('teams/' + teamId + '/spond/sync', {}).then(function (r) {
                btn.disabled = false;
                btn.textContent = original;
                if (r.ok && r.json && r.json.success) {
                    setMsg(i18n.refreshed || 'Sync triggered.', 'success');
                    reloadSoon();
                } else {
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = original;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    });
})();
