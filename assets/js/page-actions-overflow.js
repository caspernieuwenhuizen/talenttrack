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
 *   - choosing an item closes it
 *
 * Any `<details data-tt-actions-more>` gets this: the page-actions menu and
 * the record action row's menu (`RecordActionRow::renderMenu()`).
 *
 * Everything here is additive. Remove this file and the menu still opens,
 * still closes, and still lists every action — which is the property that
 * let the markup ship before the behaviour did.
 *
 * Every listener sits on the document, so a menu rendered after load (by a
 * REST refresh, say) needs no re-binding. This is the only script that
 * drives these menus (#4236).
 */
( function () {
	'use strict';

	var SELECTOR = '[data-tt-actions-more]';

	// The record action row's menu (#4231) uses the same <details> with its
	// own classes, so items are found by element, not by menu class. The
	// trigger is a <summary> and matches neither.
	var ITEM = 'a[href], button';

	function itemsIn( details ) {
		return details.querySelectorAll( ITEM );
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
		if ( ! details || ! details.matches || ! details.matches( SELECTOR ) ) return;
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

	// Choosing an item closes the menu. A link navigates anyway; a button
	// (Archive, Customize) opens something on this page, and the menu must
	// not stay open behind it.
	//
	// Capture phase, so this runs before the item's own handler. The archive
	// button opens a modal <dialog>, which hands focus back to whatever had
	// it when it opened: closing first puts that on the trigger, not on an
	// item that is hidden by the time the dialog closes.
	function onItemChoice( e ) {
		var item = e.target.closest ? e.target.closest( ITEM ) : null;
		if ( ! item ) return;

		var details = item.closest( SELECTOR + '[open]' );
		if ( details ) close( details, true );
	}

	// `toggle` does not bubble, so it is captured on the document rather
	// than bound to each menu.
	document.addEventListener( 'toggle', onToggle, true );
	document.addEventListener( 'keydown', onKeydown );
	document.addEventListener( 'click', onDocumentClick );
	document.addEventListener( 'click', onItemChoice, true );
} )();
