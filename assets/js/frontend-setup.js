/*
 * frontend-setup.js — FrontendSetupView (#1938).
 *
 * Drives the frontend first-run onboarding flow. Each step POSTs to the
 * onboarding REST surface, then the view re-renders the next step on
 * reload:
 *   POST /wp-json/talenttrack/v1/onboarding/advance
 *   POST /wp-json/talenttrack/v1/onboarding/academy
 *   POST /wp-json/talenttrack/v1/onboarding/first-team
 *   POST /wp-json/talenttrack/v1/onboarding/first-admin
 *   POST /wp-json/talenttrack/v1/onboarding/dashboard-page
 *   POST /wp-json/talenttrack/v1/onboarding/reset
 *
 * The server owns the state machine + side effects (team / staff / page
 * creation, role grant) via OnboardingHandlers; this script only composes
 * each step's payload and reloads so OnboardingState re-renders the right
 * step. Strings come from the localised TT_Setup object — no hard-coded
 * English.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-tt-setup]');
    if (!root) return;

    var cfg = window.TT_Setup || {};
    var i18n = cfg.i18n || {};
    var rest = ((window.TT && window.TT.rest_url) || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');
    var nonce = (window.TT && window.TT.rest_nonce) || (window.wpApiSettings && window.wpApiSettings.nonce) || '';

    var msg = root.querySelector('[data-tt-setup-msg]');

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
        msg.className = 'tt-setup__form-msg' + (kind ? ' tt-' + kind : '');
        msg.textContent = text || '';
    }

    function post(path, body) {
        return fetch(rest + 'onboarding/' + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers(),
            body: JSON.stringify(body || {})
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        });
    }

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 500);
    }

    function fail(json, btn) {
        if (btn) btn.setAttribute('data-state', 'error');
        setMsg(firstError(json) || i18n.error || 'Error.', 'error');
        setTimeout(function () { if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
    }

    // ---- Step form submit (academy / first-team / first-admin) ----------
    var form = root.querySelector('[data-tt-setup-form]');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var endpoint = form.getAttribute('data-tt-setup-endpoint') || '';
            if (!endpoint) return;
            var btn = form.querySelector('.tt-save-btn');
            if (btn) btn.setAttribute('data-state', 'saving');
            setMsg('', '');

            var fd = new FormData(form);
            var body = {};
            fd.forEach(function (value, key) { body[key] = String(value); });
            // Unchecked checkbox is absent from FormData; normalise.
            if (form.querySelector('[name="grant_role"]') && !fd.get('grant_role')) {
                body.grant_role = '';
            }

            post(endpoint, body).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    if (btn) btn.setAttribute('data-state', 'saved');
                    setMsg(i18n.saved || 'Saved.', 'success');
                    reloadSoon();
                } else {
                    fail(r.json, btn);
                }
            }).catch(function () {
                if (btn) btn.setAttribute('data-state', 'error');
                setMsg(i18n.network_error || 'Network error.', 'error');
                setTimeout(function () { if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
            });
        });
    }

    // ---- Welcome → academy ----------------------------------------------
    bindButton('[data-tt-setup-advance]', function (btn) {
        btn.disabled = true;
        post('advance', {}).then(function (r) {
            if (r.ok && r.json && r.json.success) {
                reloadSoon();
            } else {
                btn.disabled = false;
                setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
            }
        }).catch(function () {
            btn.disabled = false;
            setMsg(i18n.network_error || 'Network error.', 'error');
        });
    });

    // ---- Skip a step (first-team / dashboard-page) ----------------------
    root.querySelectorAll('[data-tt-setup-skip]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var endpoint = btn.getAttribute('data-tt-setup-skip') || '';
            if (!endpoint) return;
            btn.disabled = true;
            setMsg('', '');
            post(endpoint, { skip: 1 }).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    reloadSoon();
                } else {
                    btn.disabled = false;
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                btn.disabled = false;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    });

    // ---- Create dashboard page ------------------------------------------
    bindButton('[data-tt-setup-create-page]', function (btn) {
        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = i18n.creating || 'Creating…';
        setMsg('', '');
        post('dashboard-page', {}).then(function (r) {
            if (r.ok && r.json && r.json.success) {
                reloadSoon();
            } else {
                btn.disabled = false;
                btn.textContent = original;
                setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
            }
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = original;
            setMsg(i18n.network_error || 'Network error.', 'error');
        });
    });

    // ---- Reset / Run again ----------------------------------------------
    root.querySelectorAll('[data-tt-setup-reset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.confirm(i18n.reset_confirm || 'Start over?')) return;
            btn.disabled = true;
            setMsg('', '');
            post('reset', {}).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    // Re-enter with force_welcome so a completed install
                    // lands back on the welcome step, not the summary.
                    var url = new URL(window.location.href);
                    url.searchParams.set('force_welcome', '1');
                    window.location.href = url.toString();
                } else {
                    btn.disabled = false;
                    setMsg(firstError(r.json) || i18n.error || 'Error.', 'error');
                }
            }).catch(function () {
                btn.disabled = false;
                setMsg(i18n.network_error || 'Network error.', 'error');
            });
        });
    });

    function bindButton(selector, handler) {
        var el = root.querySelector(selector);
        if (el) el.addEventListener('click', function () { handler(el); });
    }
})();
