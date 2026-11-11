/*
 * my-pdp.js — season-timeline marker interaction for the player's My PDP
 * view (#1990). Each conversation marker is a <button> that toggles its
 * inline detail panel (aria-controls). Tapping a marker reveals that
 * conversation's detail in place — no long scroll. Keyboard-operable:
 * Enter/Space activate the button natively; Escape closes the open panel.
 *
 * Vanilla JS, no dependencies. Prefixed under TT. Degrades gracefully —
 * without JS the panels stay hidden (the [hidden] attribute), and the
 * self-reflection input + saved review below remain fully usable.
 */
( function () {
	'use strict';

	function closeAll( root, exceptBtn ) {
		var markers = root.querySelectorAll( '.tt-pdp-marker[aria-expanded="true"]' );
		Array.prototype.forEach.call( markers, function ( btn ) {
			if ( btn === exceptBtn ) {
				return;
			}
			btn.setAttribute( 'aria-expanded', 'false' );
			var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
			if ( panel ) {
				panel.hidden = true;
			}
		} );
	}

	function toggle( btn, root ) {
		var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
		if ( ! panel ) {
			return;
		}
		var open = btn.getAttribute( 'aria-expanded' ) === 'true';
		closeAll( root, btn );
		btn.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		panel.hidden = open;
	}

	function init( season ) {
		var markers = season.querySelectorAll( '.tt-pdp-marker' );
		Array.prototype.forEach.call( markers, function ( btn ) {
			btn.addEventListener( 'click', function () {
				toggle( btn, season );
			} );
		} );

		season.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Escape' && e.key !== 'Esc' ) {
				return;
			}
			var open = season.querySelector( '.tt-pdp-marker[aria-expanded="true"]' );
			if ( open ) {
				closeAll( season, null );
				open.focus();
			}
		} );
	}

	/*
	 * #4981 — the signed-off message links to `…&conv=N#tt-pdp-read-N`. The
	 * server draws that panel open; here the reader lands on the signed
	 * form's heading, scrolled to and focused, so a screen reader starts
	 * there too.
	 */
	function landOnReadView() {
		var hash = window.location.hash || '';
		if ( hash.indexOf( '#tt-pdp-read-' ) !== 0 ) {
			return;
		}
		var heading = document.getElementById( hash.slice( 1 ) );
		if ( ! heading ) {
			return;
		}
		var panel = heading.closest( '.tt-pdp-panel' );
		if ( panel && panel.hidden ) {
			var marker = document.querySelector( '.tt-pdp-marker[aria-controls="' + panel.id + '"]' );
			if ( marker ) {
				marker.setAttribute( 'aria-expanded', 'true' );
			}
			panel.hidden = false;
		}
		heading.scrollIntoView( { block: 'start' } );
		heading.focus( { preventScroll: true } );
	}

	function boot() {
		var seasons = document.querySelectorAll( '.tt-pdp-season' );
		Array.prototype.forEach.call( seasons, init );
		landOnReadView();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
