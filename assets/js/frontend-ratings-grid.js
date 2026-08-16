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
 *
 * Scores are validated against the configured scale before Save is allowed
 * (#2431). The `min` / `max` / `step` attributes on each cell are inert on
 * their own here — the grid submits with fetch() rather than a native form
 * submit, so the browser's constraint validation never runs and an
 * out-of-range score would otherwise sail through to a server that drops it.
 */
(function () {
    'use strict';

    var cfg = window.TTRatingsGrid || {};
    var i18n = cfg.i18n || {};
    var t = function (k, fallback) { return i18n[k] || fallback; };

    // Fills %s / %d and positional %1$s placeholders coming from PHP's
    // sprintf, so the translated string keeps control of argument order.
    function fmt(str, args) {
        var out = String(str);
        args.forEach(function (v, i) {
            out = out.split('%' + (i + 1) + '$s').join(v);
        });
        return out.replace(/%[sd]/, args[0]);
    }

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

    /**
     * Why the score is unacceptable, or null when it is fine. A blank cell
     * is always fine — blank means "not rated", which is a legitimate
     * outcome the server writes nothing for.
     */
    function invalidReason(input) {
        var raw = input.value;
        if (raw === '') return null;

        // A number input holding unparseable text reports an empty value,
        // so badInput is the only way to see "7..5" was typed.
        if (input.validity && input.validity.badInput) {
            return fmt(t('range', 'Score must be between %1$s and %2$s'),
                [input.getAttribute('min'), input.getAttribute('max')]);
        }

        var val = parseFloat(raw);
        if (isNaN(val)) {
            return fmt(t('range', 'Score must be between %1$s and %2$s'),
                [input.getAttribute('min'), input.getAttribute('max')]);
        }

        var min = parseFloat(input.getAttribute('min'));
        var max = parseFloat(input.getAttribute('max'));
        if (!isNaN(min) && val < min) {
            return fmt(t('range', 'Score must be between %1$s and %2$s'),
                [input.getAttribute('min'), input.getAttribute('max')]);
        }
        if (!isNaN(max) && val > max) {
            return fmt(t('range', 'Score must be between %1$s and %2$s'),
                [input.getAttribute('min'), input.getAttribute('max')]);
        }

        // Step is measured from the scale's floor, not from zero: a 5–10
        // scale in half points allows 5.5, and would allow 5.25 if the
        // offset were ignored. The epsilon absorbs float representation
        // noise (0.1 + 0.2 arithmetic), not a genuinely off-step score.
        var step = parseFloat(input.getAttribute('step'));
        if (!isNaN(step) && step > 0) {
            var base = isNaN(min) ? 0 : min;
            var offset = (val - base) / step;
            if (Math.abs(offset - Math.round(offset)) > 0.001) {
                return fmt(t('step', 'Score must be in steps of %s'), [input.getAttribute('step')]);
            }
        }

        return null;
    }

    /**
     * Flag or clear one cell. The typed value is never rewritten — a coach
     * who meant to type 12 should see 12 and be told why it can't be saved,
     * not find it silently clamped to 10.
     */
    function applyValidity(input) {
        var reason = invalidReason(input);
        input.classList.toggle('is-invalid', reason !== null);
        if (reason !== null) {
            input.setAttribute('aria-invalid', 'true');
        } else {
            input.removeAttribute('aria-invalid');
        }
        return reason;
    }

    function invalidInputs() {
        return inputs.filter(function (i) { return i.classList.contains('is-invalid'); });
    }

    function refresh() {
        var bad = invalidInputs();
        var n = dirtyCount();

        // Blocking Save is what makes the server-side refusal unreachable
        // from the UI: an invalid score never gets the chance to come back
        // as a silent no-op.
        if (saveBtn) saveBtn.disabled = n === 0 || bad.length > 0;

        if (bad.length) {
            var first = invalidReason(bad[0]);
            setStatus(bad.length === 1
                ? first
                : fmt(t('blocked', '%d score(s) out of range — fix the highlighted cells to save'), [bad.length]));
            return;
        }
        setStatus(n ? fmt(t('unsaved', '%d unsaved change(s)'), [n]) : '');
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
            applyValidity(input);
            refresh();
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

    // Scores already stored can fall outside a scale that was narrowed
    // after the fact. Flag them on load so the state is honest, but leave
    // them clean — the coach hasn't touched them, and Save only sends
    // dirty cells.
    inputs.forEach(applyValidity);
    refresh();

    window.addEventListener('beforeunload', function (e) {
        if (!dirtyCount()) return;
        e.preventDefault();
        e.returnValue = '';
    });

    if (saveBtn) {
        saveBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!dirtyCount() || !rest) return;
            if (invalidInputs().length) { refresh(); return; }

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

                // A 2xx is not proof every cell landed. The server reports
                // the ones it refused, and those must stay dirty — promoting
                // a refused value to the saved baseline is what made the
                // loss invisible and unrecoverable by a second Save (#2431).
                var data = r.body.data || {};
                var refused = {};
                (data.rejected || []).forEach(function (x) {
                    refused[x.player_id + ':' + x.category_id] = true;
                });

                inputs.forEach(function (input) {
                    var key = cellKey(input);
                    if (refused[key]) {
                        input.classList.add('is-invalid');
                        input.setAttribute('aria-invalid', 'true');
                        return;
                    }
                    input.setAttribute('data-initial', input.value);
                    input.classList.remove('is-dirty');
                    delete dirty[key];
                });

                // refresh() owns the button state; the status line is then
                // overridden, because "3 refused" is the thing the coach
                // needs to read, not the per-cell reason.
                var n = Object.keys(refused).length;
                refresh();
                setStatus(n
                    ? fmt(t('rejected', '%d score(s) were refused and NOT saved — the highlighted cells are still unsaved'), [n])
                    : t('saved', 'All changes saved'));
            }).catch(function () {
                saveBtn.disabled = false;
                setStatus(t('network', 'Network error — try again'));
            });
        });
    }
})();
