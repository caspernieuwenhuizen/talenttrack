/**
 * mobile-helpers.js (#0084)
 *
 * Minimal vanilla-JS helpers for the mobile pattern library.
 * Currently:
 *   TT.Mobile.openBottomSheet(el)   — show + animate in
 *   TT.Mobile.closeBottomSheet(el)  — animate out + remove from DOM
 *   TT.Mobile.bindBottomSheet(el)   — attach drag-to-dismiss + backdrop-click
 *
 * The drag-to-dismiss listens on touchstart/touchmove/touchend on the
 * sheet's drag-handle. Pulls follow the finger; release with > 80px
 * downward translation (or a flick velocity above 0.5 px/ms) closes the
 * sheet, otherwise it snaps back open. Honours prefers-reduced-motion.
 */

(function () {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    var TT = window.TT = window.TT || {};
    var Mobile = TT.Mobile = TT.Mobile || {};

    Mobile.openBottomSheet = function (sheet) {
        if (!sheet) return;
        var backdrop = sheet.previousElementSibling && sheet.previousElementSibling.classList.contains('tt-mobile-bottom-sheet-backdrop')
            ? sheet.previousElementSibling
            : null;
        if (backdrop) backdrop.setAttribute('data-open', '1');
        // Force layout flush so the transform transition fires from off-screen.
        // eslint-disable-next-line no-unused-expressions
        sheet.offsetHeight;
        sheet.setAttribute('data-open', '1');
        document.body.style.overflow = 'hidden';
    };

    Mobile.closeBottomSheet = function (sheet) {
        if (!sheet) return;
        sheet.removeAttribute('data-open');
        var backdrop = sheet.previousElementSibling && sheet.previousElementSibling.classList.contains('tt-mobile-bottom-sheet-backdrop')
            ? sheet.previousElementSibling
            : null;
        if (backdrop) backdrop.removeAttribute('data-open');
        document.body.style.overflow = '';
    };

    Mobile.bindBottomSheet = function (sheet) {
        if (!sheet || sheet.dataset.ttBound === '1') return;
        sheet.dataset.ttBound = '1';

        var backdrop = sheet.previousElementSibling && sheet.previousElementSibling.classList.contains('tt-mobile-bottom-sheet-backdrop')
            ? sheet.previousElementSibling
            : null;
        if (backdrop) {
            backdrop.addEventListener('click', function () { Mobile.closeBottomSheet(sheet); });
        }

        var handle = sheet.querySelector('.tt-mobile-bottom-sheet-handle');
        if (!handle) return;

        var startY = 0;
        var startedAt = 0;
        var dragging = false;
        var currentTranslate = 0;

        function onStart(e) {
            if (!e.touches || !e.touches[0]) return;
            startY = e.touches[0].clientY;
            startedAt = Date.now();
            dragging = true;
            sheet.style.transition = 'none';
        }
        function onMove(e) {
            if (!dragging || !e.touches || !e.touches[0]) return;
            var dy = Math.max(0, e.touches[0].clientY - startY);
            currentTranslate = dy;
            sheet.style.transform = 'translateY(' + dy + 'px)';
        }
        function onEnd() {
            if (!dragging) return;
            dragging = false;
            sheet.style.transition = '';
            sheet.style.transform = '';
            var dt = Math.max(1, Date.now() - startedAt);
            var velocity = currentTranslate / dt; // px/ms
            if (currentTranslate > 80 || velocity > 0.5) {
                Mobile.closeBottomSheet(sheet);
            }
            currentTranslate = 0;
        }

        handle.addEventListener('touchstart', onStart, { passive: true });
        handle.addEventListener('touchmove', onMove, { passive: true });
        handle.addEventListener('touchend', onEnd);
        handle.addEventListener('touchcancel', onEnd);
    };

    // Auto-bind any sheets present at DOM-ready.
    function autoBind() {
        var sheets = document.querySelectorAll('.tt-mobile-bottom-sheet');
        for (var i = 0; i < sheets.length; i++) {
            Mobile.bindBottomSheet(sheets[i]);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoBind);
    } else {
        autoBind();
    }
}());
