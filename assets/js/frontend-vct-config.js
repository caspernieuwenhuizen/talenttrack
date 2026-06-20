/**
 * TalentTrack — VCT configuration tile (#1546).
 *
 * Two responsibilities:
 *
 *   1. Auto-load pickers. Any `<select data-tt-vct-autoload>` submits its
 *      enclosing GET form on change, so swapping season (or team on the
 *      Blocks tab) reloads the view server-side without a Load button.
 *
 *   2. Structured macro-block editor. Replaces the old JSON-paste box with
 *      a label + start-date + end-date repeater (add / remove / move up /
 *      down). Each block has an optional advanced phase-profile JSON
 *      textarea. Client-side it mirrors the server rules (names, dates,
 *      no overlaps) for instant feedback; the real guard is the
 *      `PUT /vct/macro-blocks` endpoint, which re-validates everything.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        bindAutoload();
        bindBlocksEditor();
    });

    function bindAutoload() {
        var selects = document.querySelectorAll('select[data-tt-vct-autoload]');
        Array.prototype.forEach.call(selects, function (sel) {
            sel.addEventListener('change', function () {
                var form = sel.closest('form');
                if (form) form.submit();
            });
        });
    }

    function bindBlocksEditor() {
        if (typeof window.TT_VCT_CONFIG === 'undefined') return;
        var form = document.getElementById('tt-vct-blocks-form');
        if (!form) return;

        var cfg = window.TT_VCT_CONFIG;
        var i18n = cfg.i18n || {};

        var payloadEl = document.querySelector('[data-tt-vct-blocks-payload]');
        var payload = { season_id: 0, team_id: 0, blocks: [] };
        if (payloadEl) {
            try { payload = JSON.parse(payloadEl.textContent || '{}'); } catch (e) { /* keep default */ }
        }

        var rowsHost = form.querySelector('[data-tt-vct-rows]');
        var addBtn   = form.querySelector('[data-tt-vct-add]');
        var messages = form.querySelector('[data-tt-vct-messages]');
        var saveBtn  = form.querySelector('[data-tt-vct-save]');
        var saveMsg  = form.querySelector('[data-tt-vct-msg]');

        var state = {
            seasonId: parseInt(payload.season_id, 10) || 0,
            teamId: parseInt(payload.team_id, 10) || 0,
            rows: (payload.blocks || []).slice()
                .sort(function (a, b) { return (a.sequence || 0) - (b.sequence || 0); })
                .map(function (b) {
                    return {
                        label: b.label || '',
                        start: b.start_date || '',
                        end: b.end_date || '',
                        phase: (b.phase_profile && b.phase_profile.length)
                            ? JSON.stringify(b.phase_profile)
                            : ''
                    };
                })
        };

        function renderRows() {
            rowsHost.innerHTML = '';
            if (!state.rows.length) {
                var empty = document.createElement('p');
                empty.className = 'tt-empty';
                empty.textContent = i18n.empty || 'No macro-blocks yet.';
                rowsHost.appendChild(empty);
                renderMessages();
                return;
            }
            for (var i = 0; i < state.rows.length; i++) {
                rowsHost.appendChild(buildRow(i));
            }
            renderMessages();
        }

        function buildRow(i) {
            var r = state.rows[i];
            var labelTpl = i18n.block_label || 'Block %d';
            var heading = labelTpl.replace('%d', String(i + 1));

            var row = document.createElement('div');
            row.className = 'tt-vct-block-row';
            row.innerHTML =
                '<div class="tt-vct-block-head">' +
                    '<span class="tt-vct-block-seq">' + escapeHtml(heading) + '</span>' +
                    '<div class="tt-vct-block-tools">' +
                        '<button type="button" class="tt-btn tt-btn-icon" data-move="up" aria-label="' + escapeAttr(i18n.move_up || 'Move up') + '" title="' + escapeAttr(i18n.move_up || 'Move up') + '">&#8593;</button>' +
                        '<button type="button" class="tt-btn tt-btn-icon" data-move="down" aria-label="' + escapeAttr(i18n.move_down || 'Move down') + '" title="' + escapeAttr(i18n.move_down || 'Move down') + '">&#8595;</button>' +
                        '<button type="button" class="tt-btn tt-btn-icon tt-vct-remove" data-remove aria-label="' + escapeAttr(i18n.remove || 'Remove') + '" title="' + escapeAttr(i18n.remove || 'Remove') + '">&times;</button>' +
                    '</div>' +
                '</div>' +
                '<div class="tt-vct-block-fields">' +
                    '<label class="tt-field tt-vct-block-name">' +
                        '<span class="tt-field-label">' + escapeHtml(i18n.name || 'Name') + '</span>' +
                        '<input class="tt-input" type="text" data-field="label" value="' + escapeAttr(r.label) + '" placeholder="' + escapeAttr(i18n.name_ph || '') + '" />' +
                    '</label>' +
                    '<label class="tt-field">' +
                        '<span class="tt-field-label">' + escapeHtml(i18n.from || 'From') + '</span>' +
                        '<input class="tt-input" type="date" data-field="start" value="' + escapeAttr(r.start) + '" />' +
                    '</label>' +
                    '<label class="tt-field">' +
                        '<span class="tt-field-label">' + escapeHtml(i18n.to || 'To') + '</span>' +
                        '<input class="tt-input" type="date" data-field="end" value="' + escapeAttr(r.end) + '" />' +
                    '</label>' +
                '</div>' +
                '<details class="tt-vct-block-advanced">' +
                    '<summary>' + escapeHtml(i18n.advanced || 'Advanced: weekly phase profile (JSON)') + '</summary>' +
                    '<textarea class="tt-input tt-vct-phase" data-field="phase" rows="4" spellcheck="false">' + escapeHtml(r.phase) + '</textarea>' +
                    '<p class="tt-field-hint">' + escapeHtml(i18n.phase_hint || '') + '</p>' +
                '</details>';

            row.querySelectorAll('[data-field]').forEach(function (input) {
                var field = input.getAttribute('data-field');
                input.addEventListener('input', function () {
                    state.rows[i][field] = input.value;
                    if (field !== 'phase') renderMessages();
                });
                input.addEventListener('change', function () {
                    state.rows[i][field] = input.value;
                    renderMessages();
                });
            });
            row.querySelector('[data-remove]').addEventListener('click', function () {
                state.rows.splice(i, 1);
                renderRows();
            });
            row.querySelector('[data-move="up"]').addEventListener('click', function () {
                if (i === 0) return;
                var tmp = state.rows[i - 1];
                state.rows[i - 1] = state.rows[i];
                state.rows[i] = tmp;
                renderRows();
            });
            row.querySelector('[data-move="down"]').addEventListener('click', function () {
                if (i >= state.rows.length - 1) return;
                var tmp = state.rows[i + 1];
                state.rows[i + 1] = state.rows[i];
                state.rows[i] = tmp;
                renderRows();
            });
            return row;
        }

        function validate() {
            var out = [];
            for (var i = 0; i < state.rows.length; i++) {
                var r = state.rows[i];
                if (!r.label || !r.label.trim()) {
                    out.push((i18n.err_no_name || 'Block %d needs a name.').replace('%d', String(i + 1)));
                }
                if (!r.start || !r.end) {
                    out.push((i18n.err_no_dates || 'Block %d needs dates.').replace('%d', String(i + 1)));
                } else if (r.end < r.start) {
                    out.push((i18n.err_end_before || 'Block %d ends before it starts.').replace('%d', String(i + 1)));
                }
            }
            var sorted = state.rows.map(function (r, idx) { return { idx: idx, start: r.start, end: r.end }; })
                .filter(function (r) { return r.start && r.end; })
                .sort(function (a, b) { return a.start.localeCompare(b.start); });
            for (var j = 1; j < sorted.length; j++) {
                if (sorted[j].start <= sorted[j - 1].end) {
                    out.push((i18n.err_overlap || 'Block %1$d overlaps with block %2$d.')
                        .replace('%1$d', String(sorted[j - 1].idx + 1))
                        .replace('%2$d', String(sorted[j].idx + 1)));
                }
            }
            return out;
        }

        function renderMessages() {
            if (!state.rows.length) {
                messages.innerHTML = '';
                if (saveBtn) saveBtn.disabled = false;
                return;
            }
            var problems = validate();
            if (!problems.length) {
                messages.innerHTML = '<div class="tt-vct-msg tt-vct-msg-ok">' +
                    escapeHtml(i18n.msg_ok || 'Looks good.') + '</div>';
                if (saveBtn) saveBtn.disabled = false;
                return;
            }
            messages.innerHTML = problems.map(function (p) {
                return '<div class="tt-vct-msg tt-vct-msg-error">' + escapeHtml(p) + '</div>';
            }).join('');
            if (saveBtn) saveBtn.disabled = true;
        }

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                state.rows.push({ label: '', start: '', end: '', phase: '' });
                renderRows();
                var inputs = rowsHost.querySelectorAll('.tt-vct-block-name input');
                if (inputs.length) inputs[inputs.length - 1].focus();
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            save();
        });

        function save() {
            if (!saveBtn) return;
            if (!state.rows.length) {
                saveMsg.textContent = i18n.need_one || 'Add at least one block.';
                return;
            }
            var problems = validate();
            if (problems.length) {
                renderMessages();
                return;
            }

            var blocks = [];
            for (var i = 0; i < state.rows.length; i++) {
                var r = state.rows[i];
                var phase = [];
                if (r.phase && r.phase.trim()) {
                    try {
                        var parsed = JSON.parse(r.phase);
                        phase = Array.isArray(parsed) ? parsed : [];
                    } catch (err) {
                        saveMsg.textContent = (i18n.bad_json || 'Invalid JSON in block %d.').replace('%d', String(i + 1));
                        return;
                    }
                }
                blocks.push({
                    sequence: i + 1,
                    label: r.label.trim(),
                    start_date: r.start,
                    end_date: r.end,
                    phase_profile: phase
                });
            }

            saveBtn.disabled = true;
            saveMsg.textContent = i18n.saving || 'Saving…';

            var url = cfg.rest_root + '/vct/macro-blocks?season_id=' + state.seasonId + '&team_id=' + state.teamId;
            fetch(url, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({ season_id: state.seasonId, team_id: state.teamId, blocks: blocks })
            }).then(function (resp) {
                return resp.json().then(function (json) { return { ok: resp.ok, json: json }; });
            }).then(function (res) {
                if (!res.ok || !res.json || res.json.success === false) {
                    var msg = (res.json && res.json.errors && res.json.errors[0] && res.json.errors[0].message)
                        || i18n.save_failed || 'Could not save.';
                    saveMsg.textContent = msg;
                    saveBtn.disabled = false;
                    return;
                }
                saveMsg.textContent = i18n.saved || 'Saved.';
                saveBtn.disabled = false;
            }).catch(function () {
                saveMsg.textContent = i18n.save_failed || 'Could not save.';
                saveBtn.disabled = false;
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function escapeAttr(s) { return escapeHtml(s); }

        renderRows();
    }
})();
