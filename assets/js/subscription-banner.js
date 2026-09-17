/**
 * TalentTrack — subscription banner dismissal (#3497).
 *
 * Hides the suspended / ended banner for the rest of the browser session.
 * Never permanently: the state the banner describes outlasts the banner.
 * The key carries the status, so a subscription that goes from suspended to
 * ended shows its new message even after the old one was hidden.
 */
( function () {
	'use strict';

	var banner = document.querySelector( '[data-tt-subscription-banner]' );
	if ( ! banner ) return;

	var key = 'tt_subscription_banner_hidden_' + banner.getAttribute( 'data-tt-subscription-banner' );

	try {
		if ( window.sessionStorage.getItem( key ) === '1' ) {
			banner.hidden = true;
			return;
		}
	} catch ( e ) {
		// Storage unavailable: keep the banner visible.
	}

	var button = banner.querySelector( '[data-tt-subscription-dismiss]' );
	if ( ! button ) return;

	button.addEventListener( 'click', function () {
		banner.hidden = true;
		try {
			window.sessionStorage.setItem( key, '1' );
		} catch ( e ) {
			// Hidden for this page view only.
		}
	} );
} )();
