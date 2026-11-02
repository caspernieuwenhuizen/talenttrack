/*
 * TalentTrack — filter box on the Configuration landing (#4670). Narrows the
 * settings rows to those whose folded text contains what was typed, hides a
 * section with nothing left and shows the empty state when nothing matches.
 * Each row carries its folded text in data-tt-search, computed server-side by
 * SettingsRegistry::haystack(), so the rule lives in one place. Pure
 * enhancement: without this script every setting is listed.
 */
( function () {
	'use strict';

	var root  = document.querySelector( '[data-tt-settings]' );
	var input = root ? root.querySelector( '[data-tt-settings-filter]' ) : null;
	if ( ! input ) {
		return;
	}

	var rows     = Array.prototype.slice.call( root.querySelectorAll( '[data-tt-settings-entry]' ) );
	var sections = Array.prototype.slice.call( root.querySelectorAll( '[data-tt-settings-section]' ) );
	var empty    = root.querySelector( '[data-tt-settings-empty]' );

	// Same folding as the server: lowercase, accents removed, spaces collapsed.
	function fold( text ) {
		var s = String( text || '' );
		if ( s.normalize ) {
			s = s.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
		}
		return s.toLowerCase().replace( /\s+/g, ' ' ).trim();
	}

	function apply( raw ) {
		var q   = fold( raw );
		var any = false;

		rows.forEach( function ( row ) {
			var hit = q === '' || ( row.getAttribute( 'data-tt-search' ) || '' ).indexOf( q ) !== -1;
			row.hidden = ! hit;
			if ( hit ) {
				any = true;
			}
		} );

		sections.forEach( function ( section ) {
			section.hidden = ! section.querySelector( '[data-tt-settings-entry]:not([hidden])' );
		} );

		if ( empty ) {
			empty.hidden = any;
		}
	}

	input.addEventListener( 'input', function () {
		apply( input.value );
	} );

	// Escape clears the filter, as the native search field does.
	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && input.value !== '' ) {
			input.value = '';
			apply( '' );
			e.stopPropagation();
		}
	} );
}() );
