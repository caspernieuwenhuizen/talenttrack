/**
 * TalentTrack — wp-admin confirm + post-action flash bridge.
 *
 * Pairs with assets/js/components/confirm.js (the modal) and the
 * extended flash.js (addNear). Lets server-rendered admin buttons
 * declare a confirmation prompt + a post-event message via data
 * attributes instead of inline `onclick="return confirm(...)"`:
 *
 *   <button type="submit"
 *           class="button-link"
 *           data-tt-confirm-message="Revoke this role assignment?"
 *           data-tt-confirm-title="Revoke role?"
 *           data-tt-confirm-confirm-label="Revoke"
 *           data-tt-confirm-danger
 *           data-tt-flash-success-message="Role revoked.">
 *
 * On click: opens the modal. If the user confirms, the parent <form>
 * submits as normal (server processes, redirects with a query flag).
 * If the page lands on the redirect with `?tt_flash=role_revoked`,
 * an inline server-emitted notice handles the success rendering.
 *
 * For pages that don't redirect on success (rare in this admin layer),
 * the data-tt-flash-success-message is rendered via ttFlash.addNear
 * straight after submission. (Future use; current callers use the
 * redirect path.)
 *
 * Falls back to native `window.confirm()` if confirm.js hasn't loaded
 * for some reason.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var trig = e.target && e.target.closest ? e.target.closest('[data-tt-confirm-message]') : null;
        if (!trig) return;

        // Two supported triggers:
        //   1. Submit button inside a <form>  → confirm, then submit the form.
        //   2. An <a> link with an href       → confirm, then navigate.
        var form    = trig.closest('form');
        var isLink  = trig.tagName === 'A' && trig.getAttribute('href');
        if (!form && !isLink) return;
        e.preventDefault();

        var message      = trig.getAttribute('data-tt-confirm-message') || '';
        var title        = trig.getAttribute('data-tt-confirm-title') || '';
        var confirmLabel = trig.getAttribute('data-tt-confirm-confirm-label') || '';
        var cancelLabel  = trig.getAttribute('data-tt-confirm-cancel-label') || '';
        var danger       = trig.hasAttribute('data-tt-confirm-danger');

        var commit = function () {
            if (form) {
                // If the trigger is a button with name/value, replicate the
                // native submit by adding it as a hidden input first.
                var name = trig.getAttribute('name');
                if (name) {
                    var hidden = document.createElement('input');
                    hidden.type  = 'hidden';
                    hidden.name  = name;
                    hidden.value = trig.getAttribute('value') || '';
                    form.appendChild(hidden);
                }
                form.submit();
                return;
            }
            // Link trigger.
            window.location.href = trig.getAttribute('href');
        };

        if (typeof window.ttConfirm !== 'function') {
            if (window.confirm(message)) commit();
            return;
        }
        window.ttConfirm({
            title:        title,
            message:      message,
            confirmLabel: confirmLabel,
            cancelLabel:  cancelLabel,
            danger:       danger
        }).then(function (ok) { if (ok) commit(); });
    });
})();
