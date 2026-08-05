/**
 * frontend-minutes-audit-edit.js (#2367) — per-match minutes editor.
 *
 * Commits the recorded minutes per player for one game to the authoritative
 * tables the reports + match-execution read. Each dirty row writes on its
 * own so a single bad value never blocks the rest, and the write path is
 * routed per activity (the minutes-authority arbiter, #2301):
 *
 *   - owned by an execution  → PATCH /match-execution/{activity}/minutes
 *                              { player_id, minutes|null }  (the override;
 *                              survives recompute). An emptied field clears
 *                              the override (minutes: null).
 *   - no execution           → PATCH /attendance/{attendance_id}
 *                              { minutes_played }  (manual entry, #2159).
 *
 * Conservative with minors' match data: an emptied field is an EXPLICIT
 * clear (null override / null minutes_played), never a silent zero. Nothing
 * is written for a row the user did not touch.
 *
 * Vanilla JS + fetch(); nonce from the localized config. No framework.
 */
(function () {
    'use strict';

    var CFG = window.TTMinutesAuditEdit || {};
    var I18N = CFG.i18n || {};

    function restUrl(path) {
        var base = (CFG.restBase || '').replace(/\/+$/, '');
        return base + path;
    }

    function patch(path, body) {
        return fetch(restUrl(path), {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': CFG.nonce || ''
            },
            body: JSON.stringify(body)
        }).then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, status: res.status, data: data };
            }, function () {
                return { ok: res.ok, status: res.status, data: null };
            });
        });
    }

    /** Parse the input into an integer minutes value, or null when emptied. */
    function readMinutes(input) {
        var raw = (input.value || '').trim();
        if (raw === '') return null;
        var n = parseInt(raw, 10);
        if (isNaN(n) || n < 0) return 0;
        if (n > 200) return 200;
        return n;
    }

    function isDirty(input) {
        var original = input.getAttribute('data-original') || '';
        var current = (input.value || '').trim();
        return current !== original;
    }

    function setRowState(row, text, kind) {
        var cell = row.querySelector('.tt-maud-mins__state');
        if (!cell) return;
        cell.textContent = text || '';
        cell.setAttribute('data-kind', kind || '');
    }

    /** Write one row. Resolves to true on success, false on failure. */
    function writeRow(row, owned, activityId) {
        var input = row.querySelector('.tt-min-in');
        if (!input) return Promise.resolve(true);
        var minutes = readMinutes(input);
        var playerId = parseInt(row.getAttribute('data-player-id'), 10) || 0;
        var attendanceId = parseInt(row.getAttribute('data-attendance-id'), 10) || 0;

        setRowState(row, I18N.saving || 'Saving…', 'saving');

        var req;
        if (owned) {
            // Override endpoint. An emptied field clears the override (null).
            req = patch('/match-execution/' + activityId + '/minutes', {
                player_id: playerId,
                minutes: minutes
            });
        } else {
            if (attendanceId <= 0) {
                setRowState(row, I18N.noRosterRow || 'No roster row.', 'error');
                return Promise.resolve(false);
            }
            // Manual attendance path. minutes_played null = cleared.
            req = patch('/attendance/' + attendanceId, {
                minutes_played: minutes === null ? '' : minutes
            });
        }

        return req.then(function (r) {
            if (r.ok) {
                setRowState(row, I18N.saved || 'Saved', 'ok');
                input.setAttribute('data-original', minutes === null ? '' : String(minutes));
                row.classList.toggle('is-override', owned && minutes !== null);
                return true;
            }
            var code = (r.data && r.data.errors && r.data.errors[0] && r.data.errors[0].code) || '';
            var msg = I18N.saveError || 'Could not save.';
            if (code === 'minutes_owned_by_execution') msg = I18N.ownedByExec || msg;
            if (code === 'no_attendance_row') msg = I18N.noRosterRow || msg;
            setRowState(row, msg, 'error');
            return false;
        }).catch(function () {
            setRowState(row, I18N.saveError || 'Could not save.', 'error');
            return false;
        });
    }

    function onSubmit(form, evt) {
        evt.preventDefault();
        var activityId = parseInt(form.getAttribute('data-activity-id'), 10) || 0;
        var owned = form.getAttribute('data-owned') === '1';
        if (activityId <= 0) return;

        var saveBtn = form.querySelector('.tt-save-btn');
        var dirtyRows = [];
        form.querySelectorAll('.tt-maud-mins__row').forEach(function (row) {
            var input = row.querySelector('.tt-min-in');
            if (input && isDirty(input)) dirtyRows.push(row);
        });

        if (dirtyRows.length === 0) {
            if (saveBtn) saveBtn.setAttribute('data-state', 'saved');
            return;
        }

        if (saveBtn) saveBtn.setAttribute('data-state', 'saving');
        form.querySelectorAll('.tt-min-in').forEach(function (i) { i.disabled = true; });

        Promise.all(dirtyRows.map(function (row) {
            return writeRow(row, owned, activityId);
        })).then(function (results) {
            form.querySelectorAll('.tt-min-in').forEach(function (i) { i.disabled = false; });
            var allOk = results.every(function (ok) { return ok; });
            if (saveBtn) saveBtn.setAttribute('data-state', allOk ? 'saved' : 'error');
        });
    }

    function init() {
        var form = document.querySelector('form.tt-maud-edit');
        if (!form) return;
        form.addEventListener('submit', function (evt) { onSubmit(form, evt); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
