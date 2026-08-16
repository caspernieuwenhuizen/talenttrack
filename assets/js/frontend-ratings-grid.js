/**
 * Ratings grid (#2414, epic #2381).
 *
 * Dirty-cell tracking + one explicit Save, plus keyboard navigation across
 * the grid. Deliberately NOT autosave: a coach rating a squad on a flaky
 * connection gets one commit point, and Cancel means cancel.
 *
 * Every cell is one player × one category score, so a change is
 * `{ player_id, category_id, rating }` — nothing derived, nothing merged
 * client-side.
 */
(function () {
    'use strict';

    var cfg = window.TTRatingsGrid || {};
    var i18n = cfg.i18n || {};
    var t = function (k, fallback) { return i18n[k] || fallback; };

    var grid = document.querySelector('[data-tt-rgrid]');
    if (!grid) return;

    var rest = grid.getAttribute('data-rest') || '';
    var nonce = grid.getAttribute('data-nonce') || '';
    var status = grid.querySelector('[data-tt-rgrid-status]');
    var saveBtn = grid.querySelector('.tt-save-btn');
    var inputs = Array.prototype.slice.call(grid.querySelectorAll('.tt-rgrid-input'));

    // key "playerId:categoryId" -> value, for cells changed since load.
    var dirty = {};

    function cellKey(input) {
        return input.getAttribute('data-player-id') + ':' + input.getAttribute('data-category-id');
    }

    function setStatus(text) {
        if (status) status.textContent = text || '';
    }

    function dirtyCount() {
        return Object.keys(dirty).length;
    }

    function announceDirty() {
        var n = dirtyCount();
        setStatus(n ? t('unsaved', '%d unsaved change(s)').replace('%d', n) : '');
        if (saveBtn) saveBtn.disabled = n === 0;
    }

    inputs.forEach(function (input) {
        input.setAttribute('data-initial', input.value);

        input.addEventListener('input', function () {
            var key = cellKey(input);
            if (input.value === input.getAttribute('data-initial')) {
                delete dirty[key];
            } else {
                dirty[key] = {
                    player_id: parseInt(input.getAttribute('data-player-id'), 10),
                    category_id: parseInt(input.getAttribute('data-category-id'), 10),
                    rating: input.value
                };
            }
            input.classList.toggle('is-dirty', Object.prototype.hasOwnProperty.call(dirty, key));
            announceDirty();
        });

        // Arrow / Enter navigation across the grid. Enter moves DOWN a row
        // (the direction a coach rates in: one category at a time down the
        // squad), arrows move as drawn.
        input.addEventListener('keydown', function (e) {
            var move = 0;
            var byRow = 0;
            if (e.key === 'ArrowRight') move = 1;
            else if (e.key === 'ArrowLeft') move = -1;
            else if (e.key === 'ArrowDown' || e.key === 'Enter') byRow = 1;
            else if (e.key === 'ArrowUp') byRow = -1;
            else return;

            // Let the arrows adjust the number when the user means to.
            if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && e.altKey) return;

            e.preventDefault();
            var idx = inputs.indexOf(input);
            var perRow = grid.querySelectorAll('thead th').length - 1;
            var next = idx + (byRow ? byRow * perRow : move);
            if (next >= 0 && next < inputs.length) inputs[next].focus();
        });
    });

    announceDirty();

    window.addEventListener('beforeunload', function (e) {
        if (!dirtyCount()) return;
        e.preventDefault();
        e.returnValue = '';
    });

    if (saveBtn) {
        saveBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!dirtyCount() || !rest) return;

            var changes = Object.keys(dirty).map(function (k) { return dirty[k]; });
            saveBtn.disabled = true;
            setStatus(t('saving', 'Saving…'));

            fetch(rest, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify({ changes: changes })
            }).then(function (res) {
                return res.json().then(function (body) { return { status: res.status, body: body }; });
            }).then(function (r) {
                if (r.status < 200 || r.status >= 300 || !r.body || r.body.success === false) {
                    saveBtn.disabled = false;
                    var msg = (r.body && r.body.errors && r.body.errors[0] && r.body.errors[0].message) || '';
                    setStatus(msg || t('error', 'Could not save — try again'));
                    return;
                }
                // Committed: the saved values become the new baseline, so a
                // second Save doesn't re-send them.
                inputs.forEach(function (input) {
                    input.setAttribute('data-initial', input.value);
                    input.classList.remove('is-dirty');
                });
                dirty = {};
                announceDirty();
                setStatus(t('saved', 'All changes saved'));
            }).catch(function () {
                saveBtn.disabled = false;
                setStatus(t('network', 'Network error — try again'));
            });
        });
    }
})();
