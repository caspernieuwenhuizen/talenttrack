/*
 * TalentTrack — team detail "Customize" panel (#1613)
 *
 * Vanilla JS, no build step, no dependencies. Progressive enhancement:
 * the panel + checkboxes render server-side; this script wires the
 * show/hide toggle and the Save button, which PUTs the section
 * visibility map to /me/preferences/team-detail and reloads so the new
 * layout takes effect.
 *
 * Config is injected via wp_localize_script as window.TTTeamDetailCustomize
 * (rest_url, rest_nonce, i18n). No-JS users see the panel but can't
 * persist — the control is only rendered for coaches anyway, and the
 * default (all sections on) is always served.
 */
(function () {
    'use strict';

    var cfg = window.TTTeamDetailCustomize || {};
    var I18N = cfg.i18n || {};

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var trigger = document.querySelector('[data-tt-team-customize-trigger="1"]');
        var panel = document.getElementById('tt-team-customize-panel');
        if (!trigger || !panel) return;

        // Toggle the panel open / closed.
        trigger.addEventListener('click', function () {
            var open = panel.hasAttribute('hidden');
            if (open) {
                panel.removeAttribute('hidden');
                trigger.setAttribute('aria-expanded', 'true');
            } else {
                panel.setAttribute('hidden', '');
                trigger.setAttribute('aria-expanded', 'false');
            }
        });

        var saveBtn = panel.querySelector('[data-tt-team-customize-save="1"]');
        var statusEl = panel.querySelector('[data-tt-team-customize-status="1"]');
        if (!saveBtn) return;

        function setStatus(kind, text) {
            if (!statusEl) return;
            statusEl.className = 'tt-team-customize__status' + (kind ? ' is-' + kind : '');
            statusEl.textContent = text || '';
        }

        saveBtn.addEventListener('click', function () {
            var boxes = panel.querySelectorAll('[data-tt-team-section]');
            var sections = {};
            Array.prototype.forEach.call(boxes, function (cb) {
                sections[cb.getAttribute('data-tt-team-section')] = !!cb.checked;
            });

            var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
            if (cfg.rest_nonce) headers['X-WP-Nonce'] = cfg.rest_nonce;

            saveBtn.disabled = true;
            setStatus('', I18N.saving || 'Saving…');

            fetch(cfg.rest_url, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: headers,
                body: JSON.stringify({ sections: sections })
            })
                .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
                .then(function (r) {
                    if (r.ok && r.json && r.json.success) {
                        setStatus('success', I18N.saved || 'Saved. Reloading…');
                        window.location.reload();
                    } else {
                        saveBtn.disabled = false;
                        setStatus('error', I18N.error || 'Could not save. Try again.');
                    }
                })
                .catch(function () {
                    saveBtn.disabled = false;
                    setStatus('error', I18N.error || 'Could not save. Try again.');
                });
        });
    });
})();
