/* ---------------------------------------------------------------------------
 * RatingInputComponent JS — 1–5 star rating (#1641).
 *
 * Each widget is: a hidden `[data-tt-rating-value]` input carrying the
 * numeric value, a `.tt-rating-stars` radiogroup of star buttons, and a
 * `[data-tt-rating-readout]` output showing the qualitative word.
 *
 * Interaction:
 *   - Click / Enter / Space on a star sets the value (star N → its
 *     data-value) and fills stars up to N.
 *   - Arrow keys move within the group (radiogroup semantics) and set.
 *   - Hover previews the fill (enhancement only; never gates anything).
 *
 * The display is a pure function of the hidden input's value, recomputed
 * on every `input` event — so a programmatic value set (e.g. the wizard's
 * main = average-of-subs recalc dispatching `input`) re-renders the stars
 * and readout through the same path as a user click.
 *
 * Empty widgets carry `data-tt-rating-empty`; on form submit any still-
 * empty input drops its `name` so "not rated" posts as an absent field,
 * matching the server contract.
 * --------------------------------------------------------------------------- */

(function () {
    'use strict';

    function parts(group) {
        var parent = group.parentElement || document;
        return {
            hidden:  parent.querySelector('[data-tt-rating-value]'),
            readout: parent.querySelector('[data-tt-rating-readout]'),
            stars:   group.querySelectorAll('.tt-rating-star'),
            labels:  parseLabels(group)
        };
    }

    function parseLabels(group) {
        try { return JSON.parse(group.getAttribute('data-labels') || 'null'); }
        catch (e) { return null; }
    }

    function syncDisplay(group) {
        var p = parts(group);
        if (!p.hidden) return;
        var empty = p.hidden.hasAttribute('data-tt-rating-empty') || p.hidden.value === '';
        var val   = parseFloat(p.hidden.value);
        var idx   = -1;

        p.stars.forEach(function (star, i) {
            var sv  = parseFloat(star.getAttribute('data-value'));
            var on  = !empty && !isNaN(val) && val >= sv - 0.001;
            var sel = !empty && !isNaN(val) && Math.abs(val - sv) < 0.001;
            star.classList.toggle('is-on', on);
            star.setAttribute('aria-checked', sel ? 'true' : 'false');
            star.setAttribute('tabindex', sel ? '0' : '-1');
            if (sel) idx = i;
        });

        // Keep exactly one star tabbable when nothing is selected.
        if (idx === -1 && p.stars.length) p.stars[0].setAttribute('tabindex', '0');

        if (p.readout) {
            if (empty) {
                p.readout.textContent = '—';
                p.readout.classList.add('tt-rating-row__val--unset');
            } else {
                p.readout.textContent = (p.labels && p.labels[idx]) ? p.labels[idx]
                    : (isNaN(val) ? '—' : String(val));
                p.readout.classList.remove('tt-rating-row__val--unset');
            }
        }
    }

    function setValue(group, value) {
        var p = parts(group);
        if (!p.hidden || p.hidden.disabled) return;
        p.hidden.value = String(value);
        p.hidden.removeAttribute('data-tt-rating-empty');
        syncDisplay(group);
        p.hidden.dispatchEvent(new Event('input',  { bubbles: true }));
        p.hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function hoverTo(group, idx) {
        group.querySelectorAll('.tt-rating-star').forEach(function (s, i) {
            s.classList.toggle('is-hover', i <= idx);
        });
    }
    function clearHover(group) {
        group.querySelectorAll('.tt-rating-star').forEach(function (s) {
            s.classList.remove('is-hover');
        });
    }

    function moveFocus(group, idx, dir) {
        var stars = group.querySelectorAll('.tt-rating-star');
        if (!stars.length) return;
        var next = Math.min(stars.length - 1, Math.max(0, idx + dir));
        stars[next].focus();
        setValue(group, stars[next].getAttribute('data-value'));
    }

    function wire(group) {
        var stars = group.querySelectorAll('.tt-rating-star');
        stars.forEach(function (star, idx) {
            star.addEventListener('click', function () {
                if (star.disabled) return;
                setValue(group, star.getAttribute('data-value'));
            });
            star.addEventListener('mouseenter', function () { hoverTo(group, idx); });
            star.addEventListener('keydown', function (e) {
                var k = e.key;
                if (k === 'ArrowRight' || k === 'ArrowUp')   { e.preventDefault(); moveFocus(group, idx, 1); }
                else if (k === 'ArrowLeft' || k === 'ArrowDown') { e.preventDefault(); moveFocus(group, idx, -1); }
                else if (k === ' ' || k === 'Enter')         { e.preventDefault(); setValue(group, star.getAttribute('data-value')); }
            });
        });
        group.addEventListener('mouseleave', function () { clearHover(group); });

        // Re-render the stars when the hidden value changes programmatically
        // (e.g. the recalc) — single source of truth for the display.
        var hidden = parts(group).hidden;
        if (hidden && !hidden.dataset.ttStarSync) {
            hidden.dataset.ttStarSync = '1';
            hidden.addEventListener('input',  function () { syncDisplay(group); });
            hidden.addEventListener('change', function () { syncDisplay(group); });
        }

        // Drop the name on a still-empty input at submit time so "not
        // rated" posts as an absent field (server treats it as a skip).
        var form = group.closest('form');
        if (form && !form.dataset.ttRatingSubmitWired) {
            form.dataset.ttRatingSubmitWired = '1';
            form.addEventListener('submit', function () {
                form.querySelectorAll('[data-tt-rating-value][data-tt-rating-empty]').forEach(function (h) {
                    h.removeAttribute('name');
                });
            });
        }

        syncDisplay(group);
    }

    function init(root) {
        (root || document).querySelectorAll('.tt-rating-stars').forEach(function (g) {
            if (g.dataset.ttStarWired) return;
            g.dataset.ttStarWired = '1';
            wire(g);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    // Re-render every star widget under `root` from its hidden value.
    // Callers that set values programmatically without dispatching an
    // `input` event (e.g. the wizard's interruption-buffer restore) use
    // this to repaint the stars + readouts.
    function refresh(root) {
        (root || document).querySelectorAll('.tt-rating-stars').forEach(syncDisplay);
    }

    if (typeof window !== 'undefined') {
        window.TT = window.TT || {};
        window.TT.RatingInput = window.TT.RatingInput || {};
        window.TT.RatingInput.init = init;
        window.TT.RatingInput.refresh = refresh;
    }
}());
