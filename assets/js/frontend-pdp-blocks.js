/**
 * TalentTrack — PDP cycle blocks configurator (v3.110.191).
 *
 * Pilot ask: "the blocks chosen (2 or 3 or 4) by dates should be
 * shown visibly on a year timeline as blocks and there should be a
 * message if there is any overlap (unwanted) or dates not assigned
 * to a block (unwanted). the blocks should also not pass season
 * boundaries."
 *
 * Behaviour:
 *   - Season picker swap → re-hydrate inputs + timeline from the
 *     server-side payload (one JSON blob with every season's data).
 *   - Size radio (2 / 3 / 4) → add / remove date-pair rows.
 *   - Each input change → re-validate + re-render timeline.
 *   - Submit → PUT /pdp-blocks?season_id=N with the current rows.
 *
 * The timeline is an SVG spanning the season's start → end. Each
 * block lands as a coloured rectangle. Month gridlines underneath.
 * Validation surfaces overlap / gap / boundary issues as text rows.
 */
(function () {
    'use strict';
    if (typeof window.TT_PDP_BLOCKS === 'undefined') return;

    var cfg = window.TT_PDP_BLOCKS;
    var i18n = cfg.i18n || {};

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('tt-pdp-blocks-form');
        if (!form) return;

        var payloadEl = document.querySelector('[data-tt-pdp-blocks-payload]');
        if (!payloadEl) return;
        var payload;
        try { payload = JSON.parse(payloadEl.textContent || '{}'); } catch (e) { return; }
        var seasons = payload.seasons || [];
        if (!seasons.length) return;

        var seasonSel  = form.querySelector('[data-tt-pdp-blocks-season]');
        var sizeRadios = form.querySelectorAll('[data-tt-pdp-blocks-size]');
        var rowsHost   = form.querySelector('[data-tt-pdp-blocks-rows]');
        var timeline   = form.querySelector('[data-tt-pdp-blocks-timeline]');
        var axis       = form.querySelector('[data-tt-pdp-blocks-axis]');
        var messages   = form.querySelector('[data-tt-pdp-blocks-messages]');
        var saveBtn    = form.querySelector('[data-tt-pdp-blocks-save]');
        var saveMsg    = form.querySelector('[data-tt-pdp-blocks-msg]');

        var state = {
            seasonId: parseInt(seasonSel.value, 10) || (seasons[0] && seasons[0].id),
            size: 3,
            rows: []
        };

        function seasonById(id) {
            for (var i = 0; i < seasons.length; i++) {
                if (seasons[i].id === id) return seasons[i];
            }
            return null;
        }

        function hydrateFromSeason() {
            var s = seasonById(state.seasonId);
            if (!s) return;
            var saved = s.blocks || [];
            if (saved.length >= 2 && saved.length <= 4) {
                state.size = saved.length;
                state.rows = saved.slice()
                    .sort(function (a, b) { return a.sequence - b.sequence; })
                    .map(function (b) { return { start: b.start_date, end: b.end_date }; });
            } else {
                state.size = 3;
                state.rows = defaultRowsFor(s, 3);
            }
            renderSizeRadio();
            renderRows();
            renderTimeline();
            renderMessages();
        }

        function defaultRowsFor(season, n) {
            var s = Date.parse(season.start_date);
            var e = Date.parse(season.end_date);
            if (isNaN(s) || isNaN(e) || e <= s) return [];
            var sliceMs = (e - s) / n;
            var out = [];
            for (var i = 0; i < n; i++) {
                var blockStart = s + i * sliceMs;
                var blockEnd   = s + (i + 1) * sliceMs - 86400000;
                out.push({ start: ymd(new Date(blockStart)), end: ymd(new Date(blockEnd)) });
            }
            return out;
        }

        function ymd(d) {
            var y = d.getUTCFullYear();
            var m = String(d.getUTCMonth() + 1).padStart(2, '0');
            var day = String(d.getUTCDate()).padStart(2, '0');
            return y + '-' + m + '-' + day;
        }

        function renderSizeRadio() {
            Array.prototype.forEach.call(sizeRadios, function (r) {
                r.checked = (parseInt(r.value, 10) === state.size);
            });
        }

        var palette = ['#2271b1', '#1d7874', '#c9a227', '#7b2cbf'];

        function renderRows() {
            var s = seasonById(state.seasonId);
            if (!s) return;
            while (state.rows.length < state.size) {
                state.rows.push({ start: s.start_date, end: s.end_date });
            }
            state.rows.length = state.size;

            rowsHost.innerHTML = '';
            for (var i = 0; i < state.rows.length; i++) {
                var row = document.createElement('div');
                row.className = 'tt-pdp-blocks-row';
                var labelTpl = i18n.block_label || 'Block %d';
                var label = labelTpl.replace('%d', String(i + 1));
                var swatchColor = palette[i] || '#5b6e75';
                row.innerHTML =
                    '<span class="tt-pdp-blocks-row-label">' +
                        '<span class="tt-pdp-blocks-row-swatch" style="background:' + swatchColor + ';"></span>' +
                        escapeHtml(label) +
                    '</span>' +
                    '<label class="tt-pdp-blocks-row-field">' +
                        '<span>' + escapeHtml(i18n.from || 'From') + '</span>' +
                        '<input type="date" data-edge="start" data-row="' + i + '" value="' + escapeAttr(state.rows[i].start) + '" min="' + escapeAttr(s.start_date) + '" max="' + escapeAttr(s.end_date) + '" />' +
                    '</label>' +
                    '<label class="tt-pdp-blocks-row-field">' +
                        '<span>' + escapeHtml(i18n.to || 'To') + '</span>' +
                        '<input type="date" data-edge="end" data-row="' + i + '" value="' + escapeAttr(state.rows[i].end) + '" min="' + escapeAttr(s.start_date) + '" max="' + escapeAttr(s.end_date) + '" />' +
                    '</label>';
                rowsHost.appendChild(row);
            }
            rowsHost.querySelectorAll('input[type="date"]').forEach(function (input) {
                input.addEventListener('change', function () {
                    var idx = parseInt(input.getAttribute('data-row'), 10);
                    var edge = input.getAttribute('data-edge');
                    state.rows[idx][edge] = input.value;
                    renderTimeline();
                    renderMessages();
                });
            });
        }

        function renderTimeline() {
            var s = seasonById(state.seasonId);
            if (!s) { timeline.innerHTML = ''; return; }
            var seasonStart = Date.parse(s.start_date);
            var seasonEnd   = Date.parse(s.end_date);
            if (isNaN(seasonStart) || isNaN(seasonEnd) || seasonEnd <= seasonStart) {
                timeline.innerHTML = '';
                return;
            }
            var span = seasonEnd - seasonStart;
            var months = buildMonths(seasonStart, seasonEnd);
            var axisHtml = months.map(function (m) {
                var pct = ((m.ts - seasonStart) / span) * 100;
                return '<span class="tt-pdp-blocks-axis-tick" style="left:' + pct.toFixed(2) + '%;">' + m.label + '</span>';
            }).join('');
            axis.innerHTML = axisHtml;

            var blocksSvg = '';
            for (var i = 0; i < state.rows.length; i++) {
                var r = state.rows[i];
                var bs = Date.parse(r.start);
                var be = Date.parse(r.end);
                if (isNaN(bs) || isNaN(be) || be < bs) continue;
                var x1 = ((bs - seasonStart) / span) * 1000;
                var x2 = ((be - seasonStart) / span) * 1000;
                if (x1 < 0) x1 = 0;
                if (x2 > 1000) x2 = 1000;
                if (x2 <= x1) continue;
                var color = palette[i] || '#5b6e75';
                var laneY = 10 + i * 16;
                blocksSvg +=
                    '<rect x="' + x1.toFixed(2) + '" y="' + laneY + '" width="' + (x2 - x1).toFixed(2) + '" height="12" rx="3" fill="' + color + '">' +
                        '<title>' + escapeHtml(r.start + ' → ' + r.end) + '</title>' +
                    '</rect>';
            }
            var grid = '';
            months.forEach(function (m) {
                var x = ((m.ts - seasonStart) / span) * 1000;
                grid += '<line x1="' + x.toFixed(2) + '" y1="0" x2="' + x.toFixed(2) + '" y2="80" stroke="#e5e7ea" stroke-width="0.5" />';
            });
            timeline.innerHTML =
                '<svg viewBox="0 0 1000 80" preserveAspectRatio="none" class="tt-pdp-blocks-timeline-svg" aria-hidden="true">' +
                    grid +
                    '<rect x="0" y="0" width="1000" height="80" fill="none" stroke="#e5e7ea" />' +
                    blocksSvg +
                '</svg>';
        }

        function buildMonths(startTs, endTs) {
            var out = [];
            var d = new Date(startTs);
            var first = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));
            for (var ts = first.getTime(); ts <= endTs; ) {
                var dt = new Date(ts);
                if (ts >= startTs) {
                    out.push({ ts: ts, label: monthLabel(dt) });
                }
                var ny = dt.getUTCFullYear();
                var nm = dt.getUTCMonth() + 1;
                if (nm > 11) { ny += 1; nm = 0; }
                ts = Date.UTC(ny, nm, 1);
            }
            return out;
        }

        function monthLabel(d) {
            var names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return names[d.getUTCMonth()];
        }

        function renderMessages() {
            var s = seasonById(state.seasonId);
            if (!s) { messages.innerHTML = ''; return; }
            var problems = validate(s, state.rows);
            if (!problems.length) {
                messages.innerHTML = '<div class="tt-pdp-blocks-msg-row tt-pdp-blocks-msg-ok">' +
                    escapeHtml(i18n.msg_no_issues || 'All dates inside the season; no overlaps; no gaps.') +
                    '</div>';
                if (saveBtn) saveBtn.disabled = false;
                return;
            }
            messages.innerHTML = problems.map(function (p) {
                return '<div class="tt-pdp-blocks-msg-row tt-pdp-blocks-msg-' + p.severity + '">' + escapeHtml(p.text) + '</div>';
            }).join('');
            if (saveBtn) {
                var hasError = problems.some(function (p) { return p.severity === 'error'; });
                saveBtn.disabled = hasError;
            }
        }

        function validate(season, rows) {
            var out = [];
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                if (!r.start || !r.end) continue;
                if (r.end < r.start) {
                    out.push({ severity: 'error', text: (i18n.err_end_before || 'Block %d ends before it starts.').replace('%d', String(i + 1)) });
                }
                if (r.start < season.start_date || r.end > season.end_date) {
                    var tpl = i18n.err_outside_season || 'Block %1$d extends outside the season window (%2$s – %3$s).';
                    out.push({
                        severity: 'error',
                        text: tpl.replace('%1$d', String(i + 1)).replace('%2$s', season.start_date).replace('%3$s', season.end_date)
                    });
                }
            }
            var sorted = rows.map(function (r, idx) { return { idx: idx, start: r.start, end: r.end }; })
                .filter(function (r) { return r.start && r.end; })
                .sort(function (a, b) { return a.start.localeCompare(b.start); });
            for (var j = 1; j < sorted.length; j++) {
                if (sorted[j].start <= sorted[j - 1].end) {
                    var ot = i18n.err_overlap || 'Block %1$d overlaps with block %2$d.';
                    out.push({
                        severity: 'error',
                        text: ot.replace('%1$d', String(sorted[j - 1].idx + 1)).replace('%2$d', String(sorted[j].idx + 1))
                    });
                }
            }
            if (sorted.length >= 2) {
                for (var k = 1; k < sorted.length; k++) {
                    var prevEnd = nextDay(sorted[k - 1].end);
                    var thisStart = sorted[k].start;
                    if (prevEnd && prevEnd < thisStart) {
                        var gapEnd = prevDay(thisStart);
                        var gt = i18n.err_gap || '%1$s to %2$s is not covered by any block.';
                        out.push({
                            severity: 'warning',
                            text: gt.replace('%1$s', prevEnd).replace('%2$s', gapEnd)
                        });
                    }
                }
            }
            return out;
        }

        function nextDay(ymdStr) {
            var d = new Date(ymdStr + 'T00:00:00Z');
            if (isNaN(d.getTime())) return '';
            d.setUTCDate(d.getUTCDate() + 1);
            return ymd(d);
        }
        function prevDay(ymdStr) {
            var d = new Date(ymdStr + 'T00:00:00Z');
            if (isNaN(d.getTime())) return '';
            d.setUTCDate(d.getUTCDate() - 1);
            return ymd(d);
        }

        seasonSel.addEventListener('change', function () {
            state.seasonId = parseInt(seasonSel.value, 10);
            hydrateFromSeason();
        });
        sizeRadios.forEach(function (r) {
            r.addEventListener('change', function () {
                if (!r.checked) return;
                state.size = parseInt(r.value, 10);
                renderRows();
                renderTimeline();
                renderMessages();
            });
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            save();
        });

        function save() {
            if (!saveBtn) return;
            saveBtn.disabled = true;
            saveMsg.textContent = i18n.saving || 'Saving…';
            var body = {
                blocks: state.rows.map(function (r, i) {
                    return { sequence: i + 1, start_date: r.start, end_date: r.end };
                })
            };
            fetch(cfg.rest_root + '/pdp-blocks?season_id=' + state.seasonId, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify(body)
            }).then(function (r) {
                return r.json().then(function (json) { return { ok: r.ok, status: r.status, json: json }; });
            }).then(function (res) {
                if (!res.ok || !res.json || res.json.success === false) {
                    var msg = (res.json && res.json.errors && res.json.errors[0] && res.json.errors[0].message)
                              || i18n.save_failed || 'Could not save. Try again.';
                    saveMsg.textContent = msg;
                    saveBtn.disabled = false;
                    return;
                }
                saveMsg.textContent = i18n.saved || 'Blocks saved.';
                saveBtn.disabled = false;
                var s = seasonById(state.seasonId);
                if (s) {
                    s.blocks = state.rows.map(function (r, i) {
                        return { sequence: i + 1, start_date: r.start, end_date: r.end };
                    });
                }
            }).catch(function () {
                saveMsg.textContent = i18n.save_failed || 'Could not save. Try again.';
                saveBtn.disabled = false;
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function escapeAttr(s) { return escapeHtml(s); }

        hydrateFromSeason();
    });
})();
