/*
 * frontend-my-messages.js (#2606) — mark an in-app message read.
 *
 * One PATCH to `/comms/inbox/{id}`, so the page does not reload under
 * someone's thumb mid-scroll. The button only exists on unread messages,
 * and the surface renders correctly with this file absent — a reader with
 * no JavaScript still sees every message, they just cannot clear the
 * unread marker. That is a degraded convenience, not a lost feature.
 *
 * Vanilla, no build step, `TT`-prefixed globals only (CLAUDE.md §2).
 */
( function () {
    'use strict';

    var config = window.TTMyMessages;
    if ( ! config || ! config.root ) return;

    var i18n = config.i18n || {};

    function setUnreadCount( count ) {
        var node = document.querySelector( '[data-tt-unread-count]' );
        if ( ! node ) return;
        // The plural form is resolved server-side for the first paint; the
        // live update carries the short form, which reads the same at every
        // count and does not need one.
        node.textContent = count > 0
            ? ( i18n.unread || '%d unread' ).replace( '%d', String( count ) )
            : ( i18n.allRead || 'All read' );
    }

    function markRead( button ) {
        var id = button.getAttribute( 'data-tt-mark-read' );
        if ( ! id ) return;

        button.disabled = true;

        fetch( config.root + '/' + encodeURIComponent( id ), {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce || ''
            },
            body: JSON.stringify( { read: true } )
        } )
            .then( function ( response ) {
                if ( ! response.ok ) throw new Error( 'HTTP ' + response.status );
                return response.json();
            } )
            .then( function ( payload ) {
                var data = payload && payload.data ? payload.data : payload;
                var item = button.closest( '.tt-inbox-item' );
                if ( item ) item.classList.remove( 'is-unread' );

                var mark = document.createElement( 'p' );
                mark.className = 'tt-inbox-readmark';
                mark.textContent = i18n.read || 'Read';
                button.replaceWith( mark );

                if ( data && typeof data.unread_count === 'number' ) {
                    setUnreadCount( data.unread_count );
                }
            } )
            .catch( function () {
                // Say so rather than leaving a dead button: a control that
                // silently does nothing is worse than one that admits it.
                button.disabled = false;
                button.textContent = i18n.failed || 'That did not save. Try again.';
            } );
    }

    document.addEventListener( 'click', function ( event ) {
        var button = event.target.closest ? event.target.closest( '[data-tt-mark-read]' ) : null;
        if ( ! button ) return;
        event.preventDefault();
        markRead( button );
    } );
} )();
