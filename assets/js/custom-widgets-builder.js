/**
 * custom-widgets-builder.js (#0078 Phase 3) — vanilla-JS state machine
 * for the multi-step widget authoring UX. Reads the bootstrap blob
 * from `window.TTCustomWidgetsBootstrap` (sources catalogue, REST
 * root + nonce, optional widget being edited, i18n strings).
 *
 * Steps (in order): source → columns → filters → format → preview → save.
 *
 * Validation runs inline as the operator advances; the Save step
 * POSTs (or PUTs on edit) to `/wp-json/talenttrack/v1/custom-widgets`
 * and surfaces the discriminated `CustomWidgetException` kinds via
 * the status banner.
 */

(function () {
    'use strict';
    if (typeof window === 'undefined' || !document) return;

    function init() {
        var root = document.querySelector('[data-tt-cw-builder]');
        if (!root) return;
        var boot = window.TTCustomWidgetsBootstrap || {};
        if (!boot.sources || !boot.i18n) return;

        var state = stateFromBootstrap(boot);
        var $body = root.querySelector('[data-tt-cw="body"]');
        var $prev = root.querySelector('[data-tt-cw="prev"]');
        var $next = root.querySelector('[data-tt-cw="next"]');
        var $stat = root.querySelector('[data-tt-cw="status"]');
        var $stepper = root.querySelector('[data-tt-cw="stepper"]');

        var steps = ['source', 'columns', 'filters', 'format', 'preview', 'save'];
        var stepIdx = 0;

        function setStep(i) {
            stepIdx = Math.max(0, Math.min(steps.length - 1, i));
            renderStep();
            updateNav();
            updateStepper();
        }

        function updateNav() {
            $prev.disabled = stepIdx === 0;
            $next.textContent = stepIdx === steps.length - 1 ? boot.i18n.save : boot.i18n.next + ' →';
        }

        function updateStepper() {
            if (!$stepper) return;
            var items = $stepper.querySelectorAll('li');
            items.forEach(function (li, i) {
                li.classList.toggle('is-active', i === stepIdx);
                li.classList.toggle('is-done', i < stepIdx);
            });
        }

        function setStatus(msg, kind) {
            $stat.textContent = msg || '';
            $stat.className = 'tt-cw-status' + (kind ? ' is-' + kind : '');
        }

        function renderStep() {
            setStatus('');
            var step = steps[stepIdx];
            $body.innerHTML = '';
            if (step === 'source') renderSourceStep();
            else if (step === 'columns') renderColumnsStep();
            else if (step === 'filters') renderFiltersStep();
            else if (step === 'format') renderFormatStep();
            else if (step === 'preview') renderPreviewStep();
            else if (step === 'save') renderSaveStep();
        }

        function renderSourceStep() {
            var h = document.createElement('h2');
            h.textContent = boot.i18n.pickSource;
            $body.appendChild(h);
            var p = document.createElement('p');
            p.className = 'tt-cw-step-help';
            p.textContent = 'Each source declares the columns and filters its widget can use.';
            $body.appendChild(p);

            var grid = document.createElement('div');
            grid.className = 'tt-cw-source-grid';
            boot.sources.forEach(function (src) {
                var card = document.createElement('label');
                card.className = 'tt-cw-source-card';
                if (state.sourceId === src.id) card.classList.add('is-active');
                card.innerHTML =
                    '<input type="radio" name="tt-cw-source" value="' + escAttr(src.id) + '"' +
                    (state.sourceId === src.id ? ' checked' : '') + '>' +
                    '<strong>' + escHtml(src.label) + '</strong>' +
                    '<span class="tt-cw-source-id">' + escHtml(src.id) + '</span>';
                card.addEventListener('click', function () {
                    state.sourceId = src.id;
                    state.columns = [];
                    state.filters = {};
                    state.aggregation = null;
                    grid.querySelectorAll('.tt-cw-source-card').forEach(function (c) { c.classList.remove('is-active'); });
                    card.classList.add('is-active');
                });
                grid.appendChild(card);
            });
            $body.appendChild(grid);
        }

        function renderColumnsStep() {
            var src = currentSource();
            var h = document.createElement('h2');
            h.textContent = boot.i18n.pickColumns;
            $body.appendChild(h);
            if (!src) { fallback(boot.i18n.requireSource); return; }

            var p = document.createElement('p');
            p.className = 'tt-cw-step-help';
            p.textContent = 'Pick the columns this widget should show. Required for table widgets; KPI / bar / line widgets ignore the column list.';
            $body.appendChild(p);

            var grid = document.createElement('div');
            grid.className = 'tt-cw-options';
            (src.columns || []).forEach(function (col) {
                var label = document.createElement('label');
                label.className = 'tt-cw-option';
                var checked = state.columns.indexOf(col.key) !== -1;
                label.innerHTML =
                    '<input type="checkbox" value="' + escAttr(col.key) + '"' + (checked ? ' checked' : '') + '> ' +
                    escHtml(col.label || col.key);
                label.querySelector('input').addEventListener('change', function (e) {
                    if (e.target.checked) {
                        if (state.columns.indexOf(col.key) === -1) state.columns.push(col.key);
                    } else {
                        state.columns = state.columns.filter(function (c) { return c !== col.key; });
                    }
                });
                grid.appendChild(label);
            });
            $body.appendChild(grid);
        }

        function renderFiltersStep() {
            var src = currentSource();
            var h = document.createElement('h2');
            h.textContent = boot.i18n.configureFilters;
            $body.appendChild(h);
            if (!src) { fallback(boot.i18n.requireSource); return; }

            var p = document.createElement('p');
            p.className = 'tt-cw-step-help';
            p.textContent = 'Filters are applied at fetch time; leave blank to show everything the source returns.';
            $body.appendChild(p);

            var filters = src.filters || [];
            if (!filters.length) {
                var none = document.createElement('p');
                none.style.color = '#5b6e75';
                none.textContent = 'This source does not declare any filters.';
                $body.appendChild(none);
                return;
            }
            filters.forEach(function (f) {
                var row = document.createElement('div');
                row.className = 'tt-cw-filter-row';
                var labelEl = document.createElement('label');
                labelEl.setAttribute('for', 'tt-cw-filter-' + f.key);
                labelEl.textContent = f.label || f.key;
                row.appendChild(labelEl);

                var input;
                if (f.kind === 'enum' && Array.isArray(f.options)) {
                    input = document.createElement('select');
                    var emptyOpt = document.createElement('option');
                    emptyOpt.value = '';
                    emptyOpt.textContent = '— ' + (f.label || f.key) + ' —';
                    input.appendChild(emptyOpt);
                    f.options.forEach(function (opt) {
                        var o = document.createElement('option');
                        o.value = opt.value !== undefined ? opt.value : opt;
                        o.textContent = opt.label !== undefined ? opt.label : opt;
                        if (state.filters[f.key] === o.value) o.selected = true;
                        input.appendChild(o);
                    });
                } else {
                    input = document.createElement('input');
                    input.type = (f.kind === 'date_range' || f.kind === 'date') ? 'text' : 'text';
                    input.placeholder = f.placeholder || '';
                    if (state.filters[f.key] !== undefined) input.value = state.filters[f.key];
                }
                input.id = 'tt-cw-filter-' + f.key;
                input.addEventListener('input', function () {
                    if (input.value === '') delete state.filters[f.key];
                    else state.filters[f.key] = input.value;
                });
                input.addEventListener('change', function () {
                    if (input.value === '') delete state.filters[f.key];
                    else state.filters[f.key] = input.value;
                });
                row.appendChild(input);
                $body.appendChild(row);
            });
        }

        function renderFormatStep() {
            var src = currentSource();
            var h = document.createElement('h2');
            h.textContent = boot.i18n.pickFormat;
            $body.appendChild(h);
            if (!src) { fallback(boot.i18n.requireSource); return; }

            var grid = document.createElement('div');
            grid.className = 'tt-cw-format-grid';
            Object.keys(boot.chartTypes).forEach(function (key) {
                var card = document.createElement('div');
                card.className = 'tt-cw-format-card';
                if (state.chartType === key) card.classList.add('is-active');
                card.textContent = boot.chartTypes[key];
                card.dataset.value = key;
                card.addEventListener('click', function () {
                    state.chartType = key;
                    if (key === 'table') state.aggregation = null;
                    grid.querySelectorAll('.tt-cw-format-card').forEach(function (c) { c.classList.remove('is-active'); });
                    card.classList.add('is-active');
                    renderAggSection();
                });
                grid.appendChild(card);
            });
            $body.appendChild(grid);

            var aggBox = document.createElement('div');
            aggBox.className = 'tt-cw-agg-box';
            $body.appendChild(aggBox);

            function renderAggSection() {
                aggBox.innerHTML = '';
                if (state.chartType === 'table' || !state.chartType) return;
                var aggs = src.aggregations || [];
                var label = document.createElement('h3');
                label.style.marginTop = '20px';
                label.textContent = boot.i18n.aggregation;
                aggBox.appendChild(label);
                if (!aggs.length) {
                    var none = document.createElement('p');
                    none.style.color = '#5b6e75';
                    none.textContent = boot.i18n.noAggregation;
                    aggBox.appendChild(none);
                    return;
                }
                var sel = document.createElement('select');
                sel.style.minHeight = '44px';
                sel.style.fontSize = '16px';
                var emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = '—';
                sel.appendChild(emptyOpt);
                aggs.forEach(function (a) {
                    var o = document.createElement('option');
                    o.value = a.key;
                    o.dataset.kind = a.kind || '';
                    o.dataset.column = a.column || '';
                    o.textContent = (a.label || a.key) + (a.kind ? ' (' + a.kind + ')' : '');
                    if (state.aggregation && state.aggregation.key === a.key) o.selected = true;
                    sel.appendChild(o);
                });
                sel.addEventListener('change', function () {
                    var picked = sel.options[sel.selectedIndex];
                    if (!picked.value) {
                        state.aggregation = null;
                        return;
                    }
                    state.aggregation = {
                        key: picked.value,
                        kind: picked.dataset.kind,
                        column: picked.dataset.column
                    };
                });
                aggBox.appendChild(sel);
            }
            renderAggSection();
        }

        function renderPreviewStep() {
            var h = document.createElement('h2');
            h.textContent = boot.i18n.preview;
            $body.appendChild(h);

            var p = document.createElement('p');
            p.className = 'tt-cw-step-help';
            p.textContent = 'Live preview against the actual data this widget will read on a dashboard. Save to deploy.';
            $body.appendChild(p);

            var box = document.createElement('div');
            box.className = 'tt-cw-preview';
            box.textContent = boot.i18n.previewLoading;
            $body.appendChild(box);

            previewIntoBox(box);
        }

        function previewIntoBox(box) {
            // The preview path needs a saved widget id (Phase 2's
            // /custom-widgets/{id}/data endpoint resolves by id-or-uuid),
            // so we save-or-update first as a draft, then fetch.
            saveDraft().then(function (saved) {
                if (!saved) {
                    box.textContent = boot.i18n.previewFailed;
                    return;
                }
                state.uuid = saved.uuid;
                fetch(boot.restRoot + '/custom-widgets/' + encodeURIComponent(saved.uuid) + '/data?limit=20', {
                    headers: { 'X-WP-Nonce': boot.restNonce }
                })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
                    .then(function (resp) {
                        if (!resp.ok) {
                            box.textContent = boot.i18n.previewFailed + ' ' + (resp.body.message || '');
                            return;
                        }
                        renderPreviewBody(box, resp.body);
                    })
                    .catch(function () {
                        box.textContent = boot.i18n.previewFailed;
                    });
            });
        }

        function renderPreviewBody(box, body) {
            box.innerHTML = '';
            var rows = body.rows || [];
            if (state.chartType === 'kpi') {
                var k = document.createElement('div');
                k.className = 'tt-cw-preview-kpi';
                var v = document.createElement('div');
                v.className = 'tt-cw-preview-value';
                var n = rows.length;
                if (rows.length === 1) {
                    var firstKey = Object.keys(rows[0])[0];
                    n = rows[0][firstKey];
                }
                v.textContent = (n === undefined || n === null) ? '—' : String(n);
                k.appendChild(v);
                var lab = document.createElement('div');
                lab.className = 'tt-cw-preview-label';
                lab.textContent = state.aggregation ? state.aggregation.key : '';
                k.appendChild(lab);
                box.appendChild(k);
                return;
            }
            if (!rows.length) {
                box.textContent = boot.i18n.noRows;
                return;
            }
            var tbl = document.createElement('table');
            tbl.className = 'tt-cw-preview-table';
            var keys = Object.keys(rows[0]);
            var thead = document.createElement('thead');
            var trH = document.createElement('tr');
            keys.forEach(function (k) {
                var th = document.createElement('th');
                th.textContent = k;
                trH.appendChild(th);
            });
            thead.appendChild(trH);
            tbl.appendChild(thead);
            var tbody = document.createElement('tbody');
            rows.forEach(function (row) {
                var tr = document.createElement('tr');
                keys.forEach(function (k) {
                    var td = document.createElement('td');
                    td.textContent = row[k] === null || row[k] === undefined ? '' : String(row[k]);
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            tbl.appendChild(tbody);
            box.appendChild(tbl);
        }

        function renderSaveStep() {
            var h = document.createElement('h2');
            h.textContent = boot.i18n.name;
            $body.appendChild(h);

            var form = document.createElement('div');
            form.className = 'tt-cw-save-form';

            var lblName = document.createElement('label');
            lblName.textContent = 'Name';
            var inpName = document.createElement('input');
            inpName.type = 'text';
            inpName.maxLength = 120;
            inpName.value = state.name || '';
            inpName.placeholder = 'e.g. Top 10 active players';
            inpName.addEventListener('input', function () { state.name = inpName.value; });
            lblName.appendChild(inpName);
            form.appendChild(lblName);

            var lblTtl = document.createElement('label');
            lblTtl.textContent = boot.i18n.cacheTtl;
            var inpTtl = document.createElement('input');
            inpTtl.type = 'number';
            inpTtl.min = '0';
            inpTtl.max = '1440';
            inpTtl.value = state.cacheTtlMinutes || 5;
            inpTtl.addEventListener('input', function () { state.cacheTtlMinutes = parseInt(inpTtl.value, 10) || 0; });
            lblTtl.appendChild(inpTtl);
            form.appendChild(lblTtl);

            $body.appendChild(form);
        }

        function fallback(msg) {
            var p = document.createElement('p');
            p.style.color = '#b32d2e';
            p.textContent = msg;
            $body.appendChild(p);
        }

        function currentSource() {
            return boot.sources.find(function (s) { return s.id === state.sourceId; });
        }

        function validateStep() {
            var step = steps[stepIdx];
            if (step === 'source' && !state.sourceId) return boot.i18n.requireSource;
            if (step === 'columns' && state.chartType === 'table' && state.columns.length === 0) return boot.i18n.requireColumns;
            if (step === 'format' && !state.chartType) return boot.i18n.requireFormat;
            if (step === 'format' && state.chartType !== 'table' && !state.aggregation) return boot.i18n.requireAgg;
            if (step === 'save' && (!state.name || state.name.trim() === '')) return boot.i18n.requireName;
            return null;
        }

        function nextStep() {
            var err = validateStep();
            if (err) { setStatus(err, 'error'); return; }
            if (stepIdx === steps.length - 1) {
                doSave();
                return;
            }
            setStep(stepIdx + 1);
        }

        function prevStep() { setStep(stepIdx - 1); }

        function buildPayload() {
            return {
                name: state.name || '',
                data_source_id: state.sourceId,
                chart_type: state.chartType,
                definition: {
                    columns: state.columns,
                    filters: state.filters,
                    aggregation: state.aggregation,
                    format: state.format || {},
                    cache_ttl_minutes: state.cacheTtlMinutes !== undefined ? state.cacheTtlMinutes : 5
                }
            };
        }

        function saveDraft() {
            // Used by the preview step. Saves silently if name empty
            // by inserting a placeholder; the operator's chosen name
            // will overwrite it on the explicit Save click.
            var payload = buildPayload();
            if (!payload.name) payload.name = '__draft__';
            return saveCall(payload).then(function (data) { return data; }, function () { return null; });
        }

        function doSave() {
            setStatus(boot.i18n.saving);
            var payload = buildPayload();
            saveCall(payload).then(function (data) {
                if (!data) return;
                setStatus(boot.i18n.saved, 'success');
                setTimeout(function () { window.location.href = boot.listUrl; }, 700);
            }, function (err) {
                setStatus(boot.i18n.saveFailed + ' ' + (err && err.message ? err.message : ''), 'error');
            });
        }

        function saveCall(payload) {
            var url = boot.restRoot + '/custom-widgets';
            var method = 'POST';
            if (state.uuid) {
                url = boot.restRoot + '/custom-widgets/' + encodeURIComponent(state.uuid);
                method = 'PUT';
            }
            return fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': boot.restNonce
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            }).then(function (r) {
                return r.json().then(function (j) {
                    if (!r.ok) return Promise.reject(j);
                    state.uuid = j.uuid || state.uuid;
                    return j;
                });
            });
        }

        $next.addEventListener('click', nextStep);
        $prev.addEventListener('click', prevStep);
        setStep(0);
    }

    function stateFromBootstrap(boot) {
        if (boot.widget) {
            var def = boot.widget.definition || {};
            return {
                uuid: boot.widget.uuid || null,
                sourceId: boot.widget.data_source_id || '',
                chartType: boot.widget.chart_type || '',
                name: boot.widget.name || '',
                columns: Array.isArray(def.columns) ? def.columns.slice() : [],
                filters: def.filters && typeof def.filters === 'object' ? Object.assign({}, def.filters) : {},
                aggregation: def.aggregation || null,
                format: def.format || {},
                cacheTtlMinutes: typeof def.cache_ttl_minutes === 'number' ? def.cache_ttl_minutes : 5
            };
        }
        return {
            uuid: null,
            sourceId: '',
            chartType: '',
            name: '',
            columns: [],
            filters: {},
            aggregation: null,
            format: {},
            cacheTtlMinutes: 5
        };
    }

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function escAttr(s) { return escHtml(s); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
