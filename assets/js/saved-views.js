/* #2448 / #2451 — personal saved filter views for the shared FilterBar.

   Captures the live query on save, POSTs it, and reloads so the
   server-rendered list stays the source of truth. Capture keys come from the
   strip's own data-keys attribute (FilterBar derives them from its group
   config), so two bars on one page can't clobber each other's vocabulary.

   #2451 — every chip carries one "…" manage control that opens a
   <dialog>-backed modal covering rename, overwrite and delete. Three separate
   icon buttons per chip could not meet the 48px touch floor side by side at
   360px, and a five-view strip would have carried fifteen of them. The modal
   also replaces window.confirm / window.alert, matching the pattern
   frontend-archive-button.js moved to in v3.110.104 — the native prompts are
   unstyled, unlocalised and easy to miss. window.* survives only as a
   fallback for a runtime without <dialog>.

   No globals beyond window.TT (nonce/rest) and window.TT_SavedViews (i18n). */
( function () {
	'use strict';

	var cfg = window.TT_SavedViews || {};
	var i18n = cfg.i18n || {};

	var rest = ( ( window.TT && window.TT.rest_url ) || '/wp-json/talenttrack/v1/' ).replace( /\/+$/, '/' );
	var nonce = ( window.TT && window.TT.rest_nonce ) || ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';

	var MANAGE_ID = 'tt-saved-views-manage-dialog';
	var NOTICE_ID = 'tt-saved-views-notice-dialog';

	function headers() {
		var h = { 'Content-Type': 'application/json' };
		if ( nonce ) { h['X-WP-Nonce'] = nonce; }
		return h;
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ];
		} );
	}

	function dialogSupported() {
		return typeof HTMLDialogElement !== 'undefined';
	}

	// --- Notice modal (replaces window.alert) --------------------------

	function ensureNotice() {
		var existing = document.getElementById( NOTICE_ID );
		if ( existing ) { return existing; }
		if ( ! dialogSupported() ) { return null; }

		var dialog = document.createElement( 'dialog' );
		dialog.id = NOTICE_ID;
		dialog.className = 'tt-modal tt-modal--saved-views';
		dialog.innerHTML =
			'<form method="dialog" class="tt-modal-form">' +
				'<h2 class="tt-modal-title">' + escapeHtml( i18n.notice_title || '' ) + '</h2>' +
				'<p class="tt-modal-message" data-tt-sv-notice-msg></p>' +
				'<div class="tt-modal-actions">' +
					'<button type="submit" value="ok" class="tt-btn tt-btn-primary">' +
						escapeHtml( i18n.ok || 'OK' ) + '</button>' +
				'</div>' +
			'</form>';
		document.body.appendChild( dialog );
		return dialog;
	}

	function notify( message ) {
		var dialog = ensureNotice();
		if ( ! dialog ) { window.alert( message ); return; }
		dialog.querySelector( '[data-tt-sv-notice-msg]' ).textContent = message;
		dialog.showModal();
	}

	var CONFIRM_ID = 'tt-saved-views-confirm-dialog';

	function ensureConfirm() {
		var existing = document.getElementById( CONFIRM_ID );
		if ( existing ) { return existing; }
		if ( ! dialogSupported() ) { return null; }

		var dialog = document.createElement( 'dialog' );
		dialog.id = CONFIRM_ID;
		dialog.className = 'tt-modal tt-modal--saved-views';
		dialog.innerHTML =
			'<form method="dialog" class="tt-modal-form">' +
				'<h2 class="tt-modal-title">' + escapeHtml( i18n.notice_title || '' ) + '</h2>' +
				'<p class="tt-modal-message" data-tt-sv-confirm-msg></p>' +
				'<div class="tt-modal-actions">' +
					'<button type="submit" value="cancel" class="tt-btn tt-btn-secondary">' +
						escapeHtml( i18n.cancel || 'Cancel' ) + '</button>' +
					'<button type="submit" value="confirm" class="tt-btn tt-btn-danger">' +
						escapeHtml( i18n.delete || 'Delete' ) + '</button>' +
				'</div>' +
			'</form>';
		document.body.appendChild( dialog );
		return dialog;
	}

	function confirmDestructive( message, onResult ) {
		var dialog = ensureConfirm();
		if ( ! dialog ) { onResult( window.confirm( message ) ); return; }
		dialog.querySelector( '[data-tt-sv-confirm-msg]' ).textContent = message;

		var closeHandler = function () {
			dialog.removeEventListener( 'close', closeHandler );
			onResult( dialog.returnValue === 'confirm' );
		};
		dialog.addEventListener( 'close', closeHandler );
		dialog.showModal();
	}

	function onError() {
		notify( i18n.error || 'Error.' );
	}

	/** Surface the server's own message when it sent one (duplicate name, …). */
	function failWith( response ) {
		response.json().then( function ( body ) {
			var msg = body && body.errors && body.errors[0] && body.errors[0].message;
			notify( msg || i18n.error || 'Error.' );
		} ).catch( onError );
	}

	// --- Manage modal (rename / overwrite / delete) --------------------

	function ensureManage() {
		var existing = document.getElementById( MANAGE_ID );
		if ( existing ) { return existing; }
		if ( ! dialogSupported() ) { return null; }

		var dialog = document.createElement( 'dialog' );
		dialog.id = MANAGE_ID;
		dialog.className = 'tt-modal tt-modal--saved-views';
		dialog.innerHTML =
			'<form method="dialog" class="tt-modal-form">' +
				'<h2 class="tt-modal-title">' + escapeHtml( i18n.manage_title || '' ) + '</h2>' +
				'<label class="tt-saved-views__field">' +
					'<span>' + escapeHtml( i18n.name_label || '' ) + '</span>' +
					'<input type="text" class="tt-saved-views__name" maxlength="120" autocomplete="off" data-tt-sv-name />' +
				'</label>' +
				'<label class="tt-modal-option">' +
					'<input type="checkbox" data-tt-sv-overwrite />' +
					'<span>' + escapeHtml( i18n.overwrite_label || '' ) + '</span>' +
				'</label>' +
				'<div class="tt-modal-actions tt-saved-views__modal-actions">' +
					'<button type="submit" value="cancel" class="tt-btn tt-btn-secondary">' +
						escapeHtml( i18n.cancel || 'Cancel' ) + '</button>' +
					'<button type="submit" value="delete" class="tt-btn tt-btn-danger" data-tt-sv-delete>' +
						escapeHtml( i18n.delete || 'Delete' ) + '</button>' +
					'<button type="submit" value="save" class="tt-btn tt-btn-primary">' +
						escapeHtml( i18n.save || 'Save' ) + '</button>' +
				'</div>' +
			'</form>';
		document.body.appendChild( dialog );
		return dialog;
	}

	/** onResult( action, name, overwrite ) — action: 'save' | 'delete' | '' */
	function openManage( currentName, onResult ) {
		var dialog = ensureManage();
		if ( ! dialog ) {
			var typed = window.prompt( i18n.name_label || '', currentName );
			if ( typed === null ) { onResult( '', '', false ); return; }
			onResult( 'save', String( typed ).trim(), false );
			return;
		}

		var nameEl = dialog.querySelector( '[data-tt-sv-name]' );
		var owEl   = dialog.querySelector( '[data-tt-sv-overwrite]' );
		nameEl.value = currentName;
		owEl.checked = false;

		var closeHandler = function () {
			dialog.removeEventListener( 'close', closeHandler );
			var action = dialog.returnValue;
			if ( action !== 'save' && action !== 'delete' ) { action = ''; }
			onResult( action, String( nameEl.value || '' ).trim(), !! owEl.checked );
		};
		dialog.addEventListener( 'close', closeHandler );
		dialog.showModal();
		nameEl.focus();
		nameEl.select();
	}

	// --- Filter capture -------------------------------------------------

	// Whitelist the current URL's filter params into a plain object. The key
	// list is per-strip, so a list view's filter[...] / search / sort params
	// are captured just as readily as a report's flat ones.
	function currentFilters( keys ) {
		var params = new URLSearchParams( window.location.search );
		var out = {};
		keys.forEach( function ( k ) {
			var v = params.get( k );
			if ( v !== null && v !== '' ) { out[ k ] = v; }
		} );
		return out;
	}

	function keysFor( root ) {
		var raw = root.getAttribute( 'data-keys' ) || '';
		return raw.split( ',' ).map( function ( s ) { return s.trim(); } )
			.filter( function ( s ) { return s !== ''; } );
	}

	function bind( root ) {
		var viewKey = root.getAttribute( 'data-view-key' ) || '';
		var keys = keysFor( root );

		var toggle = root.querySelector( '[data-tt-view-save-toggle]' );
		var form = root.querySelector( '[data-tt-view-save-form]' );
		var nameInput = root.querySelector( '[data-tt-view-name]' );
		var confirmBtn = root.querySelector( '[data-tt-view-save-confirm]' );

		if ( toggle && form ) {
			toggle.addEventListener( 'click', function () {
				var hidden = form.hasAttribute( 'hidden' );
				if ( hidden ) { form.removeAttribute( 'hidden' ); } else { form.setAttribute( 'hidden', '' ); }
				toggle.setAttribute( 'aria-expanded', hidden ? 'true' : 'false' );
				if ( hidden && nameInput ) { nameInput.focus(); }
			} );
		}

		if ( confirmBtn ) {
			confirmBtn.addEventListener( 'click', function () {
				var name = ( nameInput && nameInput.value || '' ).trim();
				if ( ! name ) {
					notify( i18n.name_required || 'Name required.' );
					if ( nameInput ) { nameInput.focus(); }
					return;
				}
				confirmBtn.disabled = true;
				fetch( rest + 'filter-presets', {
					method: 'POST',
					headers: headers(),
					credentials: 'same-origin',
					body: JSON.stringify( {
						view_key: viewKey,
						name: name,
						filters: currentFilters( keys )
					} )
				} ).then( function ( r ) {
					if ( ! r.ok ) { confirmBtn.disabled = false; failWith( r ); return; }
					// Reload so the server-rendered list picks up the new view.
					window.location.reload();
				} ).catch( function () {
					confirmBtn.disabled = false;
					onError();
				} );
			} );
		}

		root.querySelectorAll( '[data-tt-view-manage]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = btn.getAttribute( 'data-tt-view-manage' );
				var li = btn.closest( '.tt-saved-views__item' );
				var current = li ? ( li.getAttribute( 'data-tt-view-name' ) || '' ) : '';
				if ( ! id ) { return; }

				openManage( current, function ( action, name, overwrite ) {
					if ( action === 'delete' ) {
						// Second confirm: Delete sits beside Save in the same
						// modal, so a mis-tap must not be destructive.
						confirmDestructive( i18n.delete_confirm || 'Delete?', function ( ok ) {
							if ( ! ok ) { return; }
							btn.disabled = true;
							fetch( rest + 'filter-presets/' + encodeURIComponent( id ), {
								method: 'DELETE',
								headers: headers(),
								credentials: 'same-origin'
							} ).then( function ( r ) {
								if ( ! r.ok ) { btn.disabled = false; failWith( r ); return; }
								if ( li && li.parentNode ) { li.parentNode.removeChild( li ); }
							} ).catch( function () {
								btn.disabled = false;
								onError();
							} );
						} );
						return;
					}

					if ( action !== 'save' ) { return; }
					if ( ! name ) { notify( i18n.name_required || 'Name required.' ); return; }
					// Nothing asked for — don't spend a request on it.
					if ( name === current && ! overwrite ) { return; }

					var body = {};
					if ( name !== current ) { body.name = name; }
					if ( overwrite ) { body.filters = currentFilters( keys ); }

					btn.disabled = true;
					fetch( rest + 'filter-presets/' + encodeURIComponent( id ), {
						method: 'PATCH',
						headers: headers(),
						credentials: 'same-origin',
						body: JSON.stringify( body )
					} ).then( function ( r ) {
						if ( ! r.ok ) { btn.disabled = false; failWith( r ); return; }
						window.location.reload();
					} ).catch( function () {
						btn.disabled = false;
						onError();
					} );
				} );
			} );
		} );
	}

	function init() {
		document.querySelectorAll( '[data-tt-saved-views]' ).forEach( bind );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
