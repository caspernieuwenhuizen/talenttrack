/**
 * TalentTrack — page-actions overflow menu, keyboard behaviour (#2809)
 *
 * The menu itself is a native `<details>` / `<summary>` from #2830, which is
 * why it opens with JavaScript disabled and needs nothing here to be usable.
 * What a bare `<details>` does not give you is the behaviour people expect
 * of a menu:
 *
 *   - Escape closes it and puts focus back on the trigger
 *   - opening moves focus to the first item, so a keyboard user is not left
 *     tabbing through the page to reach what they just opened
 *   - clicking outside closes it, rather than leaving menus open behind you
 *
 * Everything here is additive. Remove this file and the menu still opens,
 * still closes, and still lists every action — which is the property that
 * let the markup ship before the behaviour did.
 */
( function () {
	'use strict';

	var SELECTOR = '[data-tt-actions-more]';

	function itemsIn( details ) {
		return details.querySelectorAll(
			'.tt-page-actions__more-menu a[href], .tt-page-actions__more-menu button'
		);
	}

	function close( details, refocus ) {
		if ( ! details.open ) return;
		details.open = false;
		if ( ! refocus ) return;
		var trigger = details.querySelector( 'summary' );
		if ( trigger ) trigger.focus();
	}

	function onToggle( e ) {
		var details = e.target;
		if ( ! details.open ) return;

		// Close any other open menu — two open at once is never intended,
		// and on a phone the second one lands on top of the first.
		document.querySelectorAll( SELECTOR ).forEach( function ( other ) {
			if ( other !== details ) close( other, false );
		} );

		var first = itemsIn( details )[ 0 ];
		if ( first ) first.focus();
	}

	function onKeydown( e ) {
		if ( e.key !== 'Escape' && e.key !== 'Esc' ) return;

		var open = document.querySelector( SELECTOR + '[open]' );
		if ( ! open ) return;

		// Only swallow the key when a menu was actually open, so Escape
		// keeps working for whatever else is listening for it.
		e.stopPropagation();
		close( open, true );
	}

	function onDocumentClick( e ) {
		document.querySelectorAll( SELECTOR + '[open]' ).forEach( function ( details ) {
			if ( ! details.contains( e.target ) ) close( details, false );
		} );
	}

	function init() {
		document.querySelectorAll( SELECTOR ).forEach( function ( details ) {
			details.addEventListener( 'toggle', onToggle );
		} );

		document.addEventListener( 'keydown', onKeydown );
		document.addEventListener( 'click', onDocumentClick );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
