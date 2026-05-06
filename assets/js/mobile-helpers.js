/*
 * mobile-helpers.js — gesture handlers for the mobile pattern library
 * (#0084 Child 2).
 *
 * Loaded conditionally by `DashboardShortcode::render()` only when the
 * resolved view classifies as `native`. Tablets and desktops never
 * load this file (they use the existing components unchanged).
 *
 * Single concern at the moment: drag-to-dismiss for
 * `.tt-mobile-bottom-sheet`. The other three components in the
 * library (`tt-mobile-cta-bar`, `tt-mobile-segmented-control`,
 * `tt-mobile-list-item`) are pure CSS — no JS needed.
 *
 * Public API on `window.TT.Mobile`:
 *   open(sheet)   — slide the sheet in + show backdrop.
 *   close(sheet)  — slide the sheet out + hide backdrop.
 *   bind(sheet)   — attach drag-to-dismiss + backdrop-click + Escape
 *                   handlers. Idempotent. Returns a teardown function.
 *
 * Templates render the sheet markup but DON'T need to call `bind()`
 * directly — auto-binding runs on `DOMContentLoaded` for every
 * `.tt-mobile-bottom-sheet` already in the DOM, plus a `MutationObserver`
 * picks up sheets injected later (drawers from REST responses, etc.).
 *
 * Honours `prefers-reduced-motion: reduce` (the CSS already removes
 * the slide-up transition; the JS just toggles classes).
 */

(function () {
    'use strict';

    var TT = window.TT = window.TT || {};
    var Mobile = TT.Mobile = TT.Mobile || {};

    /**
     * Open `sheet` (an `.tt-mobile-bottom-sheet` element). Spawns the
     * backdrop adjacent to the sheet if one isn't already there, and
     * sets `body.style.overflow = 'hidden'` so the page beneath the
     * sheet doesn't scroll while the sheet is up.
     */
    Mobile.open = function (sheet) {
        if (!sheet || sheet.classList.contains('is-open')) return;
        var backdrop = ensureBackdrop(sheet);
        sheet.classList.add('is-open');
        backdrop.classList.add('is-visible');
        document.body.dataset.ttMobileSheetOpen = '1';
        document.body.style.overflow = 'hidden';
    };

    /**
     * Close `sheet` and hide its backdrop. No-op if already closed.
     */
    Mobile.close = function (sheet) {
        if (!sheet || !sheet.classList.contains('is-open')) return;
        sheet.classList.remove('is-open');
        var backdrop = sheet._ttBackdrop;
        if (backdrop) backdrop.classList.remove('is-visible');
        delete document.body.dataset.ttMobileSheetOpen;
        document.body.style.overflow = '';
    };

    /**
     * Attach drag-to-dismiss + backdrop-click + Escape-key handlers
     * to `sheet`. Idempotent — repeat calls don't double-bind. Returns
     * a teardown function that detaches all listeners.
     */
    Mobile.bind = function (sheet) {
        if (!sheet || sheet._ttMobileBound) return function () {};
        sheet._ttMobileBound = true;

        var startY = null;
        var deltaY = 0;
        var sheetHeight = 0;
        var handle = sheet.querySelector('.tt-mobile-bottom-sheet-handle');

        function onTouchStart(e) {
            if (!e.touches || !e.touches[0]) return;
            startY = e.touches[0].clientY;
            sheetHeight = sheet.offsetHeight;
            sheet.style.transition = 'none';
        }

        function onTouchMove(e) {
            if (startY == null || !e.touches || !e.touches[0]) return;
            deltaY = e.touches[0].clientY - startY;
            if (deltaY < 0) deltaY = 0; // can't drag up beyond rest
            sheet.style.transform = 'translateY(' + deltaY + 'px)';
        }

        function onTouchEnd() {
            if (startY == null) return;
            sheet.style.transition = '';
            sheet.style.transform = '';
            // Dismiss when dragged > 35% of sheet height OR > 120px,
            // whichever is smaller. Sub-120px sheets shouldn't need a
            // 35% drag to dismiss.
            var threshold = Math.min(sheetHeight * 0.35, 120);
            if (deltaY > threshold) {
                Mobile.close(sheet);
            }
            startY = null;
            deltaY = 0;
        }

        var dragTarget = handle || sheet;
        dragTarget.addEventListener('touchstart', onTouchStart, { passive: true });
        dragTarget.addEventListener('touchmove',  onTouchMove,  { passive: true });
        dragTarget.addEventListener('touchend',   onTouchEnd,   { passive: true });

        function onBackdropClick() {
            Mobile.close(sheet);
        }
        var backdrop = ensureBackdrop(sheet);
        backdrop.addEventListener('click', onBackdropClick);

        function onKey(e) {
            if (e.key === 'Escape' && sheet.classList.contains('is-open')) {
                Mobile.close(sheet);
            }
        }
        document.addEventListener('keydown', onKey);

        return function teardown() {
            dragTarget.removeEventListener('touchstart', onTouchStart);
            dragTarget.removeEventListener('touchmove',  onTouchMove);
            dragTarget.removeEventListener('touchend',   onTouchEnd);
            backdrop.removeEventListener('click', onBackdropClick);
            document.removeEventListener('keydown', onKey);
            sheet._ttMobileBound = false;
        };
    };

    /**
     * Spawn a backdrop element adjacent to `sheet` if one doesn't
     * already exist. Cached on the sheet via `_ttBackdrop` so repeat
     * calls don't create duplicates.
     */
    function ensureBackdrop(sheet) {
        if (sheet._ttBackdrop) return sheet._ttBackdrop;
        var backdrop = document.createElement('div');
        backdrop.className = 'tt-mobile-bottom-sheet-backdrop';
        sheet.parentNode.insertBefore(backdrop, sheet);
        sheet._ttBackdrop = backdrop;
        return backdrop;
    }

    function autoBind(root) {
        var sheets = (root || document).querySelectorAll('.tt-mobile-bottom-sheet');
        for (var i = 0; i < sheets.length; i++) Mobile.bind(sheets[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { autoBind(document); });
    } else {
        autoBind(document);
    }

    // Pick up sheets injected after page load (e.g. via REST-driven
    // drawer rendering). The observer is single-pass per added node;
    // `bind()` itself is idempotent.
    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (node.nodeType === 1) autoBind(node);
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
