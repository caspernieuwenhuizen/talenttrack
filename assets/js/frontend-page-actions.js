/**
 * frontend-page-actions.js (#2830) — conveniences for the page-header
 * overflow menu.
 *
 * The menu is a <details>/<summary>, so it already opens on click, on Enter
 * and on Space, keeps focus on its trigger, and works with this file absent.
 * Everything here is the part HTML does not give us:
 *
 *   - Escape closes the open menu and returns focus to its trigger;
 *   - clicking outside closes it;
 *   - opening one menu closes any other (there is normally only one on a
 *     page, but a list view with per-row menus would otherwise stack them).
 *
 * No dependencies, no build step. Delegated listeners on the document, so a
 * menu rendered after load — by a REST refresh, say — needs no re-binding.
 */
( function () {
    'use strict';

    var SELECTOR = 'details[data-tt-actions-more]';

    function openMenus() {
        return Array.prototype.filter.call(
            document.querySelectorAll( SELECTOR ),
            function ( el ) { return el.open; }
        );
    }

    function close( menu, refocus ) {
        menu.open = false;
        if ( refocus ) {
            var trigger = menu.querySelector( 'summary' );
            if ( trigger ) trigger.focus();
        }
    }

    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key !== 'Escape' && e.key !== 'Esc' ) return;
        var open = openMenus();
        if ( ! open.length ) return;
        // Return focus to the trigger: closing a menu the keyboard opened
        // must not drop the user back at the top of the document.
        open.forEach( function ( menu ) { close( menu, true ); } );
    } );

    document.addEventListener( 'click', function ( e ) {
        var inside = e.target.closest ? e.target.closest( SELECTOR ) : null;
        openMenus().forEach( function ( menu ) {
            if ( menu !== inside ) close( menu, false );
        } );
    } );

    // `toggle` does not bubble, so it is captured rather than delegated.
    document.addEventListener( 'toggle', function ( e ) {
        var menu = e.target;
        if ( ! menu.matches || ! menu.matches( SELECTOR ) || ! menu.open ) return;
        openMenus().forEach( function ( other ) {
            if ( other !== menu ) close( other, false );
        } );
    }, true );
}() );
