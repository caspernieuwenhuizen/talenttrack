/**
 * TalentTrack — in-app confirm dialog (replaces browser window.confirm)
 *
 * Promise-based API:
 *
 *   ttConfirm({
 *       title:        'Revoke role?',          // optional
 *       message:      'This cannot be undone.',
 *       confirmLabel: 'Revoke',                // optional, defaults to "OK"
 *       cancelLabel:  'Cancel',                // optional
 *       altLabel:     'Record attendance',     // optional remedy button…
 *       altHref:      '/…?tt_view=…',          // …shown only with both
 *       focus:        'cancel',                // optional; default is confirm
 *       danger:       true,                    // confirm button styled as danger
 *   }).then(function(ok) {
 *       if (ok) { ...do the action... }
 *   });
 *
 * Renders a single modal dialog (focus-trapped, ESC-cancellable, backdrop-
 * click-cancellable). One modal active at a time. Lazy-creates the DOM the
 * first time it's invoked; subsequent calls reuse the cached node.
 *
 * Why this exists: browser window.confirm() is jarring, can't be styled,
 * and disappears with no UI trace. The plugin standardised on in-app
 * dialogs in #0019; this script fills the gap for any caller that still
 * uses window.confirm.
 */
(function () {
    'use strict';

    var i18n = (window.TT && TT.i18n) ? TT.i18n : {};
    var DEFAULT_OK = i18n.confirm_ok || 'OK';
    var DEFAULT_CANCEL = i18n.confirm_cancel || 'Cancel';

    var modal = null;
    var pendingResolve = null;
    var lastFocus = null;

    function ensureModal() {
        if (modal) return modal;
        modal = document.createElement('div');
        modal.className = 'tt-confirm-overlay';
        modal.setAttribute('hidden', '');
        modal.innerHTML = [
            '<div class="tt-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="tt-confirm-title">',
            '  <h2 class="tt-confirm-title" id="tt-confirm-title"></h2>',
            '  <p class="tt-confirm-message"></p>',
            // #2023 — optional structured details slot (e.g. the recycle-bin
            // cascade preview). Populated only when the caller passes
            // detailsHtml; the caller is responsible for escaping its content.
            '  <div class="tt-confirm-details" hidden></div>',
            '  <div class="tt-confirm-actions">',
            // #3446 — both vocabularies on every control. `.button` is
            // wp-admin's, `.tt-btn` is the frontend's, and this dialog now
            // opens on both: the wizard's commit step raises it, and until
            // now a frontend caller got a row of unstyled links.
            '    <button type="button" class="button tt-btn tt-btn-secondary tt-confirm-cancel"></button>',
            // #3446 — optional third button that offers the REMEDY rather
            // than the outcome ("Record attendance" beside "Complete
            // anyway"). It navigates instead of resolving, so cancelling
            // and fixing the thing the dialog warned about stay distinct
            // choices; Escape and Cancel still mean "don't do it".
            '    <a class="button tt-btn tt-btn-secondary tt-confirm-alt" hidden></a>',
            '    <button type="button" class="button button-primary tt-btn tt-btn-primary tt-confirm-ok"></button>',
            '  </div>',
            '</div>'
        ].join('');
        document.body.appendChild(modal);

        modal.addEventListener('click', function (e) {
            if (e.target === modal) close(false);
        });
        modal.querySelector('.tt-confirm-cancel').addEventListener('click', function () { close(false); });
        modal.querySelector('.tt-confirm-ok').addEventListener('click', function () { close(true); });
        document.addEventListener('keydown', function (e) {
            if (modal.hasAttribute('hidden')) return;
            if (e.key === 'Escape') { e.preventDefault(); close(false); }
            if (e.key === 'Enter') {
                var focused = document.activeElement;
                // #3446 — the alt button is a link; let Enter follow it
                // rather than confirming the thing it exists to avoid.
                if (focused && (focused.classList.contains('tt-confirm-cancel') || focused.classList.contains('tt-confirm-alt'))) return;
                e.preventDefault();
                close(true);
            }
        });
        return modal;
    }

    function close(ok) {
        if (!modal) return;
        modal.setAttribute('hidden', '');
        modal.classList.remove('tt-confirm-danger');
        var details = modal.querySelector('.tt-confirm-details');
        if (details) { details.setAttribute('hidden', ''); details.innerHTML = ''; }
        var alt = modal.querySelector('.tt-confirm-alt');
        if (alt) { alt.setAttribute('hidden', ''); alt.removeAttribute('href'); }
        if (pendingResolve) {
            var fn = pendingResolve;
            pendingResolve = null;
            fn(!!ok);
        }
        if (lastFocus && typeof lastFocus.focus === 'function') {
            try { lastFocus.focus(); } catch (e) { /* ignore */ }
        }
        lastFocus = null;
    }

    window.ttConfirm = function (opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var m = ensureModal();
            // If a previous confirm is still open, resolve it as cancelled.
            if (pendingResolve) {
                var prev = pendingResolve;
                pendingResolve = null;
                prev(false);
            }
            pendingResolve = resolve;

            m.querySelector('.tt-confirm-title').textContent = opts.title || '';
            m.querySelector('.tt-confirm-title').style.display = opts.title ? '' : 'none';
            m.querySelector('.tt-confirm-message').textContent = opts.message || '';
            // #2023 — optional structured details (caller-escaped HTML).
            var details = m.querySelector('.tt-confirm-details');
            if (details) {
                if (opts.detailsHtml) {
                    details.innerHTML = opts.detailsHtml;
                    details.removeAttribute('hidden');
                } else {
                    details.innerHTML = '';
                    details.setAttribute('hidden', '');
                }
            }
            var okBtn = m.querySelector('.tt-confirm-ok');
            okBtn.textContent = opts.confirmLabel || DEFAULT_OK;
            m.querySelector('.tt-confirm-cancel').textContent = opts.cancelLabel || DEFAULT_CANCEL;
            // #3446 — the remedy button. Shown only when the caller supplies
            // both a label and a destination; a labelled button with nowhere
            // to go would dead-click.
            var altBtn = m.querySelector('.tt-confirm-alt');
            if (altBtn) {
                if (opts.altLabel && opts.altHref) {
                    altBtn.textContent = opts.altLabel;
                    altBtn.setAttribute('href', opts.altHref);
                    altBtn.hidden = false;
                } else {
                    altBtn.removeAttribute('href');
                    altBtn.hidden = true;
                }
            }
            if (opts.danger) m.classList.add('tt-confirm-danger');

            lastFocus = document.activeElement;
            m.removeAttribute('hidden');
            // #3446 — a dialog that warns about a gap must not open with the
            // override under the cursor: one stray Enter and the thing it
            // warned about has happened. Opt-in, so every existing caller
            // keeps the confirm-focused default it was written against.
            var firstFocus = opts.focus === 'cancel'
                ? m.querySelector('.tt-confirm-cancel')
                : okBtn;
            // Defer focus so the dialog is paint-visible first.
            setTimeout(function () { ( firstFocus || okBtn ).focus(); }, 0);
        });
    };
})();
