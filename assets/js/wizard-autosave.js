/*
 * Wizard autosave (#0072 follow-up).
 *
 * Listens for `input` + `change` events on `.tt-wizard-form`, debounces
 * ~800ms, and POSTs the form's current field map to
 *   POST /wp-json/talenttrack/v1/wizards/{slug}/draft
 * which merges the patch into the cross-device-persistent
 * `tt_wizard_drafts` row.
 *
 * Visible UX: a small status caption next to the action buttons cycles
 * through "Idle / Saving… / Saved · HH:MM / Save failed".
 */
(function () {
    'use strict';

    var cfg = window.TT_WizardAutosave || null;
    if (!cfg || !cfg.rest_url || !cfg.rest_nonce || !cfg.slug) return;

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('.tt-wizard-form');
        if (!form) return;

        var status = document.querySelector('[data-tt-autosave-status]');
        if (!status) return;

        var DEBOUNCE_MS = 800;
        var pending = null;
        var lastSentSerial = '';
        var inflight = false;

        function setStatus(state, label) {
            status.dataset.state = state;
            status.textContent = label;
        }

        function serialize() {
            var data = {};
            var elems = form.querySelectorAll('input, select, textarea');
            Array.prototype.forEach.call(elems, function (el) {
                if (!el.name) return;
                if (el.disabled) return;
                if (el.type === 'submit' || el.type === 'button' || el.type === 'reset') return;
                if (el.type === 'hidden' && (el.name === 'tt_wizard_nonce' || el.name === 'tt_wizard_action' || el.name === '_cancel_url' || el.name.charAt(0) === '_')) return;
                if (el.type === 'checkbox' || el.type === 'radio') {
                    if (!el.checked) return;
                    appendValue(data, el.name, el.value);
                    return;
                }
                appendValue(data, el.name, el.value);
            });
            return data;
        }

        function appendValue(target, name, value) {
            // Convert PHP-style "key[]" to arrays.
            var match = name.match(/^([^\[]+)\[\]$/);
            if (match) {
                var key = match[1];
                if (!Array.isArray(target[key])) target[key] = [];
                target[key].push(value);
                return;
            }
            target[name] = value;
        }

        function fire() {
            if (inflight) {
                pending = setTimeout(fire, DEBOUNCE_MS);
                return;
            }
            var fields = serialize();
            var serial = JSON.stringify(fields);
            if (serial === lastSentSerial || serial === '{}') return;

            inflight = true;
            setStatus('saving', cfg.i18n_saving || 'Saving…');

            fetch(cfg.rest_url + 'wizards/' + encodeURIComponent(cfg.slug) + '/draft', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
                body: JSON.stringify({ fields: fields })
            }).then(function (res) {
                inflight = false;
                if (!res.ok) {
                    setStatus('error', cfg.i18n_failed || 'Save failed');
                    return;
                }
                lastSentSerial = serial;
                var now = new Date();
                var hh = String(now.getHours()).padStart(2, '0');
                var mm = String(now.getMinutes()).padStart(2, '0');
                setStatus('saved', (cfg.i18n_saved || 'Saved · ') + hh + ':' + mm);
            }).catch(function () {
                inflight = false;
                setStatus('error', cfg.i18n_failed || 'Save failed');
            });
        }

        function schedule() {
            if (pending) clearTimeout(pending);
            pending = setTimeout(fire, DEBOUNCE_MS);
        }

        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
    });
})();
