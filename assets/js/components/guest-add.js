/* TalentTrack — Guest add modal (#0026).
 *
 * Hooks the "+ Add guest" button on a session-edit form to the guest
 * modal: tab switching, validation, REST POST to
 * /sessions/{id}/guests, and an inline DOM append on success so the
 * coach sees the new row immediately. */
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
        var linkedSel = modal.querySelector( '#tt_guest_linked_player_id' );
        if ( nameInput ) nameInput.value = '';
        if ( ageInput )  ageInput.value  = '';
        if ( posInput )  posInput.value  = '';
        if ( linkedSel ) linkedSel.value = '';
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
            var pid = parseInt( modal.querySelector( '#tt_guest_linked_player_id' ).value || '0', 10 );
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
        return fetch( REST_NS + '/sessions/' + sessionId + '/guests', {
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

    document.addEventListener( 'click', function ( e ) {
        var target = e.target;

        // Open
        var trigger = target.closest && target.closest( '[data-tt-guest-modal-open]' );
        if ( trigger ) {
            e.preventDefault();
            var modal = $( '[data-tt-guest-modal]' );
            if ( modal ) openModal( modal );
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
            var sessionId = parseInt( ( modalEl.getAttribute( 'data-session-id' ) || document.querySelector( '[data-tt-guest-session-id]' ) && document.querySelector( '[data-tt-guest-session-id]' ).getAttribute( 'data-tt-guest-session-id' ) || '0' ), 10 );
            if ( ! sessionId ) {
                showMsg( modalEl, STR.saveFirst || 'Save the session first, then add guests.', true );
                return;
            }
            var payload = buildPayload( modalEl );
            if ( payload.error ) { showMsg( modalEl, payload.error, true ); return; }
            submitBtn.disabled = true;
            postGuest( sessionId, payload ).then( function ( resp ) {
                submitBtn.disabled = false;
                if ( ! resp.ok ) {
                    showMsg( modalEl, ( resp.body && resp.body.message ) || ( STR.saveFailed || 'Could not add guest.' ), true );
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
    } );
} )();
