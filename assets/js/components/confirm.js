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
            '  <div class="tt-confirm-actions">',
            '    <button type="button" class="button tt-confirm-cancel"></button>',
            '    <button type="button" class="button button-primary tt-confirm-ok"></button>',
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
                if (document.activeElement && document.activeElement.classList.contains('tt-confirm-cancel')) return;
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
            var okBtn = m.querySelector('.tt-confirm-ok');
            okBtn.textContent = opts.confirmLabel || DEFAULT_OK;
            m.querySelector('.tt-confirm-cancel').textContent = opts.cancelLabel || DEFAULT_CANCEL;
            if (opts.danger) m.classList.add('tt-confirm-danger');

            lastFocus = document.activeElement;
            m.removeAttribute('hidden');
            // Defer focus so the dialog is paint-visible first.
            setTimeout(function () { okBtn.focus(); }, 0);
        });
    };
})();
