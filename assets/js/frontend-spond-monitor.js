/*
 * frontend-spond-monitor.js — FrontendSpondMonitorView (#2284).
 *
 * Dry-run Spond integration monitor. On "Fetch now" it POSTs to
 *   POST /wp-json/talenttrack/v1/teams/{id}/spond/preview
 * (SpondRestController::route_preview) which fetches Spond LIVE and diffs
 * each incoming event against the stored activity WITHOUT writing anything,
 * then renders the returned events + counts + archive list.
 *
 * All DOM is built with textContent (never innerHTML of untrusted data) so
 * a Spond title / location / description can't inject markup. Strings come
 * from the localised TT_SpondMonitor object — no hard-coded English.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-tt-spm]');
    if (!root) return;

    var cfg = window.TT_SpondMonitor || {};
    var i18n = cfg.i18n || {};
    var restRoot = String(cfg.rest_root || '/wp-json/talenttrack/v1/teams/');
    var nonce = String(cfg.nonce || '');

    var teamSel = root.querySelector('[data-tt-spm-team]');
    var fetchBtn = root.querySelector('[data-tt-spm-fetch]');
    var results = root.querySelector('[data-tt-spm-results]');

    function t(key, fallback) {
        return (i18n && i18n[key]) || fallback || '';
    }

    // Minimal %d / %1$d / %2$d / %3$d formatter for the localised strings.
    function fmt(str, args) {
        var i = 0;
        return String(str)
            .replace(/%(\d+)\$d/g, function (_, n) { return String(args[parseInt(n, 10) - 1]); })
            .replace(/%d/g, function () { return String(args[i++]); });
    }

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function setFetching(on) {
        if (!fetchBtn) return;
        fetchBtn.disabled = on;
        fetchBtn.textContent = on ? t('fetching', 'Fetching…') : t('fetch', 'Fetch now');
    }

    function showMessage(text, kind) {
        clear(results);
        var p = el('p', 'tt-spm__msg' + (kind ? ' tt-spm__msg--' + kind : ''), text);
        results.appendChild(p);
    }

    function firstError(json) {
        return (json && json.errors && json.errors[0] && json.errors[0].message) || '';
    }

    // ---- Fetch ----------------------------------------------------------
    if (fetchBtn) {
        fetchBtn.addEventListener('click', function () {
            var teamId = teamSel ? parseInt(teamSel.value || '0', 10) : 0;
            if (!teamId) {
                showMessage(t('no_team', 'Pick a team first.'), 'error');
                return;
            }
            setFetching(true);
            clear(results);

            var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
            if (nonce) headers['X-WP-Nonce'] = nonce;

            fetch(restRoot + teamId + '/spond/preview', {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers,
                body: '{}'
            }).then(function (res) {
                return res.json().then(function (json) { return { ok: res.ok, json: json }; });
            }).then(function (r) {
                setFetching(false);
                if (!r.ok || !r.json || !r.json.success) {
                    showMessage(firstError(r.json) || t('error', 'Could not fetch.'), 'error');
                    return;
                }
                render(r.json.data || {});
            }).catch(function () {
                setFetching(false);
                showMessage(t('network_error', 'Network error.'), 'error');
            });
        });
    }

    // ---- Render ---------------------------------------------------------
    function render(data) {
        clear(results);

        // Domain-level failure (no group linked, live fetch failed, …) —
        // the envelope succeeded but ok:false carries the reason.
        if (data.ok === false) {
            showMessage(data.error_message || t('error', 'Could not fetch.'), 'error');
            return;
        }

        var counts = data.counts || {};
        var summary = el('p', 'tt-spm__result-summary');
        summary.appendChild(el('strong', null, fmt(t('summary', '%1$d new · %2$d update · %3$d archive'), [
            counts['new'] || 0, counts.update || 0, counts.archive || 0
        ])));
        var fetched = el('span', 'tt-spm__result-fetched',
            ' — ' + fmt(t('fetched', '%d events fetched from Spond.'), [data.fetched_count || 0]));
        summary.appendChild(fetched);
        results.appendChild(summary);

        var events = Array.isArray(data.events) ? data.events : [];
        if (!events.length) {
            results.appendChild(el('p', 'tt-spm__empty', t('nothing', 'Spond returned no events.')));
        } else {
            var list = el('div', 'tt-spm__events');
            events.forEach(function (ev) { list.appendChild(renderEvent(ev)); });
            results.appendChild(list);
        }

        var archive = Array.isArray(data.archive) ? data.archive : [];
        if (archive.length) results.appendChild(renderArchive(archive));
    }

    function renderEvent(ev) {
        var isUpdate = ev.status === 'update';
        var card = el('details', 'tt-spm__event tt-spm__event--' + (isUpdate ? 'update' : 'new'));

        var summary = el('summary', 'tt-spm__event-head');
        summary.appendChild(el('span',
            'tt-spm__chip tt-spm__chip--' + (isUpdate ? 'update' : 'new'),
            isUpdate ? t('status_update', 'UPDATE') : t('status_new', 'NEW')));
        summary.appendChild(el('span', 'tt-spm__event-title', ev.summary || ''));
        summary.appendChild(el('span', 'tt-spm__event-type', ev.type || ''));
        card.appendChild(summary);

        var body = el('div', 'tt-spm__event-body');

        // Meta: when + location.
        var meta = el('dl', 'tt-spm__meta');
        var when = ev.dtstart || '';
        if (ev.start_time) when += ' · ' + (ev.start_time || '') + (ev.end_time ? '–' + ev.end_time : '');
        appendMeta(meta, t('col_when', 'When'), when);
        appendMeta(meta, t('col_location', 'Location'), ev.location || '—');
        if (ev.description) appendMeta(meta, t('description', 'Description'), ev.description);
        body.appendChild(meta);

        // Change diff (update rows only).
        if (isUpdate) {
            var changes = Array.isArray(ev.changes) ? ev.changes : [];
            if (!changes.length) {
                body.appendChild(el('p', 'tt-spm__no-changes', t('no_changes', 'No schedule changes.')));
            } else {
                body.appendChild(el('p', 'tt-spm__changes-label', t('changes', 'Would change:')));
                var tbl = el('table', 'tt-spm__changes');
                var thead = el('tr', null);
                thead.appendChild(el('th', null, ''));
                thead.appendChild(el('th', null, t('from', 'stored')));
                thead.appendChild(el('th', null, t('to', 'Spond')));
                tbl.appendChild(thead);
                changes.forEach(function (ch) {
                    var tr = el('tr', null);
                    tr.appendChild(el('th', 'tt-spm__changes-field', ch.field || ''));
                    tr.appendChild(el('td', 'tt-spm__changes-from', (ch.from === '' || ch.from == null) ? '—' : ch.from));
                    tr.appendChild(el('td', 'tt-spm__changes-to', (ch.to === '' || ch.to == null) ? '—' : ch.to));
                    tbl.appendChild(tr);
                });
                body.appendChild(tbl);
            }
        }

        card.appendChild(body);
        return card;
    }

    function appendMeta(dl, label, value) {
        dl.appendChild(el('dt', null, label));
        dl.appendChild(el('dd', null, value || '—'));
    }

    function renderArchive(archive) {
        var section = el('section', 'tt-spm__archive');
        section.appendChild(el('h3', 'tt-spm__archive-title', t('archive_title', 'Would be archived')));
        section.appendChild(el('p', 'tt-spm__archive-hint', t('archive_hint', '')));
        var ul = el('ul', 'tt-spm__archive-list');
        archive.forEach(function (row) {
            var li = el('li', 'tt-spm__archive-item');
            li.appendChild(el('span', 'tt-spm__chip tt-spm__chip--archive', t('archive_title', 'Archive')));
            li.appendChild(el('span', 'tt-spm__archive-name', row.title || ('#' + (row.activity_id || ''))));
            ul.appendChild(li);
        });
        section.appendChild(ul);
        return section;
    }
})();
