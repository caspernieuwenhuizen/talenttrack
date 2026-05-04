/*
 * New-evaluation wizard — Review-step per-row progress (#0072 follow-up).
 *
 * Intercepts the Review form's "Versturen / Next / Create" submit, drives
 * one POST per evaluation row to /wizards/new-evaluation/insert-row, and
 * shows a <progress> bar with "Writing evaluation N of M…". Falls back
 * to the standard PHP submit if no JS, no rows, or the script fails to
 * load.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var payloadEl = document.getElementById('tt-eval-review-payload');
        if (!payloadEl) return;
        var payload;
        try {
            payload = JSON.parse(payloadEl.textContent || '{}');
        } catch (e) { return; }
        if (!payload.rows || !payload.rows.length || !payload.rest_url) return;

        var form = document.querySelector('.tt-wizard-form');
        if (!form) return;

        var progressContainer = document.querySelector('[data-tt-eval-progress]');
        var progressBar = progressContainer ? progressContainer.querySelector('progress') : null;
        var progressStatus = document.querySelector('[data-tt-eval-progress-status]');
        if (!progressContainer || !progressBar || !progressStatus) return;

        // Only intercept Submit / "Versturen" / Create, not Cancel/Skip/Save-as-draft/Back.
        form.addEventListener('click', function (ev) {
            var btn = ev.target;
            if (!btn || btn.tagName !== 'BUTTON') return;
            if (btn.getAttribute('name') !== 'tt_wizard_action') return;
            if (btn.value !== 'next') return;

            ev.preventDefault();
            ev.stopPropagation();
            run();
        }, true);

        function run() {
            progressContainer.hidden = false;
            disableActions();

            var total = payload.rows.length;
            var idx = 0;
            var failed = 0;

            function next() {
                if (idx >= total) {
                    if (failed > 0) {
                        // v3.92.4 — was setting status text and returning,
                        // leaving the form's buttons disabled forever.
                        // Re-enable so the operator can retry / cancel /
                        // go back. Status message also nudges them
                        // toward retrying.
                        setStatus(payload.i18n_failed || 'One or more rows failed. Try again or go back to fix the input.');
                        enableActions();
                        return;
                    }
                    setStatus(payload.i18n_done || 'Done — redirecting…');
                    progressBar.value = 100;
                    setTimeout(function () { window.location.href = payload.redirect_url; }, 400);
                    return;
                }
                var row = payload.rows[idx];
                idx++;
                progressBar.value = Math.round((idx / total) * 100);
                setStatus(format(payload.i18n_writing || 'Writing evaluation %1$d of %2$d…', idx, total));

                fetch(payload.rest_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': payload.rest_nonce },
                    body: JSON.stringify(row)
                }).then(function (res) {
                    if (!res.ok) failed++;
                    next();
                }).catch(function () {
                    failed++;
                    next();
                });
            }
            next();
        }

        function setStatus(text) { progressStatus.textContent = text; }
        function format(template, n, total) {
            return template.replace('%1$d', n).replace('%2$d', total);
        }
        function disableActions() {
            var btns = form.querySelectorAll('button[name="tt_wizard_action"]');
            Array.prototype.forEach.call(btns, function (b) { b.disabled = true; });
        }
        function enableActions() {
            var btns = form.querySelectorAll('button[name="tt_wizard_action"]');
            Array.prototype.forEach.call(btns, function (b) { b.disabled = false; });
        }
    });
})();
