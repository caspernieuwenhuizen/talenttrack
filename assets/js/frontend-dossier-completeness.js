/*
 * frontend-dossier-completeness.js (#4145) — the "Open all / Close all"
 * control above the check cards on the Dossier completeness view.
 *
 * An enhancement only. Every card is a native <details> that opens and
 * closes by tap, Enter or Space without this file; the control ships with
 * the `hidden` attribute and is revealed here, so a page without
 * JavaScript never shows a button that does nothing.
 */
( function () {
    'use strict';

    function setAll( open ) {
        var cards = document.querySelectorAll( '[data-tt-dc-check]' );
        Array.prototype.forEach.call( cards, function ( card ) {
            card.open = open;
        } );
    }

    function init() {
        var control = document.querySelector( '[data-tt-dc-toggle-all]' );
        if ( ! control ) {
            return;
        }
        var openBtn  = control.querySelector( '[data-tt-dc-open-all]' );
        var closeBtn = control.querySelector( '[data-tt-dc-close-all]' );
        if ( ! openBtn || ! closeBtn ) {
            return;
        }

        openBtn.addEventListener( 'click', function () { setAll( true ); } );
        closeBtn.addEventListener( 'click', function () { setAll( false ); } );
        control.hidden = false;
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
