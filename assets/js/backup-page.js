/**
 * TalentTrack — backup settings page enhancements.
 *
 * Two small client-side concerns:
 *   1. Preset description swap. The dropdown's helper text below
 *      the field is a single localised string per preset; switch
 *      it as the user changes selection so they see what each
 *      preset actually covers without having to open the docs.
 *   2. "Backup in progress" overlay. The Run-Now form posts
 *      synchronously through admin-post.php; the overlay reveals
 *      the moment the form submits and the page reloads when the
 *      server redirects back, naturally hiding it. Non-dismissible
 *      by design — see issue #7.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // 1. Preset description swap.
        var select = document.querySelector('[data-tt-bk-preset-select]');
        var desc   = document.querySelector('[data-tt-bk-preset-description]');
        var data   = (window.TT_BACKUP && TT_BACKUP.preset_descriptions) ? TT_BACKUP.preset_descriptions : {};
        if (select && desc) {
            select.addEventListener('change', function () {
                if (Object.prototype.hasOwnProperty.call(data, select.value)) {
                    desc.textContent = data[select.value];
                }
            });
        }

        // 2. Run-Now overlay.
        var overlay = document.querySelector('[data-tt-bk-overlay]');
        var runForm = document.querySelector('[data-tt-bk-run-now-form]');
        if (overlay && runForm) {
            runForm.addEventListener('submit', function () {
                overlay.removeAttribute('hidden');
                // Disable the submit button so a double-click doesn't
                // queue two backups.
                var btn = runForm.querySelector('button[type="submit"], input[type="submit"]');
                if (btn) btn.disabled = true;
            });
        }

        // 3. Restore preview-form: same overlay during the actual
        //    restore submit (the page where the user types "RESTORE").
        var restoreForm = document.querySelector('[data-tt-bk-restore-form]');
        if (overlay && restoreForm) {
            restoreForm.addEventListener('submit', function () {
                var msg = restoreForm.getAttribute('data-tt-bk-restore-msg') || '';
                var msgEl = overlay.querySelector('[data-tt-bk-overlay-msg]');
                if (msg && msgEl) msgEl.textContent = msg;
                overlay.removeAttribute('hidden');
                var btn = restoreForm.querySelector('button[type="submit"], input[type="submit"]');
                if (btn) btn.disabled = true;
            });
        }
    });
})();
