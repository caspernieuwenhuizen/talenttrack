/*
 * record-spine-tabs.js — in-page tab strips rendered by RecordSpine.
 *
 * Binds every `[data-tt-spine-tabs]` strip on the page to the panels its
 * tabs name in `aria-controls`. The markup is already correct without
 * this file: the active tab is `aria-selected="true"`, its panel is the
 * one not carrying `hidden`, and every other panel is hidden server-side.
 * So a page that fails to load this script shows the default panel rather
 * than nothing, which is the right way round for a live-match surface.
 *
 * Follows the WAI-ARIA tabs pattern with **automatic activation**: an
 * arrow key moves focus and selects in one go. That is the recommended
 * behaviour for small strips whose panels are already in the DOM — there
 * is nothing to fetch, so there is no reason to make the user press Enter
 * as well. Strips here carry three or four tabs.
 */
(function () {
    'use strict';

    function panelFor(tab) {
        var id = tab.getAttribute('aria-controls');
        return id ? document.getElementById(id) : null;
    }

    function select(strip, tab, moveFocus) {
        var tabs = strip.querySelectorAll('[role="tab"]');
        Array.prototype.forEach.call(tabs, function (t) {
            var on = t === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.setAttribute('tabindex', on ? '0' : '-1');
            t.classList.toggle('is-active', on);

            var panel = panelFor(t);
            if (panel) panel.hidden = !on;
        });

        if (moveFocus) tab.focus();

        // A tab reached by keyboard can be outside the strip's scroll
        // window; the focus ring alone would then be invisible.
        if (typeof tab.scrollIntoView === 'function') {
            tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }

        strip.dispatchEvent(new CustomEvent('tt-spine-tab-change', {
            bubbles: true,
            detail: { panel: tab.getAttribute('aria-controls') }
        }));
    }

    function wire(strip) {
        if (strip.hasAttribute('data-tt-spine-bound')) return;
        strip.setAttribute('data-tt-spine-bound', '');

        strip.addEventListener('click', function (e) {
            var tab = e.target.closest('[role="tab"]');
            if (tab && strip.contains(tab)) select(strip, tab, false);
        });

        strip.addEventListener('keydown', function (e) {
            var tab = e.target.closest('[role="tab"]');
            if (!tab || !strip.contains(tab)) return;

            var tabs = Array.prototype.slice.call(strip.querySelectorAll('[role="tab"]'));
            var i = tabs.indexOf(tab);
            var next = null;

            // Horizontal strip, so Left/Right. Up/Down are deliberately
            // left alone: they scroll the panel, which is what a coach
            // reading a long bench list expects them to do.
            if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
            else if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
            else if (e.key === 'Home') next = tabs[0];
            else if (e.key === 'End') next = tabs[tabs.length - 1];
            else return;

            e.preventDefault();
            select(strip, next, true);
        });
    }

    function init() {
        var strips = document.querySelectorAll('[data-tt-spine-tabs]');
        Array.prototype.forEach.call(strips, wire);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
