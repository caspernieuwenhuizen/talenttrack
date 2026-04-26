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
        var btn = e.target && e.target.closest ? e.target.closest('[data-tt-confirm-message]') : null;
        if (!btn) return;
        // Only handle submit-style buttons or links inside a form.
        var form = btn.closest('form');
        if (!form) return;
        e.preventDefault();

        var message      = btn.getAttribute('data-tt-confirm-message') || '';
        var title        = btn.getAttribute('data-tt-confirm-title') || '';
        var confirmLabel = btn.getAttribute('data-tt-confirm-confirm-label') || '';
        var cancelLabel  = btn.getAttribute('data-tt-confirm-cancel-label') || '';
        var danger       = btn.hasAttribute('data-tt-confirm-danger');

        var submit = function () {
            // If the button has a name/value, replicate the standard
            // form-button submit by adding it as a hidden input first.
            var name = btn.getAttribute('name');
            if (name) {
                var hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = name;
                hidden.value = btn.getAttribute('value') || '';
                form.appendChild(hidden);
            }
            form.submit();
        };

        if (typeof window.ttConfirm !== 'function') {
            if (window.confirm(message)) submit();
            return;
        }
        window.ttConfirm({
            title:        title,
            message:      message,
            confirmLabel: confirmLabel,
            cancelLabel:  cancelLabel,
            danger:       danger
        }).then(function (ok) { if (ok) submit(); });
    });
})();
