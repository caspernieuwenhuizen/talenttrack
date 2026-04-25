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
        return escapeHtml(v == null ? '' : v);
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
        return '<tr data-row-id="' + escapeHtml(row.id == null ? '' : row.id) + '">' + tds + '</tr>';
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
        if (!rows.length) {
            tbody.innerHTML = '<tr class="tt-list-table-empty"><td colspan="' + (Object.keys(config.columns).length + (Object.keys(config.row_actions).length ? 1 : 0)) + '">' + escapeHtml(config.i18n.empty) + '</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(function(r) { return renderRow(config, r); }).join('');
    }

    function refresh(root) {
        var config = root._ttListConfig;
        var state  = root._ttListState;
        setStatus(root, 'loading', config.i18n.loading);
        return fetchPage(config.rest_path, state).then(function(res) {
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

    function bindRowActions(root) {
        var tbody = root.querySelector('[data-tt-list-body="1"]');
        if (!tbody) return;
        tbody.addEventListener('click', function(e) {
            var btn = e.target.closest('button[data-rest-path]');
            if (!btn || !tbody.contains(btn)) return;
            var path = btn.getAttribute('data-rest-path');
            var method = btn.getAttribute('data-rest-method') || 'POST';
            var confirmText = btn.getAttribute('data-confirm');
            if (confirmText && !window.confirm(confirmText)) return;
            var rest = getRest();
            var headers = { 'Accept': 'application/json' };
            if (rest.nonce) headers['X-WP-Nonce'] = rest.nonce;
            btn.disabled = true;
            fetch(rest.url + path.replace(/^\/+/, ''), { method: method, credentials: 'same-origin', headers: headers })
                .then(function(res) { return res.json().then(function(json) { return { ok: res.ok, json: json }; }); })
                .then(function(r) {
                    if (r.ok && r.json && r.json.success) {
                        refresh(root);
                    } else {
                        var msg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message) || 'Error';
                        setStatus(root, 'error', msg);
                        btn.disabled = false;
                    }
                })
                .catch(function() {
                    setStatus(root, 'error', root._ttListConfig.i18n.error);
                    btn.disabled = false;
                });
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
                if (e.target && e.target.name === 'search') debouncedApply();
            });
            form.addEventListener('change', function(e) {
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

        // First fetch.
        refresh(root);
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard [data-tt-list-table="1"]').forEach(hydrate);
    });
})();
