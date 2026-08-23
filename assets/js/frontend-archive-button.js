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
 *
 * #1555 — generalised beyond archive-only. Optional attributes let the
 * same button reuse this modal/nonce/redirect plumbing for the rest of
 * the archive lifecycle:
 *   data-tt-archive-method="POST"          — HTTP verb (default DELETE)
 *   data-tt-archive-confirm-label="Restore"— confirm-button label
 *   data-tt-archive-variant="primary"      — confirm-button style
 *                                            ('danger' default)
 *   data-tt-archive-confirm-title="Reopen activity" — modal title
 *                                            (#2265; default "Archive record")
 * Restore (POST .../restore) and permanent-delete (DELETE .../permanent)
 * on the activities archived list ride on these; existing archive
 * buttons keep the DELETE / danger defaults and are untouched.
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
                // #2411 — optional opt-in checkbox (e.g. "also archive this
                // team's activities"). Hidden unless the button declares one.
                '<label class="tt-modal-option" data-tt-archive-modal-option hidden>' +
                    '<input type="checkbox" data-tt-archive-modal-option-input />' +
                    '<span data-tt-archive-modal-option-label></span>' +
                '</label>' +
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
    function promptArchive( msg, i18n, onResult, opts ) {
        opts = opts || {};
        var dialog = ensureDialog( i18n );
        if ( ! dialog ) {
            // Fallback for very old browsers (shouldn't happen on the
            // versions TalentTrack targets, but defensive).
            onResult( window.confirm( msg ) );
            return;
        }
        dialog.querySelector( '[data-tt-archive-modal-msg]' ).textContent = msg;
        // #2265 — retarget the modal TITLE per action too. The dialog is
        // shared across archive / restore / reopen / cancel; without this
        // a Reopen or Cancel kept the fixed "Archive record" title. Reset
        // every open, falling back to the localised default.
        var titleEl = dialog.querySelector( '.tt-modal-title' );
        if ( titleEl ) {
            titleEl.textContent = opts.title || i18n.title;
        }
        // #1555 — retarget the confirm button label + variant per action
        // (Archive / Restore / Delete) so the modal reads correctly for
        // each step of the lifecycle.
        var confirmBtn = dialog.querySelector( '[data-tt-archive-modal-confirm]' );
        if ( confirmBtn ) {
            confirmBtn.textContent = opts.confirmLabel || i18n.confirm;
            confirmBtn.className = 'tt-btn ' + ( opts.variant === 'primary' ? 'tt-btn-primary' : 'tt-btn-danger' );
        }
        // #2411 — optional opt-in checkbox. Shown only when the button
        // declares one; its state travels back with the confirmation so the
        // caller can fold it into the request body.
        var optionWrap  = dialog.querySelector( '[data-tt-archive-modal-option]' );
        var optionInput = dialog.querySelector( '[data-tt-archive-modal-option-input]' );
        var optionLabel = dialog.querySelector( '[data-tt-archive-modal-option-label]' );
        if ( optionWrap && optionInput && optionLabel ) {
            if ( opts.optionLabel ) {
                optionLabel.textContent = opts.optionLabel;
                optionInput.checked = opts.optionDefault !== false;
                optionWrap.hidden = false;
            } else {
                optionWrap.hidden = true;
                optionInput.checked = false;
            }
        }

        var closeHandler = function () {
            dialog.removeEventListener( 'close', closeHandler );
            onResult(
                dialog.returnValue === 'confirm',
                !! ( optionInput && ! optionWrap.hidden && optionInput.checked )
            );
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
                var method   = ( btn.getAttribute('data-tt-archive-method') || 'DELETE' ).toUpperCase();
                // #2411 — an action may offer one opt-in checkbox in the
                // confirm dialog; `data-tt-archive-option-key` names the
                // body field its state is sent as.
                var optionKey = btn.getAttribute('data-tt-archive-option-key') || '';
                var opts = {
                    title:        btn.getAttribute('data-tt-archive-confirm-title') || '',
                    confirmLabel: btn.getAttribute('data-tt-archive-confirm-label') || '',
                    variant:      btn.getAttribute('data-tt-archive-variant') || 'danger',
                    optionLabel:  optionKey ? ( btn.getAttribute('data-tt-archive-option-label') || '' ) : '',
                    optionDefault: btn.getAttribute('data-tt-archive-option-default') !== '0'
                };
                promptArchive( confirm_text, modal_i18n, function ( ok, optionChecked ) {
                    if ( ! ok ) return;

                    var path     = btn.getAttribute('data-tt-archive-rest-path') || '';
                    var redirect = btn.getAttribute('data-tt-archive-redirect') || '';
                    if (!path || !rest_root || !rest_nonce) {
                        window.alert('Archive failed: REST configuration missing.');
                        return;
                    }

                    btn.disabled = true;
                    // #2245 — optional JSON body (e.g. the status
                    // transition endpoint needs `{ status: '…' }`).
                    // Backward-compatible: archive / restore / permanent
                    // buttons carry no body and send none.
                    var bodyRaw = btn.getAttribute('data-tt-archive-body') || '';
                    var headers = {
                        'Accept': 'application/json',
                        'X-WP-Nonce': rest_nonce
                    };
                    var fetchOpts = {
                        method: method,
                        credentials: 'same-origin',
                        headers: headers
                    };
                    // #2411 — fold the dialog's opt-in checkbox into the body
                    // under the key the button declared, merging with any
                    // static body rather than replacing it.
                    if (optionKey) {
                        var payload = {};
                        if (bodyRaw) {
                            try { payload = JSON.parse(bodyRaw) || {}; } catch (e) { payload = {}; }
                        }
                        payload[optionKey] = !!optionChecked;
                        bodyRaw = JSON.stringify(payload);
                    }
                    if (bodyRaw) {
                        headers['Content-Type'] = 'application/json';
                        fetchOpts.body = bodyRaw;
                    }
                    fetch(rest_root + path, fetchOpts).then(function (res) {
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
                        var msg = 'Action failed.';
                        if (r.body && r.body.errors && r.body.errors[0] && r.body.errors[0].message) {
                            msg = r.body.errors[0].message;
                        }
                        window.alert(msg);
                    }).catch(function () {
                        btn.disabled = false;
                        window.alert('Network error. Please try again.');
                    });
                    // #2684 — `opts` carries the per-action title, confirm
                    // label, variant and opt-in checkbox. It was built above
                    // and then never passed, so every action fell back to the
                    // archive defaults: Reopen and Restore asked "Archive
                    // record" behind a red button, and the team cascade
                    // checkbox (#2411) could never be shown, which meant its
                    // value went out as `false` on every team archive.
                } , opts );
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
