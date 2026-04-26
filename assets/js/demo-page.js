/**
 * TalentTrack — DemoData admin page enhancements (#5).
 *
 *   1. Basic / Advanced tab switcher on the Generate form.
 *   2. Full-screen "Generating demo data…" overlay shown when the
 *      generate form submits. Reuses the .tt-bk-overlay styles from
 *      the Backup admin page so the UX matches.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // Tab switching.
        var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-tt-demo-tab]'));
        var panes = document.querySelectorAll('[data-tt-demo-tab-pane]');
        if (tabs.length && panes.length) {
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var target = tab.getAttribute('data-tt-demo-tab');
                    tabs.forEach(function (t) {
                        var active = t.getAttribute('data-tt-demo-tab') === target;
                        t.classList.toggle('tt-demo-tab-active', active);
                        t.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    panes.forEach(function (pane) {
                        pane.hidden = pane.getAttribute('data-tt-demo-tab-pane') !== target;
                    });
                });
            });
        }

        // Generate-form submit overlay.
        var form    = document.getElementById('tt-demo-generate-form');
        var overlay = document.querySelector('[data-tt-demo-overlay]');
        if (form && overlay) {
            form.addEventListener('submit', function () {
                if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
                overlay.removeAttribute('hidden');
                var btn = form.querySelector('input[type="submit"], button[type="submit"]');
                if (btn) btn.disabled = true;
            });
        }
    });
})();
