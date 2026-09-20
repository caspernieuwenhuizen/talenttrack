/**
 * TalentTrack — link an existing prospect to a scouting visit (#3711)
 *
 * A scout who sees a known player again had no way to record it: the
 * "Log scouting find" wizard always creates a new prospect. This wires
 * the search box on the visit detail to GET /prospects (which applies the
 * viewer's prospect scope server-side) and the result buttons to
 * POST /scouting-visits/{id}/observations.
 *
 * Presentation only. Which prospects come back, and whether this caller
 * may link them, are both decided by the API.
 */
(function () {
    'use strict';

    var BOOT = window.TT_VISIT_OBSERVATIONS || null;
    var DEBOUNCE_MS = 250;
    var MIN_QUERY = 2;

    function i18n(key, fallback) {
        return (BOOT && BOOT.i18n && BOOT.i18n[key]) || fallback;
    }

    function headers(extra) {
        var h = { 'Accept': 'application/json' };
        if (BOOT && BOOT.rest_nonce) h['X-WP-Nonce'] = BOOT.rest_nonce;
        if (extra) Object.keys(extra).forEach(function (k) { h[k] = extra[k]; });
        return h;
    }

    function errorMessage(json, fallbackKey, fallback) {
        var first = json && json.errors && json.errors[0];
        return (first && first.message) || i18n(fallbackKey, fallback);
    }

    function setStatus(root, message) {
        var el = root.querySelector('[data-tt-observation-status]');
        if (el) el.textContent = message || '';
    }

    function renderResults(root, rows) {
        var list = root.querySelector('[data-tt-observation-results]');
        if (!list) return;
        list.innerHTML = '';

        if (!rows.length) {
            setStatus(root, i18n('no_results', 'No prospects match that name.'));
            return;
        }
        setStatus(root, '');

        rows.forEach(function (row) {
            var item = document.createElement('li');
            item.className = 'tt-observation-result';

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'tt-btn tt-btn-secondary tt-observation-pick';
            button.setAttribute('data-tt-observation-link', String(row.id));

            var name = document.createElement('span');
            name.className = 'tt-observation-name';
            name.textContent = [row.first_name, row.last_name].filter(Boolean).join(' ');
            button.appendChild(name);

            var meta = [row.birth_year, row.current_club].filter(Boolean).join(' · ');
            if (meta) {
                var metaEl = document.createElement('span');
                metaEl.className = 'tt-observation-meta';
                metaEl.textContent = meta;
                button.appendChild(metaEl);
            }

            item.appendChild(button);
            list.appendChild(item);
        });
    }

    function search(root, query) {
        var url = (BOOT && BOOT.prospects_url) || '';
        if (!url) return;

        var joiner = url.indexOf('?') === -1 ? '?' : '&';
        setStatus(root, i18n('searching', 'Searching…'));

        fetch(url + joiner + 'search=' + encodeURIComponent(query) + '&per_page=10', {
            credentials: 'same-origin',
            headers: headers()
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        }).then(function (r) {
            if (!r.ok || !r.json || !r.json.success) {
                setStatus(root, errorMessage(r.json, 'error_generic', 'Something went wrong. Please try again.'));
                return;
            }
            var data = r.json.data || {};
            renderResults(root, data.rows || data.prospects || []);
        }).catch(function () {
            setStatus(root, i18n('network_error', 'Network error. Please try again.'));
        });
    }

    function post(root, prospectId, button) {
        var url = (BOOT && BOOT.observations_url) || '';
        if (!url) return;
        if (button) button.disabled = true;

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ prospect_id: prospectId })
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        }).then(function (r) {
            if (r.ok && r.json && r.json.success) {
                window.location.reload();
                return;
            }
            setStatus(root, errorMessage(r.json, 'error_link', 'Could not link that prospect. Please try again.'));
            if (button) button.disabled = false;
        }).catch(function () {
            setStatus(root, i18n('network_error', 'Network error. Please try again.'));
            if (button) button.disabled = false;
        });
    }

    function unlink(root, observationId, button) {
        var url = (BOOT && BOOT.observations_url) || '';
        if (!url) return;
        if (button) button.disabled = true;

        fetch(url.replace(/\/+$/, '') + '/' + encodeURIComponent(observationId), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: headers()
        }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        }).then(function (r) {
            if (r.ok && r.json && r.json.success) {
                window.location.reload();
                return;
            }
            setStatus(root, errorMessage(r.json, 'error_unlink', 'Could not remove that link. Please try again.'));
            if (button) button.disabled = false;
        }).catch(function () {
            setStatus(root, i18n('network_error', 'Network error. Please try again.'));
            if (button) button.disabled = false;
        });
    }

    function boot(root) {
        var input = root.querySelector('[data-tt-observation-search]');
        var timer = null;

        if (input) {
            input.addEventListener('input', function () {
                var query = input.value.trim();
                if (timer) window.clearTimeout(timer);
                if (query.length < MIN_QUERY) {
                    renderResults(root, []);
                    setStatus(root, '');
                    return;
                }
                timer = window.setTimeout(function () { search(root, query); }, DEBOUNCE_MS);
            });
            // Enter must not submit the page — there is no form action.
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') e.preventDefault();
            });
        }

        root.addEventListener('click', function (e) {
            var link = e.target.closest('[data-tt-observation-link]');
            if (link) {
                e.preventDefault();
                post(root, parseInt(link.getAttribute('data-tt-observation-link'), 10), link);
                return;
            }
            var remove = e.target.closest('[data-tt-observation-unlink]');
            if (remove) {
                e.preventDefault();
                var id = remove.getAttribute('data-tt-observation-unlink');
                var msg = i18n('confirm_unlink', 'Remove this prospect from the visit?');
                if (typeof window.ttConfirm === 'function') {
                    window.ttConfirm({ message: msg, danger: true }).then(function (ok) {
                        if (ok) unlink(root, id, remove);
                    });
                } else if (window.confirm(msg)) {
                    unlink(root, id, remove);
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-tt-observations]').forEach(boot);
    });
})();
