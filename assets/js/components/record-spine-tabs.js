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

    function select(strip, tab, moveFocus, quiet) {
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
        // window; the focus ring alone would then be invisible. Not for the
        // selection a page load makes from the URL: nothing has focus then,
        // and the page must not jump.
        if (!quiet && typeof tab.scrollIntoView === 'function') {
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

    /*
     * #5080 — the stuck spine's height, published as `--tt-spine-h` on the
     * dashboard root, the way app-shell.js publishes the header's. A sticky
     * header further down the page (a PDP talk's form part) sits at the
     * spine's bottom edge instead of on top of it. Only a spine that
     * actually sticks counts: the side layout and a spine a surface sets
     * static (live match) publish nothing.
     */
    function measureSpine() {
        var root  = document.querySelector('.tt-dashboard');
        var spine = document.querySelector('.tt-spine:not(.tt-spine--side)');
        if (!root || !spine || window.getComputedStyle(spine).position !== 'sticky') return;

        var sync = function () {
            var h = Math.round(spine.getBoundingClientRect().height);
            if (h > 0) root.style.setProperty('--tt-spine-h', h + 'px');
        };
        sync();
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(sync).observe(spine);
        } else {
            window.addEventListener('resize', sync);
        }
    }

    /*
     * #5080 — below 480px the spine's aside leaves the stuck strip and sits
     * in the page flow just under the spine, so it scrolls away with the
     * hero: the stuck chrome on a record page stays within 45% of a 360x640
     * viewport. The node itself moves, so the control exists once at every
     * width (no twin, no second form or nonce), focus inside it is kept,
     * and from 480px up it is back on the right of the tab row. Without
     * this script the aside simply stays in the strip.
     */
    function placeAside() {
        var spine = document.querySelector('.tt-spine:not(.tt-spine--side)');
        var aside = spine ? spine.querySelector('.tt-spine__aside') : null;
        var row   = aside ? aside.parentElement : null;
        if (!spine || !aside || !row || !window.matchMedia) return;

        var slot = document.createElement('div');
        slot.className = 'tt-spine-aside-slot';
        // Hidden until the aside moves in, so a load at 480px or wider never
        // leaves an empty slot showing.
        slot.hidden = true;
        spine.parentNode.insertBefore(slot, spine.nextSibling);

        var wide = window.matchMedia('(min-width: 480px)');
        var apply = function () {
            var target = wide.matches ? row : slot;
            if (aside.parentElement === target) return;
            var active = document.activeElement;
            var hadFocus = active && aside.contains(active);
            target.appendChild(aside);
            slot.hidden = wide.matches;
            if (hadFocus && typeof active.focus === 'function') active.focus();
        };
        apply();
        if (typeof wide.addEventListener === 'function') wide.addEventListener('change', apply);
        else if (typeof wide.addListener === 'function') wide.addListener(apply);
    }

    /*
     * #5082 — a record page can open on a named tab: `#<panel-id>` selects
     * that panel when it is one of this strip's own. Read once, on load, and
     * never written, so tab use adds nothing to the browser history. The
     * fragment is input: it is only compared, as a string, with the tabs'
     * `aria-controls`, never handed to a selector. Anything else (an unknown
     * id, another strip's panel, an ordinary anchor) leaves the server's
     * choice standing.
     */
    function openFromFragment(strip) {
        var hash = window.location.hash ? window.location.hash.slice(1) : '';
        if (hash === '') return;
        var tabs = strip.querySelectorAll('[role="tab"]');
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i].getAttribute('aria-controls') === hash) {
                select(strip, tabs[i], false, true);
                return;
            }
        }
    }

    function init() {
        var strips = document.querySelectorAll('[data-tt-spine-tabs]');
        Array.prototype.forEach.call(strips, wire);
        Array.prototype.forEach.call(strips, openFromFragment);
        placeAside();
        measureSpine();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
