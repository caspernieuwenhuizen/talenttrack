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
            // #3041 — tell the server this browser can drive the run one
            // step at a time. Set from JS on purpose: without it the form
            // posts exactly as it always did and the server generates
            // everything in the one request, so a reader with no JavaScript
            // loses nothing.
            var chunked = document.createElement('input');
            chunked.type = 'hidden';
            chunked.name = 'chunked';
            chunked.value = '1';
            form.appendChild(chunked);

            form.addEventListener('submit', function () {
                if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
                overlay.removeAttribute('hidden');
                var btn = form.querySelector('input[type="submit"], button[type="submit"]');
                if (btn) btn.disabled = true;
            });
        }

        runner(overlay);
    });

    /**
     * #3041 — walk a started run to the end, one request per step.
     *
     * The whole generation used to be a single admin-post request, which a
     * hosted install's reverse proxy killed on the large preset. Each step is
     * its own short request now, and the overlay says which one is running
     * instead of spinning indeterminately.
     */
    function runner(overlay) {
        var cfg = window.TTDemoRun;
        if (!cfg || !cfg.root) return;

        var stepEl = document.querySelector('[data-tt-demo-step]');
        var barEl  = document.querySelector('[data-tt-demo-bar]');
        var fillEl = document.querySelector('[data-tt-demo-bar-fill]');

        function post(path, body) {
            return fetch(cfg.root + path, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cfg.nonce
                },
                body: JSON.stringify(body || {})
            }).then(function (res) { return res.json(); });
        }

        function unwrap(payload) {
            if (!payload) return null;
            return payload.data && typeof payload.data === 'object' ? payload.data : payload;
        }

        function paint(progress) {
            if (!progress) return;
            var total = progress.total || 0;
            var done  = progress.completed || 0;

            if (stepEl) {
                stepEl.textContent = progress.next
                    ? cfg.i18n.step
                        .replace('%1$d', Math.min(done + 1, total))
                        .replace('%2$d', total)
                        .replace('%3$s', progress.next_label)
                    : cfg.i18n.finishing;
            }
            if (barEl && fillEl && total > 0) {
                barEl.removeAttribute('hidden');
                fillEl.style.width = Math.round((done / total) * 100) + '%';
            }
        }

        function drive(runId) {
            if (overlay) overlay.removeAttribute('hidden');

            return post('/step', { run_id: runId }).then(function (payload) {
                var progress = unwrap(payload);
                if (!progress || !progress.status) {
                    if (stepEl) stepEl.textContent = cfg.i18n.failed;
                    return;
                }
                paint(progress);

                if (progress.status === 'running' && progress.next) {
                    return drive(runId);
                }
                if (progress.status === 'failed') {
                    if (stepEl) stepEl.textContent = cfg.i18n.failed;
                    window.setTimeout(function () { window.location.reload(); }, 1500);
                    return;
                }
                // Done: land on the page's own "generated" summary.
                window.location.href = cfg.pageUrl
                    + '&tt_demo_msg=generated'
                    + '&tt_demo_batch=' + encodeURIComponent(progress.batch_id || '');
            }).catch(function () {
                if (stepEl) stepEl.textContent = cfg.i18n.failed;
            });
        }

        // A redirect back from the generate form carries the run id.
        if (cfg.runId) {
            drive(cfg.runId);
            return;
        }

        // An unfinished run from an earlier visit: resume or discard.
        var banner = document.querySelector('[data-tt-demo-resume]');
        if (!banner) return;

        var runId = banner.getAttribute('data-tt-run-id') || '';
        var resume = banner.querySelector('[data-tt-demo-resume-start]');
        var discard = banner.querySelector('[data-tt-demo-resume-discard]');

        if (resume) {
            resume.addEventListener('click', function () {
                resume.disabled = true;
                drive(runId);
            });
        }
        if (discard) {
            discard.addEventListener('click', function () {
                discard.disabled = true;
                discard.textContent = cfg.i18n.discarding;
                post('/discard', {}).then(function () { window.location.reload(); });
            });
        }
    }
})();
