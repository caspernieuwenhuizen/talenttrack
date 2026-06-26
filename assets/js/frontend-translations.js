/*
 * frontend-translations.js — FrontendTranslationsView (#1935).
 *
 * Wires the engine-config form and the "Clear cache" button on the
 * frontend Translations view to the dedicated REST surface:
 *   POST /wp-json/talenttrack/v1/translations/settings
 *   POST /wp-json/talenttrack/v1/translations/clear-cache
 *
 * The view composes the payload here; the REST controller decides
 * (validation, keep-on-blank credentials, GDPR purge live server-side).
 * Strings come from TT.i18n / the localised TT_Translations object —
 * no hard-coded English.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-tt-translations]');
    if (!root) return;

    var cfg = window.TT_Translations || {};
    var i18n = cfg.i18n || {};
    var rest = ((window.TT && window.TT.rest_url) || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');
    var nonce = (window.TT && window.TT.rest_nonce) || (window.wpApiSettings && window.wpApiSettings.nonce) || '';

    function headers() {
        var h = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
        if (nonce) h['X-WP-Nonce'] = nonce;
        return h;
    }

    function firstError(json) {
        return (json && json.errors && json.errors[0] && json.errors[0].message) || '';
    }

    // ---- Save ----------------------------------------------------------
    var form = root.querySelector('[data-tt-translations-form]');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('.tt-save-btn');
            var msg = form.querySelector('[data-tt-translations-msg]');
            if (btn) btn.setAttribute('data-state', 'saving');
            if (msg) { msg.className = 'tt-translations__form-msg'; msg.textContent = ''; }

            var fd = new FormData(form);
            var body = {
                enabled: fd.get('enabled') ? true : false,
                subprocessor_confirmed: fd.get('subprocessor_confirmed') ? true : false,
                primary_engine: String(fd.get('primary_engine') || 'deepl'),
                fallback_engine: String(fd.get('fallback_engine') || ''),
                deepl_key: String(fd.get('deepl_key') || ''),
                google_service_account: String(fd.get('google_service_account') || ''),
                site_default_lang: String(fd.get('site_default_lang') || ''),
                monthly_cap: parseInt(fd.get('monthly_cap') || '0', 10),
                threshold_pct: parseInt(fd.get('threshold_pct') || '0', 10)
            };

            fetch(rest + 'translations/settings', {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers(),
                body: JSON.stringify(body)
            })
                .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
                .then(function (r) {
                    if (r.ok && r.json && r.json.success) {
                        if (btn) btn.setAttribute('data-state', 'saved');
                        if (msg) { msg.className = 'tt-translations__form-msg tt-success'; msg.textContent = i18n.saved || 'Saved.'; }
                        // Reload so the usage table + "(set)" indicators reflect
                        // the new state without a second round-trip.
                        setTimeout(function () { window.location.reload(); }, 700);
                    } else {
                        if (btn) btn.setAttribute('data-state', 'error');
                        if (msg) { msg.className = 'tt-translations__form-msg tt-error'; msg.textContent = firstError(r.json) || i18n.error || 'Error.'; }
                        setTimeout(function () { if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
                    }
                })
                .catch(function () {
                    if (btn) btn.setAttribute('data-state', 'error');
                    if (msg) { msg.className = 'tt-translations__form-msg tt-error'; msg.textContent = i18n.network_error || 'Network error.'; }
                    setTimeout(function () { if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
                });
        });
    }

    // ---- Clear cache ---------------------------------------------------
    var clearBtn = root.querySelector('[data-tt-translations-clear]');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!window.confirm(i18n.clear_confirm || 'Clear all cached translations?')) return;
            clearBtn.disabled = true;
            var clearMsg = root.querySelector('[data-tt-translations-clear-msg]');
            if (clearMsg) { clearMsg.className = 'tt-translations__form-msg'; clearMsg.textContent = ''; }

            fetch(rest + 'translations/clear-cache', {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers(),
                body: '{}'
            })
                .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
                .then(function (r) {
                    clearBtn.disabled = false;
                    if (r.ok && r.json && r.json.success) {
                        if (clearMsg) { clearMsg.className = 'tt-translations__form-msg tt-success'; clearMsg.textContent = i18n.cache_cleared || 'Cache cleared.'; }
                        setTimeout(function () { window.location.reload(); }, 700);
                    } else if (clearMsg) {
                        clearMsg.className = 'tt-translations__form-msg tt-error';
                        clearMsg.textContent = firstError(r.json) || i18n.error || 'Error.';
                    }
                })
                .catch(function () {
                    clearBtn.disabled = false;
                    if (clearMsg) { clearMsg.className = 'tt-translations__form-msg tt-error'; clearMsg.textContent = i18n.network_error || 'Network error.'; }
                });
        });
    }
})();
