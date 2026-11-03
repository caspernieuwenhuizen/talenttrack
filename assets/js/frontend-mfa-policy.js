/*
 * TalentTrack — the academy-wide two-factor rule (#4707). Shows the line
 * under the form while a ticked role still has accounts that have not set up
 * two-factor authentication, so an administrator sees who a save reaches
 * before saving. The save itself is the shared .tt-ajax-form handler in
 * public.js. Without this script the line shows as it was when the page
 * loaded.
 */
( function () {
	'use strict';

	var form = document.querySelector( '[data-tt-mfa-policy]' );
	if ( ! form ) {
		return;
	}

	var notice = form.querySelector( '[data-tt-mfa-policy-notice]' );
	var boxes  = Array.prototype.slice.call( form.querySelectorAll( 'input[data-tt-mfa-unenrolled]' ) );
	if ( ! notice ) {
		return;
	}

	function update() {
		notice.hidden = ! boxes.some( function ( box ) {
			return box.checked && parseInt( box.getAttribute( 'data-tt-mfa-unenrolled' ), 10 ) > 0;
		} );
	}

	boxes.forEach( function ( box ) {
		box.addEventListener( 'change', update );
	} );
}() );
