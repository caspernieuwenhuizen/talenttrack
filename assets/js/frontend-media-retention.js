/**
 * TalentTrack media retention review (#2666, epic #2589).
 *
 * Three actions on the review queue: remove an expired attachment, keep
 * it with a reason, or put a kept one back.
 *
 * Removal confirms first and says what will actually happen, because the
 * two outcomes are materially different: unlinking a squad photo from one
 * player leaves the photo alone, while unlinking the last thing pointing
 * at a portrait deletes the file for good. The server reports which
 * happened and the row says so.
 */
( function () {
	'use strict';

	var CFG = window.TT_MediaRetention || {};
	var I18N = CFG.i18n || {};

	function t( key, fallback ) {
		return I18N[ key ] || fallback || '';
	}

	function send( method, linkId, body, done ) {
		var xhr = new XMLHttpRequest();
		xhr.open( method, CFG.root + '/media/retention/' + linkId, true );
		xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce );
		if ( body ) xhr.setRequestHeader( 'Content-Type', 'application/json' );

		xhr.addEventListener( 'load', function () {
			var parsed = null;
			try { parsed = JSON.parse( xhr.responseText ); } catch ( e ) { parsed = null; }
			done( xhr.status >= 200 && xhr.status < 300, parsed );
		} );

		xhr.addEventListener( 'error', function () { done( false, null ); } );

		xhr.send( body ? JSON.stringify( body ) : null );
	}

	function fail( row, parsed ) {
		var message = t( 'failed', 'That could not be saved.' );
		if ( parsed && parsed.errors && parsed.errors[ 0 ] && parsed.errors[ 0 ].message ) {
			message = parsed.errors[ 0 ].message;
		}
		window.alert( message );
		setBusy( row, false );
	}

	function setBusy( row, busy ) {
		Array.prototype.forEach.call( row.querySelectorAll( 'button' ), function ( b ) {
			b.disabled = busy;
		} );
	}

	function replaceWithNote( row, text ) {
		var cell = row.querySelector( '.tt-media-retention__actions' ) || row.lastElementChild;
		if ( ! cell ) { row.remove(); return; }
		cell.textContent = text;
		row.classList.add( 'is-decided' );
	}

	document.addEventListener( 'click', function ( e ) {
		var row = e.target.closest( '[data-role="retention-row"]' );
		if ( ! row ) return;

		var linkId = row.getAttribute( 'data-link-id' );
		if ( ! linkId ) return;

		if ( e.target.closest( '[data-role="retention-remove"]' ) ) {
			if ( ! window.confirm( t( 'confirmRemove', 'Remove this?' ) ) ) return;
			setBusy( row, true );

			send( 'DELETE', linkId, null, function ( ok, parsed ) {
				if ( ! ok ) { fail( row, parsed ); return; }
				var gone = parsed && parsed.data && parsed.data.media_deleted;
				replaceWithNote( row, gone
					? t( 'removedGone', 'Removed, and the file was deleted.' )
					: t( 'removedKept', 'Removed from this player.' ) );
			} );
			return;
		}

		if ( e.target.closest( '[data-role="retention-keep"]' ) ) {
			var reason = window.prompt( t( 'askReason', 'Why is this being kept?' ) );
			if ( reason === null ) return;              // cancelled
			if ( ! reason.trim() ) { window.alert( t( 'askReason', 'A reason is required.' ) ); return; }

			setBusy( row, true );
			send( 'POST', linkId, { decision: 'keep', reason: reason }, function ( ok, parsed ) {
				if ( ! ok ) { fail( row, parsed ); return; }
				// Reload so it moves into the "kept on purpose" table with
				// its reason, rather than leaving the page half-true.
				window.location.reload();
			} );
			return;
		}

		if ( e.target.closest( '[data-role="retention-release"]' ) ) {
			setBusy( row, true );
			send( 'POST', linkId, { decision: 'release' }, function ( ok, parsed ) {
				if ( ! ok ) { fail( row, parsed ); return; }
				window.location.reload();
			} );
		}
	} );
} )();
