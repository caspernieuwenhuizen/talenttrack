/**
 * Team monthly report — composition panel (#3459, epic #3457).
 *
 * The panel is a plain GET form and works without this file: tick sections,
 * press "Update report". With it, a change re-renders straight away, and the
 * selection goes into the URL as one readable `blocks=kpi,status,…` value
 * rather than a run of `blk[]` parameters, so the address a coach copies is the
 * report they are looking at. The window (`period` or `from`/`to`) rides along
 * in the form's hidden fields and is never lost.
 */
( function () {
	'use strict';

	var form = document.querySelector( '[data-tt-mr-panel]' );
	if ( ! form ) return;

	form.classList.add( 'is-live' );

	function navigate() {
		var params = new URLSearchParams();
		var blocks = [];

		Array.prototype.forEach.call( form.elements, function ( el ) {
			if ( ! el.name || el.disabled ) return;
			if ( el.name === 'blk[]' ) {
				if ( ( el.type === 'checkbox' && el.checked ) || el.type === 'hidden' ) {
					if ( blocks.indexOf( el.value ) === -1 ) blocks.push( el.value );
				}
				return;
			}
			if ( ( el.type === 'radio' || el.type === 'checkbox' ) && ! el.checked ) return;
			if ( el.type === 'submit' ) return;
			params.set( el.name, el.value );
		} );

		params.set( 'blocks', blocks.join( ',' ) );

		var action = form.getAttribute( 'action' ) || window.location.pathname;
		var joiner = action.indexOf( '?' ) === -1 ? '?' : '&';
		window.location.assign( action + joiner + params.toString() );
	}

	form.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( ! t || ( t.name !== 'layout' && t.name !== 'blk[]' ) ) return;
		navigate();
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		navigate();
	} );
} )();
