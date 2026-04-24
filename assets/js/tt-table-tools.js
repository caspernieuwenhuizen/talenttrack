/*
 * TalentTrack — client-side table tools (v3.6.0)
 *
 * Vanilla JS. No build step, no dependencies. Progressive enhancement:
 * if the script fails to load, tables still render and remain usable.
 *
 * Opt-in by adding CSS class `tt-table-sortable` to a `<table>`.
 * The script:
 *   - Adds a search input above the table (unless the table is
 *     annotated with `data-tt-table-search="off"`).
 *   - Makes every `<th>` sortable on click. Columns can opt out by
 *     adding `data-tt-sort="off"` to the `<th>`.
 *   - Auto-detects column type (number, date, text) from the first
 *     non-empty body cell. Override via `data-tt-sort-type="number"` /
 *     `"date"` / `"text"` on the `<th>`.
 *   - Stable sort (preserves insertion order within equal keys).
 *   - Filters rows by search text against all visible cells. Diacritic
 *     insensitive for locales like `nl_NL` ("ë" matches "e").
 *
 * Everything is self-scoped — no globals except `window.ttTableTools`
 * as an escape hatch for manual re-init after AJAX inserts a new
 * table.
 */

(function () {
    'use strict';

    var STRINGS = (window.ttTableToolsStrings && typeof window.ttTableToolsStrings === 'object')
        ? window.ttTableToolsStrings
        : {};

    function t(key, fallback) {
        return (STRINGS[key] && typeof STRINGS[key] === 'string') ? STRINGS[key] : fallback;
    }

    function normalize(s) {
        if (s == null) return '';
        var str = String(s).toLowerCase();
        if (typeof str.normalize === 'function') {
            return str.normalize('NFD').replace(/[̀-ͯ]/g, '');
        }
        return str;
    }

    function detectType(table, colIdx) {
        var body = table.tBodies[0];
        if (!body) return 'text';
        for (var i = 0; i < body.rows.length && i < 20; i++) {
            var cell = body.rows[i].cells[colIdx];
            if (!cell) continue;
            var txt = (cell.textContent || '').trim();
            if (!txt || txt === '—' || txt === '-') continue;
            // ISO-ish date YYYY-MM-DD takes priority
            if (/^\d{4}-\d{2}-\d{2}/.test(txt)) return 'date';
            // Numeric: allow commas, %, currency prefixes like "#"
            var numTest = txt.replace(/[,#]/g, '').replace(/%/g, '').trim();
            if (numTest && !isNaN(parseFloat(numTest)) && isFinite(numTest)) return 'number';
            return 'text';
        }
        return 'text';
    }

    function compareFactory(type) {
        if (type === 'number') {
            return function (a, b) {
                var na = parseFloat(String(a).replace(/[,#%]/g, ''));
                var nb = parseFloat(String(b).replace(/[,#%]/g, ''));
                if (isNaN(na)) na = -Infinity;
                if (isNaN(nb)) nb = -Infinity;
                return na - nb;
            };
        }
        if (type === 'date') {
            return function (a, b) {
                var da = Date.parse(a) || 0;
                var db = Date.parse(b) || 0;
                return da - db;
            };
        }
        return function (a, b) {
            var na = normalize(a);
            var nb = normalize(b);
            if (na < nb) return -1;
            if (na > nb) return 1;
            return 0;
        };
    }

    function sortBy(table, colIdx, type, direction) {
        var body = table.tBodies[0];
        if (!body) return;
        var rows = Array.prototype.slice.call(body.rows);
        var cmp = compareFactory(type);
        // Stable sort via index tag
        rows.forEach(function (r, i) { r.__idx = i; });
        rows.sort(function (a, b) {
            var ca = (a.cells[colIdx] && a.cells[colIdx].textContent) ? a.cells[colIdx].textContent.trim() : '';
            var cb = (b.cells[colIdx] && b.cells[colIdx].textContent) ? b.cells[colIdx].textContent.trim() : '';
            var primary = cmp(ca, cb);
            if (primary !== 0) return direction === 'desc' ? -primary : primary;
            return a.__idx - b.__idx;
        });
        rows.forEach(function (r) { body.appendChild(r); });
    }

    function filterTable(table, needle) {
        var body = table.tBodies[0];
        if (!body) return 0;
        var n = normalize(needle);
        var visibleCount = 0;
        for (var i = 0; i < body.rows.length; i++) {
            var row = body.rows[i];
            if (row.getAttribute('data-tt-table-noop') === 'true') continue;
            if (n === '') {
                row.style.display = '';
                visibleCount++;
                continue;
            }
            var haystack = '';
            for (var j = 0; j < row.cells.length; j++) {
                var c = row.cells[j];
                if (c && c.getAttribute && c.getAttribute('data-tt-search') === 'skip') continue;
                haystack += (c ? (c.textContent || '') : '') + ' ';
            }
            if (normalize(haystack).indexOf(n) !== -1) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
        return visibleCount;
    }

    function attachHeaderSort(table) {
        var headRow = (table.tHead && table.tHead.rows[0]) ? table.tHead.rows[0] : null;
        if (!headRow) return;
        var headers = headRow.cells;
        for (var i = 0; i < headers.length; i++) {
            (function (th, idx) {
                if (th.getAttribute('data-tt-sort') === 'off') return;
                var type = th.getAttribute('data-tt-sort-type') || detectType(table, idx);
                th.setAttribute('data-tt-sort-type', type);
                th.style.cursor = 'pointer';
                th.style.userSelect = 'none';
                th.setAttribute('role', 'button');
                th.setAttribute('tabindex', '0');

                // Sort indicator
                if (!th.querySelector('.tt-sort-indicator')) {
                    var ind = document.createElement('span');
                    ind.className = 'tt-sort-indicator';
                    ind.setAttribute('aria-hidden', 'true');
                    ind.style.marginLeft = '4px';
                    ind.style.color = '#a0a6ae';
                    ind.style.fontSize = '0.85em';
                    ind.textContent = '↕';
                    th.appendChild(ind);
                }

                function doSort() {
                    var currentDir = th.getAttribute('data-tt-sort-dir');
                    var nextDir = currentDir === 'asc' ? 'desc' : 'asc';
                    // Clear other headers
                    for (var k = 0; k < headers.length; k++) {
                        if (headers[k] !== th) {
                            headers[k].removeAttribute('data-tt-sort-dir');
                            var otherInd = headers[k].querySelector('.tt-sort-indicator');
                            if (otherInd) { otherInd.textContent = '↕'; otherInd.style.color = '#a0a6ae'; }
                        }
                    }
                    th.setAttribute('data-tt-sort-dir', nextDir);
                    var ownInd = th.querySelector('.tt-sort-indicator');
                    if (ownInd) { ownInd.textContent = nextDir === 'asc' ? '▲' : '▼'; ownInd.style.color = '#2271b1'; }
                    sortBy(table, idx, type, nextDir);
                }

                th.addEventListener('click', doSort);
                th.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); doSort(); }
                });
            })(headers[i], i);
        }
    }

    function attachSearchBar(table) {
        if (table.getAttribute('data-tt-table-search') === 'off') return;
        // Prevent double-wrap on re-init
        if (table.previousElementSibling && table.previousElementSibling.classList &&
            table.previousElementSibling.classList.contains('tt-table-tools-bar')) {
            return;
        }

        var bar = document.createElement('div');
        bar.className = 'tt-table-tools-bar';
        bar.style.display = 'flex';
        bar.style.justifyContent = 'flex-end';
        bar.style.alignItems = 'center';
        bar.style.gap = '8px';
        bar.style.margin = '0 0 8px';
        bar.style.flexWrap = 'wrap';

        var countSpan = document.createElement('span');
        countSpan.className = 'tt-table-count';
        countSpan.style.fontSize = '12px';
        countSpan.style.color = '#666';

        var label = document.createElement('label');
        label.style.fontSize = '13px';
        label.style.color = '#4a5057';
        var labelText = document.createElement('span');
        labelText.textContent = t('search', 'Search:');
        labelText.style.marginRight = '6px';
        var input = document.createElement('input');
        input.type = 'search';
        input.className = 'tt-table-search';
        input.placeholder = t('searchPlaceholder', 'Filter rows…');
        input.style.padding = '4px 8px';
        input.style.fontSize = '13px';
        input.style.border = '1px solid #c3c4c7';
        input.style.borderRadius = '3px';
        input.style.minWidth = '180px';
        label.appendChild(labelText);
        label.appendChild(input);

        bar.appendChild(countSpan);
        bar.appendChild(label);
        table.parentNode.insertBefore(bar, table);

        function updateCount() {
            var body = table.tBodies[0];
            if (!body) return;
            var total = 0, visible = 0;
            for (var i = 0; i < body.rows.length; i++) {
                var row = body.rows[i];
                if (row.getAttribute('data-tt-table-noop') === 'true') continue;
                total++;
                if (row.style.display !== 'none') visible++;
            }
            if (total === visible) {
                countSpan.textContent = t('rowsTotal', '{n} row(s)').replace('{n}', total);
            } else {
                countSpan.textContent = t('rowsFiltered', '{v} of {n}').replace('{v}', visible).replace('{n}', total);
            }
        }

        input.addEventListener('input', function () {
            filterTable(table, input.value);
            updateCount();
        });
        updateCount();
    }

    function init(root) {
        root = root || document;
        var tables = root.querySelectorAll('table.tt-table-sortable');
        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];
            if (table.getAttribute('data-tt-table-initialized') === '1') continue;
            table.setAttribute('data-tt-table-initialized', '1');
            attachSearchBar(table);
            attachHeaderSort(table);
        }
    }

    window.ttTableTools = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
})();
