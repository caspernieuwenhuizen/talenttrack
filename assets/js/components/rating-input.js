/* ---------------------------------------------------------------------------
 * RatingInputComponent JS — chip selection + slider readout binding.
 * Mockup of record: .local-mockups/player-rating-input/ (#1067).
 *
 * Two component shapes share this file:
 *
 *   [data-tt-rating-input]     — single chip grid. Tap a chip to set the
 *                                hidden input's value, mark the chip
 *                                aria-checked, and dispatch a synthetic
 *                                `change` event on the hidden input so
 *                                host forms see it.
 *
 *   [data-tt-rating-row]       — slider row. The slider's own input event
 *                                updates the readout span. The `data-tt-
 *                                rating-empty` flag is cleared on first
 *                                interaction so the readout flips from "—"
 *                                to the picked value.
 *
 * No external dependencies. Runs on DOMContentLoaded.
 * --------------------------------------------------------------------------- */

(function () {
    'use strict';

    function wireChipGrid(root) {
        var hidden = root.querySelector('[data-tt-rating-value]');
        if (!hidden) return;
        var chips = root.querySelectorAll('.tt-rating-chip:not(.tt-rating-chip--filler)');

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                if (chip.disabled) return;
                chips.forEach(function (other) {
                    other.setAttribute('aria-checked', other === chip ? 'true' : 'false');
                });
                hidden.value = chip.getAttribute('data-value') || '';
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
                hidden.dispatchEvent(new Event('input',  { bubbles: true }));
            });

            // Keyboard parity with native radios: arrows move focus +
            // selection within the radiogroup. Tab still moves out.
            chip.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' &&
                    e.key !== 'ArrowDown'  && e.key !== 'ArrowUp') return;
                e.preventDefault();
                var list = Array.prototype.slice.call(chips);
                var idx  = list.indexOf(chip);
                var step = (e.key === 'ArrowRight' || e.key === 'ArrowDown') ? 1 : -1;
                var next = list[(idx + step + list.length) % list.length];
                next.focus();
                next.click();
            });
        });
    }

    function formatValue(v, step) {
        var n = parseFloat(v);
        if (isNaN(n)) return '—';
        // One decimal if the step is fractional, none otherwise.
        var s = parseFloat(step);
        if (!isNaN(s) && (s % 1) !== 0) return n.toFixed(1);
        return String(Math.round(n));
    }

    function wireSliderRow(row) {
        var slider  = row.querySelector('.tt-rating-row__slider');
        var readout = row.querySelector('[data-tt-rating-readout]');
        if (!slider || !readout) return;

        function refresh() {
            readout.textContent = formatValue(slider.value, slider.step);
            readout.classList.remove('tt-rating-row__val--unset');
        }

        slider.addEventListener('input', function () {
            // First interaction clears the empty flag — if the row
            // started life with `data-tt-rating-empty="1"`, the
            // server-side renderer was showing the midpoint as a
            // placeholder. Clear it so subsequent reads treat the
            // value as a real coach pick.
            slider.removeAttribute('data-tt-rating-empty');
            refresh();
        });

        // Empty (untouched) sliders sit at the midpoint visually but
        // must NOT submit a value — the server treats a missing entry
        // as "not rated". Wire to the closest form's submit and strip
        // the `name` attribute on any slider still flagged empty.
        // Wiring once per form is enough; mark the form so we don't
        // re-bind every wireSliderRow call.
        var form = slider.closest('form');
        if (form && !form.dataset.ttRatingSubmitWired) {
            form.dataset.ttRatingSubmitWired = '1';
            form.addEventListener('submit', function () {
                form.querySelectorAll('[data-tt-rating-empty="1"]').forEach(function (s) {
                    s.removeAttribute('name');
                });
            });
        }

        // Initial render — keep "—" / unset styling when the row was
        // emitted in empty state.
        if (!slider.getAttribute('data-tt-rating-empty')) refresh();
    }

    function init(root) {
        (root || document).querySelectorAll('[data-tt-rating-input]').forEach(wireChipGrid);
        (root || document).querySelectorAll('[data-tt-rating-row]').forEach(wireSliderRow);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    // Re-init hook for callers that swap rating widgets into the DOM
    // (e.g. a wizard step that lazy-loads a roster). Idempotent — chips
    // / sliders already wired keep their listeners; new ones get wired.
    if (typeof window !== 'undefined') {
        window.TT = window.TT || {};
        window.TT.RatingInput = window.TT.RatingInput || {};
        window.TT.RatingInput.init = init;
    }
}());
