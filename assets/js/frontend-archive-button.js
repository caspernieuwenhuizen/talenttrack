/*
 * frontend-archive-button.js (v3.110.53)
 *
 * Wires up Archive buttons on detail pages. The button carries:
 *   data-tt-archive-rest-path="players/123"
 *   data-tt-archive-confirm="Archive this player? They can be restored later."
 *   data-tt-archive-redirect="https://.../wp-admin/?tt_view=players"
 *
 * v3.110.104 — the click handler now opens a `<dialog>`-based app
 * modal instead of `window.confirm()`. Same payload (REST path /
 * confirm message / redirect URL); the modal shows the message in
 * an in-page card with Cancel + Archive buttons, focus-trapped via
 * the native dialog element. Pilot symptom: *"the archive button
 * triggers a browser notification instead of an application
 * notification."* Native dialog is widely supported across the
 * browsers TalentTrack targets (all evergreen + Safari 15.4+).
 * Fallback to `window.confirm()` only when the dialog element
 * isn't supported.
 *
 * Errors (REST failure, network) still surface via `window.alert`
 * because they're rare and out of scope of the pilot's report;
 * worth revisiting if those become noisy in practice.
 *
 * Click → modal confirm → fetch DELETE /wp-json/talenttrack/v1/<rest_path>
 * with X-WP-Nonce → on success, redirect to the list URL. Nonce +
 * REST root come from window.TT (set by public.js on every
 * dashboard page).
 */
(function () {
    'use strict';

    var globals = window.TT || {};
    var rest_root  = globals.rest_url   || '';
    var rest_nonce = globals.rest_nonce || '';

    var DIALOG_ID = 'tt-archive-confirm-dialog';

    /**
     * Inject the modal once per page; reuse for every archive button.
     * Returns the dialog element, or null when the runtime doesn't
     * support `<dialog>.showModal()`.
     */
    function ensureDialog( i18n ) {
        var existing = document.getElementById( DIALOG_ID );
        if ( existing ) return existing;
        if ( typeof HTMLDialogElement === 'undefined' ) return null;

        var dialog = document.createElement( 'dialog' );
        dialog.id = DIALOG_ID;
        dialog.className = 'tt-modal tt-modal--archive';
        dialog.innerHTML =
            '<form method="dialog" class="tt-modal-form">' +
                '<h2 class="tt-modal-title">' + escapeHtml( i18n.title ) + '</h2>' +
                '<p class="tt-modal-message" data-tt-archive-modal-msg></p>' +
                '<div class="tt-modal-actions">' +
                    '<button type="submit" value="cancel" class="tt-btn tt-btn-secondary">' + escapeHtml( i18n.cancel ) + '</button>' +
                    '<button type="submit" value="confirm" class="tt-btn tt-btn-danger" data-tt-archive-modal-confirm>' + escapeHtml( i18n.confirm ) + '</button>' +
                '</div>' +
            '</form>';
        document.body.appendChild( dialog );
        return dialog;
    }

    function escapeHtml( s ) {
        return String( s ).replace( /[&<>"']/g, function ( c ) {
            return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ];
        } );
    }

    /**
     * Promise-like prompt. Resolves true when the coach confirms,
     * false when they cancel or dismiss (Escape, backdrop click).
     */
    function promptArchive( msg, i18n, onResult ) {
        var dialog = ensureDialog( i18n );
        if ( ! dialog ) {
            // Fallback for very old browsers (shouldn't happen on the
            // versions TalentTrack targets, but defensive).
            onResult( window.confirm( msg ) );
            return;
        }
        dialog.querySelector( '[data-tt-archive-modal-msg]' ).textContent = msg;
        var closeHandler = function () {
            dialog.removeEventListener( 'close', closeHandler );
            onResult( dialog.returnValue === 'confirm' );
        };
        dialog.addEventListener( 'close', closeHandler );
        dialog.showModal();
        // Focus the Cancel button by default so a stray Enter on the
        // backdrop doesn't accidentally confirm a destructive action.
        var cancelBtn = dialog.querySelector( 'button[value="cancel"]' );
        if ( cancelBtn ) cancelBtn.focus();
    }

    function init() {
        // v3.110.104 — strings are localised via wp_localize_script on
        // the enqueue site (`FrontendViewBase::enqueueAssets`). Falls
        // back to English defaults if the localise step ever fails.
        var i18n = window.TT_ArchiveI18n || {};
        var modal_i18n = {
            title:   i18n.title   || 'Archive record',
            cancel:  i18n.cancel  || 'Cancel',
            confirm: i18n.confirm || 'Archive'
        };

        var buttons = document.querySelectorAll('[data-tt-archive-rest-path]');
        Array.prototype.forEach.call(buttons, function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                if (btn.disabled) return;

                var confirm_text = btn.getAttribute('data-tt-archive-confirm') || modal_i18n.title;
                promptArchive( confirm_text, modal_i18n, function ( ok ) {
                    if ( ! ok ) return;

                    var path     = btn.getAttribute('data-tt-archive-rest-path') || '';
                    var redirect = btn.getAttribute('data-tt-archive-redirect') || '';
                    if (!path || !rest_root || !rest_nonce) {
                        window.alert('Archive failed: REST configuration missing.');
                        return;
                    }

                    btn.disabled = true;
                    fetch(rest_root + path, {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-WP-Nonce': rest_nonce
                        }
                    }).then(function (res) {
                        return res.json().then(function (body) {
                            return { status: res.status, body: body };
                        }).catch(function () {
                            return { status: res.status, body: null };
                        });
                    }).then(function (r) {
                        var ok2 = r.status >= 200 && r.status < 300 && (!r.body || r.body.success !== false);
                        if (ok2) {
                            if (redirect) {
                                window.location.assign(redirect);
                            } else {
                                window.location.reload();
                            }
                            return;
                        }
                        btn.disabled = false;
                        var msg = 'Archive failed.';
                        if (r.body && r.body.errors && r.body.errors[0] && r.body.errors[0].message) {
                            msg = r.body.errors[0].message;
                        }
                        window.alert(msg);
                    }).catch(function () {
                        btn.disabled = false;
                        window.alert('Network error. Please try again.');
                    });
                } );
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
