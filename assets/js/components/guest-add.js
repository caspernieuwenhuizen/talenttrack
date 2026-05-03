/* TalentTrack — Guest add modal (#0026, #0037).
 *
 * Hooks the "+ Add guest" button on an activity create / edit form
 * to the guest modal: tab switching, validation, REST POST to
 * /activities/{id}/guests, and an inline DOM append on success so the
 * coach sees the new row immediately.
 *
 * #0037 — create-flow support: when the button is clicked on a not-
 * yet-saved activity (activity_id = 0), we trigger the activity form's
 * own save, listen for the response on tt:form-saved, and redirect
 * the page to the edit URL with `&open_guest=1`. The DOMContentLoaded
 * handler at the bottom honours that query param by auto-clicking the
 * "+ Add guest" button on edit-page load. The result is a single fluid
 * "create activity → add guest" flow with no manual second click.
 */
( function () {
    'use strict';

    var REST_NS = ( window.TT_GuestAdd && window.TT_GuestAdd.restNs ) || '/wp-json/talenttrack/v1';
    var NONCE   = ( window.TT_GuestAdd && window.TT_GuestAdd.nonce )  || ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';
    var STR     = ( window.TT_GuestAdd && window.TT_GuestAdd.strings ) || {};

    function $$( sel, root ) { return ( root || document ).querySelectorAll( sel ); }
    function $( sel, root )  { return ( root || document ).querySelector( sel ); }

    function openModal( modal ) { modal.hidden = false; modal.classList.add( 'tt-guest-modal--open' ); }
    function closeModal( modal ) {
        modal.hidden = true; modal.classList.remove( 'tt-guest-modal--open' );
        var msg = modal.querySelector( '[data-tt-guest-modal-msg]' );
        if ( msg ) { msg.hidden = true; msg.textContent = ''; }
        var nameInput = modal.querySelector( '#tt-guest-anon-name' );
        var ageInput  = modal.querySelector( '#tt-guest-anon-age' );
        var posInput  = modal.querySelector( '#tt-guest-anon-position' );
        // PlayerSearchPickerComponent (#0037) — clear via its own clear
        // button so the search input + selected-chip state both reset.
        var clearBtn  = modal.querySelector( '[data-tt-psp-clear]' );
        var linkedHidden = modal.querySelector( 'input[name="tt_guest_linked_player_id"]' );
        if ( nameInput ) nameInput.value = '';
        if ( ageInput )  ageInput.value  = '';
        if ( posInput )  posInput.value  = '';
        if ( clearBtn ) clearBtn.click();
        else if ( linkedHidden ) linkedHidden.value = '';
    }

    function readSessionId( modalEl ) {
        var attr = modalEl.getAttribute( 'data-session-id' );
        if ( attr ) return parseInt( attr, 10 ) || 0;
        var holder = document.querySelector( '[data-tt-guest-session-id]' );
        if ( holder ) return parseInt( holder.getAttribute( 'data-tt-guest-session-id' ), 10 ) || 0;
        return 0;
    }

    function switchTab( modal, target ) {
        $$( '[data-tt-guest-tab]', modal ).forEach( function ( btn ) {
            var active = btn.getAttribute( 'data-tt-guest-tab' ) === target;
            btn.classList.toggle( 'tt-guest-modal-tab--active', active );
        } );
        $$( '[data-tt-guest-pane]', modal ).forEach( function ( pane ) {
            pane.hidden = pane.getAttribute( 'data-tt-guest-pane' ) !== target;
        } );
    }

    function activeTab( modal ) {
        var btn = modal.querySelector( '.tt-guest-modal-tab--active' );
        return btn ? btn.getAttribute( 'data-tt-guest-tab' ) : 'linked';
    }

    function showMsg( modal, text, isError ) {
        var msg = modal.querySelector( '[data-tt-guest-modal-msg]' );
        if ( ! msg ) return;
        msg.hidden = false;
        msg.textContent = text;
        msg.classList.toggle( 'tt-guest-modal-msg--error', !! isError );
    }

    function buildPayload( modal ) {
        var tab = activeTab( modal );
        if ( tab === 'linked' ) {
            // PlayerSearchPickerComponent renders a hidden input keyed by
            // name (no static id), so we look it up by name attribute.
            var hidden = modal.querySelector( 'input[name="tt_guest_linked_player_id"]' );
            var pid = hidden ? parseInt( hidden.value || '0', 10 ) : 0;
            if ( ! pid ) return { error: STR.linkedRequired || 'Pick a player.' };
            return { guest_player_id: pid };
        }
        var name = ( modal.querySelector( '#tt-guest-anon-name' ).value || '' ).trim();
        if ( ! name ) return { error: STR.nameRequired || 'Name is required.' };
        var age   = ( modal.querySelector( '#tt-guest-anon-age' ).value || '' ).trim();
        var pos   = ( modal.querySelector( '#tt-guest-anon-position' ).value || '' ).trim();
        var body  = { guest_name: name };
        if ( age ) body.guest_age = parseInt( age, 10 );
        if ( pos ) body.guest_position = pos;
        return body;
    }

    function postGuest( sessionId, body ) {
        return fetch( REST_NS + '/activities/' + sessionId + '/guests', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   NONCE,
            },
            credentials: 'same-origin',
            body: JSON.stringify( body ),
        } ).then( function ( r ) {
            return r.json().then( function ( j ) { return { ok: r.ok, status: r.status, body: j }; } );
        } );
    }

    function appendGuestRow( table, guest ) {
        if ( ! table || ! guest || ! guest.id ) return;
        var tbody = table.querySelector( 'tbody' );
        if ( ! tbody ) return;
        var tr = document.createElement( 'tr' );
        tr.className = 'tt-attendance-row tt-attendance-row--guest';
        tr.setAttribute( 'data-tt-attendance-id', String( guest.id ) );
        tr.setAttribute( 'data-is-guest', '1' );

        var label = guest.guest_player_id
            ? ( guest.player_name || ( STR.linkedFallback || 'Guest' ) )
            : ( guest.guest_name  || ( STR.anonFallback   || 'Guest' ) );
        var sub = guest.guest_player_id
            ? ( guest.home_team || '' )
            : ( STR.unaffiliated || '(unaffiliated)' );

        var nameTd = document.createElement( 'td' );
        nameTd.setAttribute( 'data-label', STR.player || 'Player' );
        nameTd.innerHTML =
            '<em>' + escapeHtml( label ) + '</em> ' +
            '<span class="tt-guest-badge">' + escapeHtml( STR.guestBadge || 'Guest' ) + '</span>' +
            ( sub ? '<div class="tt-guest-subline">' + escapeHtml( sub ) + '</div>' : '' );
        tr.appendChild( nameTd );

        var statusTd = document.createElement( 'td' );
        statusTd.setAttribute( 'data-label', STR.status || 'Status' );
        statusTd.textContent = guest.status || 'Present';
        tr.appendChild( statusTd );

        var notesTd = document.createElement( 'td' );
        notesTd.setAttribute( 'data-label', STR.notes || 'Notes' );
        if ( guest.guest_player_id ) {
            notesTd.innerHTML =
                '<a href="#" data-tt-guest-eval="' + guest.guest_player_id + '">' +
                escapeHtml( STR.evaluate || 'Evaluate' ) + '</a>' +
                '  <button type="button" class="tt-btn-link" data-tt-guest-remove="' + guest.id + '">' +
                escapeHtml( STR.remove || 'Remove' ) + '</button>';
        } else {
            notesTd.innerHTML =
                '<input type="text" class="tt-input tt-guest-notes-input" ' +
                ' data-tt-guest-notes-id="' + guest.id + '" placeholder="' +
                escapeHtml( STR.notesPlaceholder || 'Notes…' ) + '" />' +
                '<div class="tt-guest-row-actions">' +
                '<a href="#" data-tt-guest-promote="' + guest.id + '">' +
                escapeHtml( STR.promote || 'Add as player' ) + '</a> · ' +
                '<button type="button" class="tt-btn-link" data-tt-guest-remove="' + guest.id + '">' +
                escapeHtml( STR.remove || 'Remove' ) + '</button>' +
                '</div>';
        }
        tr.appendChild( notesTd );
        tbody.appendChild( tr );
    }

    function escapeHtml( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    function patchAttendance( id, body ) {
        return fetch( REST_NS + '/attendance/' + id, {
            method:  'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   NONCE,
            },
            credentials: 'same-origin',
            body: JSON.stringify( body ),
        } );
    }

    function deleteAttendance( id ) {
        return fetch( REST_NS + '/attendance/' + id, {
            method:  'DELETE',
            headers: { 'X-WP-Nonce': NONCE },
            credentials: 'same-origin',
        } );
    }

    /* #0037 — when the activity hasn't been saved yet, the "+ Add guest"
     * trigger needs to do two things:
     *   1. Save the activity form (POST /activities)
     *   2. Redirect to the edit URL with `&open_guest=1` so the modal
     *      pops back open with the new activity id wired in.
     * The redirect is the cheap way to avoid in-place state surgery on
     * the form (data-rest-method PUT vs POST, data-rest-path, the
     * guest-section data-attr, the URL bar). One full page nav, then
     * everything is "edit mode" and the existing flow takes over.
     */
    function saveActivityThenOpenGuest( modalEl, btn ) {
        var form = document.querySelector( 'form.tt-activity-form' );
        if ( ! form ) { openModal( modalEl ); return; }

        // Validate required fields up-front so we don't POST a partial.
        if ( typeof form.reportValidity === 'function' && ! form.reportValidity() ) {
            return;
        }

        if ( btn ) btn.disabled = true;
        var sub = function ( r ) {
            if ( btn ) btn.disabled = false;
            var newId = r && r.json && r.json.data && r.json.data.id;
            if ( ! r.ok || ! newId ) {
                showMsg( modalEl, ( r && r.json && r.json.message ) || ( STR.saveFailed || 'Could not save activity.' ), true );
                openModal( modalEl );
                return;
            }
            try {
                var url = new URL( window.location.href );
                url.searchParams.set( 'tt_view',    'activities' );
                url.searchParams.set( 'id',         String( newId ) );
                url.searchParams.set( 'open_guest', '1' );
                url.searchParams.delete( 'action' );
                window.location.href = url.toString();
            } catch ( _e ) {
                window.location.search = '?tt_view=activities&id=' + newId + '&open_guest=1';
            }
        };

        // Mirror public.js's formToJSON helper inline (avoids cross-file
        // coupling). att[<pid>][status]/[notes] arrays handled.
        var data = {};
        Array.prototype.forEach.call( form.querySelectorAll( 'input,select,textarea' ), function ( el ) {
            if ( ! el.name || el.disabled ) return;
            var m = el.name.match( /^([^\[]+)\[(\d+)\]\[([^\]]+)\]$/ );
            if ( m ) {
                if ( ! data[ m[1] ] ) data[ m[1] ] = {};
                if ( ! data[ m[1] ][ m[2] ] ) data[ m[1] ][ m[2] ] = {};
                data[ m[1] ][ m[2] ][ m[3] ] = el.value;
                return;
            }
            if ( el.type === 'checkbox' ) { data[ el.name ] = el.checked; return; }
            data[ el.name ] = el.value;
        } );

        fetch( REST_NS + '/activities', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            credentials: 'same-origin',
            body: JSON.stringify( data ),
        } ).then( function ( r ) {
            return r.json().then( function ( j ) { sub( { ok: r.ok, status: r.status, json: j } ); } );
        } ).catch( function () {
            if ( btn ) btn.disabled = false;
            showMsg( modalEl, STR.networkError || 'Network error.', true );
            openModal( modalEl );
        } );
    }

    document.addEventListener( 'click', function ( e ) {
        var target = e.target;

        // Open
        var trigger = target.closest && target.closest( '[data-tt-guest-modal-open]' );
        if ( trigger ) {
            e.preventDefault();
            var modal = $( '[data-tt-guest-modal]' );
            if ( ! modal ) return;
            if ( readSessionId( modal ) > 0 ) {
                openModal( modal );
            } else {
                // Create-mode: save the activity first, then come back via
                // the open_guest=1 query param.
                saveActivityThenOpenGuest( modal, trigger );
            }
            return;
        }

        // Close
        var closeBtn = target.closest && target.closest( '[data-tt-guest-modal-close]' );
        if ( closeBtn ) {
            e.preventDefault();
            var m = closeBtn.closest( '[data-tt-guest-modal]' );
            if ( m ) closeModal( m );
            return;
        }

        // Tab switch
        var tabBtn = target.closest && target.closest( '[data-tt-guest-tab]' );
        if ( tabBtn ) {
            e.preventDefault();
            switchTab( tabBtn.closest( '[data-tt-guest-modal]' ), tabBtn.getAttribute( 'data-tt-guest-tab' ) );
            return;
        }

        // Submit
        var submitBtn = target.closest && target.closest( '[data-tt-guest-modal-submit]' );
        if ( submitBtn ) {
            e.preventDefault();
            var modalEl = submitBtn.closest( '[data-tt-guest-modal]' );
            var sessionId = readSessionId( modalEl );
            if ( ! sessionId ) {
                // Defensive: in normal create flow the modal can't open
                // without an id (saveActivityThenOpenGuest redirects
                // first). If a caller forces it open manually, tell
                // them to save and bail out.
                showMsg( modalEl, STR.saveFirst || 'Saving activity first…', true );
                return;
            }
            var payload = buildPayload( modalEl );
            if ( payload.error ) { showMsg( modalEl, payload.error, true ); return; }
            submitBtn.disabled = true;
            postGuest( sessionId, payload ).then( function ( resp ) {
                submitBtn.disabled = false;
                if ( ! resp.ok ) {
                    // RestResponse error envelope is { errors: [{ code, message }] };
                    // older code read body.message which never existed, so every
                    // failure surfaced the generic STR.saveFailed message and
                    // hid the actual reason. Read errors[0].message; fall back
                    // to body.message (legacy callers) then to the generic.
                    var msg = '';
                    if ( resp.body ) {
                        if ( Array.isArray( resp.body.errors ) && resp.body.errors[0] && resp.body.errors[0].message ) {
                            msg = resp.body.errors[0].message;
                        } else if ( resp.body.message ) {
                            msg = resp.body.message;
                        }
                    }
                    showMsg( modalEl, msg || ( STR.saveFailed || 'Could not add guest.' ), true );
                    return;
                }
                appendGuestRow( document.querySelector( '[data-tt-guest-table]' ), resp.body );
                closeModal( modalEl );
            } ).catch( function () {
                submitBtn.disabled = false;
                showMsg( modalEl, STR.networkError || 'Network error.', true );
            } );
            return;
        }

        // Remove guest
        var removeBtn = target.closest && target.closest( '[data-tt-guest-remove]' );
        if ( removeBtn ) {
            e.preventDefault();
            if ( ! window.confirm( STR.confirmRemove || 'Remove this guest?' ) ) return;
            var rid = parseInt( removeBtn.getAttribute( 'data-tt-guest-remove' ), 10 );
            deleteAttendance( rid ).then( function ( r ) {
                if ( r.ok ) {
                    var row = removeBtn.closest( 'tr' );
                    if ( row ) row.parentNode.removeChild( row );
                }
            } );
            return;
        }
    } );

    // Inline notes save on blur for anonymous guests.
    document.addEventListener( 'blur', function ( e ) {
        var input = e.target;
        if ( ! input || ! input.matches || ! input.matches( '[data-tt-guest-notes-id]' ) ) return;
        var id = parseInt( input.getAttribute( 'data-tt-guest-notes-id' ), 10 );
        if ( ! id ) return;
        patchAttendance( id, { guest_notes: input.value } );
    }, true );

    // Initialize anonymous-notes inputs from data-initial.
    document.addEventListener( 'DOMContentLoaded', function () {
        $$( '[data-tt-guest-notes-id]' ).forEach( function ( inp ) {
            var initial = inp.getAttribute( 'data-initial' );
            if ( initial != null ) inp.value = initial;
        } );

        // #0037 — landing on edit URL with `?open_guest=1` (set by the
        // create-flow auto-save) auto-opens the modal so the user
        // continues straight into picking a guest.
        try {
            var url = new URL( window.location.href );
            if ( url.searchParams.get( 'open_guest' ) === '1' ) {
                var modal = $( '[data-tt-guest-modal]' );
                if ( modal && readSessionId( modal ) > 0 ) {
                    openModal( modal );
                    // Clean the URL so a refresh doesn't re-pop the modal.
                    url.searchParams.delete( 'open_guest' );
                    window.history.replaceState( {}, '', url.toString() );
                }
            }
        } catch ( _e ) { /* noop */ }
    } );
} )();
