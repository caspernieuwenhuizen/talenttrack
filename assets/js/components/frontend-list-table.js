/**
 * TalentTrack — FrontendListTable hydration
 * #0019 Sprint 2 session 2
 *
 * Each `.tt-list-table` element on the page hydrates independently
 * from the JSON config + state blocks the PHP shell embedded inside
 * it. After hydration, filter changes / sort clicks / pagination /
 * per-page changes all re-fetch via REST and reflect the new state
 * in the URL querystring.
 *
 * No-JS users get the initial server-rendered page with a working
 * filter form (full reload on submit). The JS upgrade is purely
 * additive — it cancels the form's default submit and takes over.
 */
(function(){
    'use strict';

    var SEARCH_DEBOUNCE_MS = 300;

    function getRest() {
        var t = window.TT || {};
        return {
            url: (t.rest_url || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/'),
            nonce: t.rest_nonce || ''
        };
    }

    function fetchPage(restPath, params) {
        var rest = getRest();
        var qs = new URLSearchParams();
        if (params.search) qs.set('search', params.search);
        Object.keys(params.filter || {}).forEach(function(k) {
            var v = params.filter[k];
            if (v !== '' && v != null) qs.set('filter[' + k + ']', v);
        });
        if (params.orderby)  qs.set('orderby',  params.orderby);
        if (params.order)    qs.set('order',    params.order);
        if (params.page)     qs.set('page',     String(params.page));
        if (params.per_page) qs.set('per_page', String(params.per_page));

        var url = rest.url + restPath.replace(/^\/+/, '') + '?' + qs.toString();
        var headers = { 'Accept': 'application/json' };
        if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
        return fetch(url, { credentials: 'same-origin', headers: headers })
            .then(function(res) { return res.json().then(function(json) { return { ok: res.ok, status: res.status, json: json }; }); });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function renderCell(col, row) {
        var v = row[col.value_key];
        if (col.render === 'percent') return v == null ? '—' : (v + '%');
        if (col.render === 'date')    return v == null ? '—' : escapeHtml(v);
        if (col.render === 'inline_select') return renderInlineSelect(col, row, v);
        // 'html' — emit a server-rendered HTML fragment verbatim. The
        // server is responsible for escaping; this mode bypasses the
        // per-cell escapeHtml() so things like coloured pills render.
        if (col.render === 'html') return v == null ? '' : String(v);
        return escapeHtml(v == null ? '' : v);
    }

    /**
     * Inline-select cell — emits a select bound to a REST PATCH
     * endpoint via data-attributes. Change event handler is bound
     * once at hydration time (see bindInlineSelects below).
     */
    function renderInlineSelect(col, row, current) {
        var path = (col.patch_path || '').replace(/\{([a-zA-Z0-9_]+)\}/g, function(_m, k) { return row[k] == null ? '' : encodeURIComponent(String(row[k])); });
        var opts = col.options || {};
        var html = '<select class="tt-list-inline-select"'
                 + ' data-tt-list-inline-select="1"'
                 + ' data-patch-path="' + escapeHtml(path) + '"'
                 + ' data-patch-field="' + escapeHtml(col.patch_field || col.value_key) + '">';
        Object.keys(opts).forEach(function(value) {
            var sel = String(current) === String(value) ? ' selected' : '';
            html += '<option value="' + escapeHtml(value) + '"' + sel + '>' + escapeHtml(opts[value]) + '</option>';
        });
        html += '</select>';
        return html;
    }

    /**
     * Substitute {placeholders} from a row into a string. Supports any
     * row property; callers only need to use names that match the
     * REST payload keys.
     */
    function fillTemplate(tpl, row) {
        return String(tpl).replace(/\{([a-zA-Z0-9_]+)\}/g, function(_m, key) {
            var v = row[key];
            return v == null ? '' : encodeURIComponent(String(v));
        });
    }

    function renderRowActions(actions, row) {
        var out = '';
        Object.keys(actions).forEach(function(key) {
            var a = actions[key];
            // #1470 — per-row visibility: when an action declares `show_if`,
            // only render it for rows where that field is truthy (e.g.
            // Restore / Delete-permanently only on rows with `archived_at`).
            if (a.show_if && !row[a.show_if]) return;
            var label = escapeHtml(a.label);
            if (a.href) {
                // The href template uses raw substitution (not URL-encoded twice for known-safe placeholders like {id}).
                var href = String(a.href).replace(/\{([a-zA-Z0-9_]+)\}/g, function(_m, k) { return row[k] == null ? '' : String(row[k]); });
                out += '<a class="tt-list-table-action" href="' + escapeHtml(href) + '" data-action="' + escapeHtml(key) + '">' + label + '</a>';
            } else if (a.rest_path) {
                var dataAttrs = ' data-action="' + escapeHtml(key) + '"' +
                                ' data-rest-method="' + escapeHtml(a.rest_method || 'POST') + '"' +
                                ' data-rest-path="' + escapeHtml(fillTemplate(a.rest_path, row)) + '"' +
                                ' data-row-id="' + escapeHtml(row.id == null ? '' : row.id) + '"';
                if (a.confirm) dataAttrs += ' data-confirm="' + escapeHtml(a.confirm) + '"';
                // #2023 — recycle-bin "Move to recycle bin" affordances:
                // confirm_cascade triggers the itemized cascade-preview
                // dialog; success_message + undo_path drive the Undo banner.
                if (a.confirm_cascade) dataAttrs += ' data-confirm-cascade="' + escapeHtml(a.confirm_cascade) + '"';
                if (a.success_message) dataAttrs += ' data-success-message="' + escapeHtml(a.success_message) + '"';
                if (a.undo_path) dataAttrs += ' data-undo-path="' + escapeHtml(fillTemplate(a.undo_path, row)) + '"';
                var cls = 'tt-list-table-action' + (a.variant === 'danger' ? ' tt-list-table-action-danger' : '');
                out += '<button type="button" class="' + cls + '"' + dataAttrs + '>' + label + '</button>';
            }
        });
        return out;
    }

    function renderRow(config, row) {
        var tds = '';
        Object.keys(config.columns).forEach(function(key) {
            var col = config.columns[key];
            tds += '<td data-label="' + escapeHtml(col.label) + '">' + renderCell(col, row) + '</td>';
        });
        if (Object.keys(config.row_actions).length) {
            tds += '<td class="tt-list-table-actions" data-label="">' + renderRowActions(config.row_actions, row) + '</td>';
        }
        // v3.110.169 (#758) — row-link standard. When the preset
        // declares `row_url_key` and the row carries that key, stamp
        // the <tr> with data-row-href + is-row-link + role=link +
        // tabindex=0 so the whole row is a click / Enter / Space
        // target. bindRowLinks() below wires the click handler,
        // skipping interactive descendants (links, buttons, inputs)
        // so per-column links keep working.
        var rowAttrs = ' data-row-id="' + escapeHtml(row.id == null ? '' : row.id) + '"';
        var rowClass = '';
        var rowUrlKey = config.row_url_key;
        if (rowUrlKey && row[rowUrlKey]) {
            rowAttrs += ' data-row-href="' + escapeHtml(String(row[rowUrlKey])) + '"';
            rowAttrs += ' role="link" tabindex="0"';
            rowClass = ' class="is-row-link"';
        }
        return '<tr' + rowClass + rowAttrs + '>' + tds + '</tr>';
    }

    function syncUrl(state) {
        if (!history.replaceState) return;
        var url = new URL(window.location.href);
        // Wipe owned keys, then re-set non-empty ones.
        url.searchParams.delete('search');
        url.searchParams.delete('orderby');
        url.searchParams.delete('order');
        url.searchParams.delete('page');
        url.searchParams.delete('per_page');
        Array.prototype.slice.call(url.searchParams.keys()).forEach(function(k) {
            if (k.indexOf('filter[') === 0) url.searchParams.delete(k);
        });
        if (state.search) url.searchParams.set('search', state.search);
        Object.keys(state.filter).forEach(function(k) {
            var v = state.filter[k];
            if (v !== '' && v != null) url.searchParams.set('filter[' + k + ']', v);
        });
        if (state.orderby)  url.searchParams.set('orderby',  state.orderby);
        if (state.order)    url.searchParams.set('order',    state.order);
        if (state.page > 1) url.searchParams.set('page',     String(state.page));
        if (state.per_page && state.per_page !== 25) url.searchParams.set('per_page', String(state.per_page));
        history.replaceState({}, '', url.toString());
    }

    function renderPager(root, state, total) {
        var per = Math.max(1, state.per_page);
        var totalPages = Math.max(1, Math.ceil(total / per));
        var pager = root.querySelector('[data-tt-list-pager="1"]');
        var summary = root.querySelector('[data-tt-list-summary="1"]');
        var i18n = root._ttListConfig.i18n;

        if (summary) {
            if (total === 0) {
                summary.textContent = '';
            } else {
                var first = (state.page - 1) * per + 1;
                var last = Math.min(state.page * per, total);
                summary.textContent = i18n.showing
                    .replace('%1$d', String(first))
                    .replace('%2$d', String(last))
                    .replace('%3$d', String(total));
            }
        }

        if (!pager) return;
        if (totalPages <= 1) { pager.innerHTML = ''; return; }
        var prev = state.page > 1
            ? '<button type="button" data-tt-list-page="' + (state.page - 1) + '" class="tt-btn tt-btn-secondary">‹</button>'
            : '<button type="button" disabled class="tt-btn tt-btn-secondary">‹</button>';
        var next = state.page < totalPages
            ? '<button type="button" data-tt-list-page="' + (state.page + 1) + '" class="tt-btn tt-btn-secondary">›</button>'
            : '<button type="button" disabled class="tt-btn tt-btn-secondary">›</button>';
        var label = i18n.page_of.replace('%1$d', String(state.page)).replace('%2$d', String(totalPages));
        pager.innerHTML = prev + ' <span class="tt-list-table-page-label">' + escapeHtml(label) + '</span> ' + next;
    }

    function setStatus(root, kind, text) {
        var el = root.querySelector('[data-tt-list-status="1"]');
        if (!el) return;
        el.className = 'tt-list-table-status' + (kind ? ' is-' + kind : '');
        el.textContent = text || '';
    }

    function renderRows(root, config, state, payload) {
        var tbody = root.querySelector('[data-tt-list-body="1"]');
        if (!tbody) return;
        var rows = (payload && payload.rows) || [];
        var isCards = config.layout === 'cards';
        if (!rows.length) {
            // #1362 — guided EmptyStateCard (server-rendered, cap-aware
            // HTML in config.empty_html) for the fresh "you have
            // nothing yet" case; plain "nothing matches your filters"
            // text whenever a search or filter is active.
            var hasQuery = !!state.search || Object.keys(state.filter || {}).length > 0;
            var emptyContent = (!hasQuery && config.empty_html)
                ? config.empty_html
                : escapeHtml(config.i18n.empty);
            if (isCards) {
                // #1614 — the card grid is a <div>, not a table; the
                // empty state lives in a plain wrapper, no <td colspan>.
                tbody.innerHTML = '<div class="tt-list-table-empty">' + emptyContent + '</div>';
            } else {
                tbody.innerHTML = '<tr class="tt-list-table-empty"><td colspan="' + (Object.keys(config.columns).length + (Object.keys(config.row_actions).length ? 1 : 0)) + '">' + emptyContent + '</td></tr>';
            }
            return;
        }
        if (isCards) {
            // #1614 — each row carries a server-rendered whole-card <a>
            // fragment (the card_value_key field). Emit it verbatim;
            // the <a> is the keyboard-focusable tap target, so no
            // row-link wiring is needed.
            var key = config.card_value_key || 'card_html';
            tbody.innerHTML = rows.map(function(r) { return r[key] == null ? '' : String(r[key]); }).join('');
            return;
        }
        tbody.innerHTML = rows.map(function(r) { return renderRow(config, r); }).join('');
    }

    function refresh(root) {
        var config = root._ttListConfig;
        var state  = root._ttListState;
        // #1361 — aria-busy + .tt-is-loading on the component root for
        // the duration of the fetch; CSS dims the stale rows and AT
        // announces the busy state. TT.Loading lives in public.js.
        var loading = (window.TT && TT.Loading) || null;
        if (loading) loading.start(root);
        setStatus(root, 'loading', config.i18n.loading);
        // v3.92.7 — merge static_filters (server-locked filter values
        // passed via the PHP `static_filters` config arg) into the
        // request filter map so they're sent on every fetch without
        // appearing as user-editable controls. Used by surfaces like
        // `?tt_view=my-activities` that need a permanent player_id
        // scope.
        var requestState = state;
        if (config.static_filters && typeof config.static_filters === 'object') {
            var mergedFilter = {};
            Object.keys(state.filter || {}).forEach(function(k) { mergedFilter[k] = state.filter[k]; });
            Object.keys(config.static_filters).forEach(function(k) {
                if (mergedFilter[k] === undefined || mergedFilter[k] === '' || mergedFilter[k] == null) {
                    mergedFilter[k] = config.static_filters[k];
                }
            });
            requestState = Object.assign({}, state, { filter: mergedFilter });
        }
        return fetchPage(config.rest_path, requestState).then(function(res) {
            if (!res.ok || !res.json || !res.json.success) {
                setStatus(root, 'error', config.i18n.error);
                return;
            }
            setStatus(root, '', '');
            renderRows(root, config, state, res.json.data);
            renderPager(root, state, (res.json.data && res.json.data.total) || 0);
            updateSortHeaders(root, state);
        }).catch(function() {
            setStatus(root, 'error', config.i18n.error);
        }).finally(function() {
            if (loading) loading.stop(root);
        });
    }

    function updateSortHeaders(root, state) {
        root.querySelectorAll('[data-tt-list-sort]').forEach(function(th) {
            var key = th.getAttribute('data-tt-list-sort');
            var active = key === state.orderby;
            th.classList.toggle('is-active', active);
            var arrow = th.querySelector('span[aria-hidden="true"]');
            if (arrow) arrow.textContent = active ? (state.order === 'asc' ? '↑' : '↓') : '';
        });
    }

    function debounce(fn, ms) {
        var t;
        return function() {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function() { fn.apply(ctx, args); }, ms);
        };
    }

    function readFiltersFromForm(form, config) {
        var filter = {};
        var fd = new FormData(form);
        fd.forEach(function(value, key) {
            if (key === 'search') return;
            var m = /^filter\[(.+)\]$/.exec(key);
            if (!m) return;
            if (value !== '' && value != null) filter[m[1]] = value;
        });
        var search = fd.get('search') || '';
        return { search: String(search), filter: filter };
    }

    /**
     * #2082 — the FilterBar chrome renders each control twice (the inline
     * desktop row and the mobile bottom-sheet share one <form>). When the
     * user edits one copy, mirror its value onto every sibling carrying
     * the same `name` so the two copies never drift — otherwise FormData
     * would emit two conflicting values for one filter. Checkboxes mirror
     * `checked`; everything else mirrors `value`.
     */
    function syncDuplicateControls(form, source) {
        if (!source || !source.name) return;
        var matches = form.querySelectorAll('[name="' + (window.CSS && CSS.escape ? CSS.escape(source.name) : source.name.replace(/(["\\])/g, '\\$1')) + '"]');
        Array.prototype.forEach.call(matches, function(ctrl) {
            if (ctrl === source) return;
            if (source.type === 'checkbox' || source.type === 'radio') {
                if (ctrl.checked !== source.checked) ctrl.checked = source.checked;
            } else if (ctrl.value !== source.value) {
                ctrl.value = source.value;
            }
        });
    }

    function bindInlineSelects(root) {
        var tbody = root.querySelector('[data-tt-list-body="1"]');
        if (!tbody) return;
        tbody.addEventListener('change', function(e) {
            var sel = e.target.closest('select[data-tt-list-inline-select="1"]');
            if (!sel || !tbody.contains(sel)) return;
            var path  = sel.getAttribute('data-patch-path');
            var field = sel.getAttribute('data-patch-field');
            if (!path || !field) return;
            var rest = getRest();
            var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
            if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
            var body = {};
            body[field] = sel.value;
            sel.disabled = true;
            fetch(rest.url + path.replace(/^\/+/, ''), {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: headers,
                body: JSON.stringify(body)
            })
                .then(function(res) { return res.json().then(function(json) { return { ok: res.ok, json: json }; }); })
                .then(function(r) {
                    sel.disabled = false;
                    if (!r.ok || !r.json || !r.json.success) {
                        var msg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message) || root._ttListConfig.i18n.error;
                        setStatus(root, 'error', msg);
                    }
                })
                .catch(function() {
                    sel.disabled = false;
                    setStatus(root, 'error', root._ttListConfig.i18n.error);
                });
        });
    }

    function i18n(key, fallback) {
        return (window.TT && TT.i18n && TT.i18n[key]) || fallback;
    }

    /**
     * #2023 — build the itemized cascade-preview HTML (caller-escaped) for
     * the "Move to recycle bin" confirm dialog. Renders the rows a later
     * purge would remove / null / zero, plus any current blockers (shown
     * informationally; the move itself is never blocked).
     */
    function buildCascadeDetails(preview) {
        if (!preview) return '';
        var removals = (preview.removals || []);
        var nulls    = (preview.nullifications || []);
        var zeros    = (preview.zeroings || []);
        var blockers = preview.blockers || {};
        var blockerKeys = Object.keys(blockers);

        function tidy(name) { return String(name).replace(/^tt_/, '').replace(/_/g, ' '); }
        function listFrom(items, withColumn) {
            var li = items.map(function(it) {
                var label = tidy(it.table) + (withColumn && it.column ? ' (' + escapeHtml(it.column) + ')' : '');
                return '<li>' + escapeHtml(label) + ' — ' + escapeHtml(String(it.count)) + '</li>';
            });
            return li.join('');
        }

        var out = '';
        if (!removals.length && !nulls.length && !zeros.length && !blockerKeys.length) {
            out += '<p class="tt-cascade-empty">' + escapeHtml(i18n('cascade_none', 'No linked records.')) + '</p>';
            return out;
        }
        if (removals.length) {
            out += '<p class="tt-cascade-heading">' + escapeHtml(i18n('cascade_removed', 'Linked records that will be removed on purge:')) + '</p>';
            out += '<ul class="tt-cascade-list">' + listFrom(removals, false) + '</ul>';
        }
        if (nulls.length) {
            out += '<p class="tt-cascade-heading">' + escapeHtml(i18n('cascade_kept', 'References that will be cleared:')) + '</p>';
            out += '<ul class="tt-cascade-list">' + listFrom(nulls, true) + '</ul>';
        }
        if (zeros.length) {
            out += '<p class="tt-cascade-heading">' + escapeHtml(i18n('cascade_zeroed', 'References that will be reset:')) + '</p>';
            out += '<ul class="tt-cascade-list">' + listFrom(zeros, true) + '</ul>';
        }
        if (blockerKeys.length) {
            var bItems = blockerKeys.map(function(t) { return { table: t, count: blockers[t] }; });
            out += '<p class="tt-cascade-heading tt-cascade-heading-blocker">' + escapeHtml(i18n('cascade_blockers', 'Records that currently block a permanent delete:')) + '</p>';
            out += '<ul class="tt-cascade-list tt-cascade-list-blocker">' + listFrom(bItems, false) + '</ul>';
        }
        return out;
    }

    function fetchCascadePreview(entity, id) {
        var rest = getRest();
        var headers = { 'Accept': 'application/json' };
        if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
        var url = rest.url + 'recycle-bin/preview/' + encodeURIComponent(entity) + '/' + encodeURIComponent(id);
        return fetch(url, { credentials: 'same-origin', headers: headers })
            .then(function(res) { return res.json().then(function(json) { return { ok: res.ok, json: json }; }); })
            .then(function(r) { return (r.ok && r.json && r.json.success) ? r.json.data : null; })
            .catch(function() { return null; });
    }

    function bindRowActions(root) {
        var tbody = root.querySelector('[data-tt-list-body="1"]');
        if (!tbody) return;
        tbody.addEventListener('click', function(e) {
            var btn = e.target.closest('button[data-rest-path]');
            if (!btn || !tbody.contains(btn)) return;
            var path = btn.getAttribute('data-rest-path');
            var method = btn.getAttribute('data-rest-method') || 'POST';
            var confirmText = btn.getAttribute('data-confirm');
            var successMsg = btn.getAttribute('data-success-message');
            var cascadeEntity = btn.getAttribute('data-confirm-cascade');
            var undoPath = btn.getAttribute('data-undo-path');
            var rowId = btn.getAttribute('data-row-id');

            // After a successful trash, offer an Undo banner that restores the
            // row straight out of the bin (POST {plural}/{id}/restore).
            var offerUndo = function() {
                if (!undoPath || !window.ttFlash || !window.ttFlash.addAction) {
                    if (successMsg && window.ttFlash && window.ttFlash.add) {
                        window.ttFlash.add('success', successMsg);
                    }
                    return;
                }
                window.ttFlash.addAction('success', successMsg || '', i18n('undo', 'Undo'), function() {
                    var rest = getRest();
                    var headers = { 'Accept': 'application/json' };
                    if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
                    fetch(rest.url + undoPath.replace(/^\/+/, ''), { method: 'POST', credentials: 'same-origin', headers: headers })
                        .then(function() { refresh(root); })
                        .catch(function() { setStatus(root, 'error', root._ttListConfig.i18n.error); });
                });
            };

            var doAction = function() {
                var rest = getRest();
                var headers = { 'Accept': 'application/json' };
                if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
                btn.disabled = true;
                fetch(rest.url + path.replace(/^\/+/, ''), { method: method, credentials: 'same-origin', headers: headers })
                    .then(function(res) { return res.json().then(function(json) { return { ok: res.ok, json: json }; }); })
                    .then(function(r) {
                        if (r.ok && r.json && r.json.success) {
                            if (undoPath) {
                                offerUndo();
                            } else if (successMsg && window.ttFlash && window.ttFlash.addNear) {
                                window.ttFlash.addNear(btn, 'success', successMsg);
                            }
                            refresh(root);
                        } else {
                            var msg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message) || i18n('error_generic', 'Error.');
                            setStatus(root, 'error', msg);
                            btn.disabled = false;
                        }
                    })
                    .catch(function() {
                        setStatus(root, 'error', root._ttListConfig.i18n.error);
                        btn.disabled = false;
                    });
            };

            // #2023 — recycle-bin move: fetch + show the itemized cascade
            // preview in the shared confirm dialog before trashing.
            if (cascadeEntity && rowId) {
                if (typeof window.ttConfirm !== 'function') { if (window.confirm(confirmText || '')) doAction(); return; }
                fetchCascadePreview(cascadeEntity, rowId).then(function(preview) {
                    window.ttConfirm({
                        title: btn.textContent,
                        message: confirmText || '',
                        detailsHtml: buildCascadeDetails(preview),
                        confirmLabel: btn.textContent,
                        danger: true
                    }).then(function(ok) { if (ok) doAction(); });
                });
                return;
            }

            if (!confirmText) { doAction(); return; }
            if (typeof window.ttConfirm === 'function') {
                window.ttConfirm({ message: confirmText, danger: true }).then(function(ok) { if (ok) doAction(); });
            } else if (window.confirm(confirmText)) {
                doAction();
            }
        });
    }

    function hydrate(root) {
        var configEl = root.querySelector('[data-tt-list-config="1"]');
        var stateEl  = root.querySelector('[data-tt-list-state="1"]');
        if (!configEl || !stateEl) return;
        var config, state;
        try { config = JSON.parse(configEl.textContent || '{}'); } catch (_) { return; }
        try { state  = JSON.parse(stateEl.textContent  || '{}'); } catch (_) { return; }
        root._ttListConfig = config;
        root._ttListState  = state;

        var form = root.querySelector('[data-tt-list-form="1"]');
        if (form) {
            // Cancel no-JS submit; switch to live filtering.
            form.addEventListener('submit', function(e) { e.preventDefault(); applyFromForm(); });

            var debouncedApply = debounce(applyFromForm, SEARCH_DEBOUNCE_MS);
            form.addEventListener('input',  function(e) {
                // #2082 — keep the inline + sheet copies of the control in
                // sync before reading the form, so FormData never sees two
                // conflicting values for one filter.
                if (e.target) syncDuplicateControls(form, e.target);
                if (e.target && e.target.name === 'search') debouncedApply();
            });
            form.addEventListener('change', function(e) {
                if (e.target) syncDuplicateControls(form, e.target);
                // #1614 — the card-mode sort dropdown lives in the form but
                // drives orderby/order via its own handler, not a filter.
                if (e.target && e.target.getAttribute && e.target.getAttribute('data-tt-list-sort-select') === '1') return;
                if (e.target && e.target.name && e.target.name !== 'search') applyFromForm();
            });
        }

        function applyFromForm() {
            var snap = readFiltersFromForm(form, config);
            state.search = snap.search;
            state.filter = snap.filter;
            state.page = 1;
            syncUrl(state);
            refresh(root);
        }

        // Sort header clicks.
        root.querySelectorAll('[data-tt-list-sort]').forEach(function(th) {
            var anchor = th.querySelector('a');
            if (!anchor) return;
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                var key = th.getAttribute('data-tt-list-sort');
                if (state.orderby === key) {
                    state.order = state.order === 'asc' ? 'desc' : 'asc';
                } else {
                    state.orderby = key;
                    state.order = 'asc';
                }
                state.page = 1;
                syncUrl(state);
                refresh(root);
            });
        });

        // #1614 — card-mode sort dropdown ("name:asc" style values).
        // Lives inside the filter form, so the form's `change` handler
        // would otherwise treat it as a filter; it carries no
        // `filter[...]` name, so readFiltersFromForm ignores it, and we
        // bind orderby/order directly here. #2082 — the FilterBar chrome
        // renders the control twice (inline + sheet); bind both copies and
        // read the value off the one that changed.
        root.querySelectorAll('[data-tt-list-sort-select="1"]').forEach(function(sortSelect) {
            sortSelect.addEventListener('change', function() {
                var parts = String(sortSelect.value || '').split(':');
                state.orderby = parts[0] || state.orderby;
                state.order = (parts[1] === 'desc') ? 'desc' : 'asc';
                state.page = 1;
                syncUrl(state);
                refresh(root);
            });
        });

        // Per-page selector.
        var perPage = root.querySelector('[data-tt-list-perpage="1"]');
        if (perPage) {
            perPage.addEventListener('change', function() {
                state.per_page = parseInt(perPage.value, 10) || 25;
                state.page = 1;
                syncUrl(state);
                refresh(root);
            });
        }

        // Pager.
        var pager = root.querySelector('[data-tt-list-pager="1"]');
        if (pager) {
            pager.addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-tt-list-page]');
                if (!btn || !pager.contains(btn)) return;
                var p = parseInt(btn.getAttribute('data-tt-list-page'), 10);
                if (!p || p === state.page) return;
                state.page = p;
                syncUrl(state);
                refresh(root);
            });
        }

        bindRowActions(root);
        bindInlineSelects(root);
        bindRowLinks(root);

        // First fetch.
        refresh(root);
    }

    // v3.110.169 (#758) — row-link delegated click handler. Navigates
    // to row.dataset.rowHref on click / Enter / Space, except when
    // the actual click target is interactive (link, button, input,
    // select, label, textarea, anything with role="button"). Middle-
    // click + cmd/ctrl-click open in a new tab. Text-selection drags
    // are ignored — if the user is actively selecting, the click
    // event doesn't fire on mouseup.
    function bindRowLinks(root) {
        var tbody = root.querySelector('[data-tt-list-body="1"]');
        if (!tbody) return;

        function targetIsInteractive(el) {
            // Walk up from the click target to the row, looking for
            // any interactive element. If we hit one, skip navigation.
            while (el && el !== tbody) {
                if (el.tagName === 'A' || el.tagName === 'BUTTON' ||
                    el.tagName === 'INPUT' || el.tagName === 'SELECT' ||
                    el.tagName === 'TEXTAREA' || el.tagName === 'LABEL') {
                    return true;
                }
                if (el.getAttribute && el.getAttribute('role') === 'button') return true;
                el = el.parentNode;
            }
            return false;
        }

        function navigate(href, newTab) {
            if (!href) return;
            if (newTab) {
                window.open(href, '_blank', 'noopener');
            } else {
                window.location.href = href;
            }
        }

        tbody.addEventListener('click', function(e) {
            var tr = e.target.closest && e.target.closest('tr.is-row-link');
            if (!tr || !tbody.contains(tr)) return;
            if (targetIsInteractive(e.target)) return;
            // Don't navigate on text-selection drags.
            var sel = window.getSelection && window.getSelection();
            if (sel && sel.toString && sel.toString().length > 0 && sel.containsNode && sel.containsNode(tr, true)) return;
            var href = tr.getAttribute('data-row-href');
            navigate(href, e.metaKey || e.ctrlKey || e.button === 1);
        });

        // Middle-click on a row (auxclick fires for non-primary buttons).
        tbody.addEventListener('auxclick', function(e) {
            if (e.button !== 1) return; // middle button only
            var tr = e.target.closest && e.target.closest('tr.is-row-link');
            if (!tr || !tbody.contains(tr)) return;
            if (targetIsInteractive(e.target)) return;
            e.preventDefault();
            navigate(tr.getAttribute('data-row-href'), true);
        });

        // Enter / Space on a focused row activates the link.
        tbody.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var tr = e.target.closest && e.target.closest('tr.is-row-link');
            if (!tr || !tbody.contains(tr)) return;
            if (targetIsInteractive(e.target)) return;
            e.preventDefault();
            navigate(tr.getAttribute('data-row-href'), e.metaKey || e.ctrlKey);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard [data-tt-list-table="1"]').forEach(hydrate);
    });
})();
