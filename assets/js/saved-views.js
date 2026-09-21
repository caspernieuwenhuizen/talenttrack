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
					'<button type="submit" value="confirm" class="tt-btn tt-btn-danger" data-tt-sv-confirm-btn></button>' +
				'</div>' +
			'</form>';
		document.body.appendChild( dialog );
		return dialog;
	}

	/*
	 * One confirm dialog for both questions it asks: deleting (a danger
	 * button) and replacing an existing view's filters (#3990, a primary one).
	 */
	function askConfirm( message, label, danger, onResult ) {
		var dialog = ensureConfirm();
		if ( ! dialog ) { onResult( window.confirm( message ) ); return; }
		dialog.querySelector( '[data-tt-sv-confirm-msg]' ).textContent = message;
		var btn = dialog.querySelector( '[data-tt-sv-confirm-btn]' );
		btn.textContent = label;
		btn.className = 'tt-btn ' + ( danger ? 'tt-btn-danger' : 'tt-btn-primary' );

		var closeHandler = function () {
			dialog.removeEventListener( 'close', closeHandler );
			onResult( dialog.returnValue === 'confirm' );
		};
		dialog.addEventListener( 'close', closeHandler );
		dialog.showModal();
	}

	function confirmDestructive( message, onResult ) {
		askConfirm( message, i18n.delete || 'Delete', true, onResult );
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

	// --- Manage modal (rename / default / delete) ----------------------
	// #3990 — replacing the filters left this dialog: it is the menu's own
	// "Update" action now, and saving under a taken name offers it too.

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
					'<input type="checkbox" data-tt-sv-default />' +
					'<span>' + escapeHtml( i18n.default_label || '' ) + '</span>' +
				'</label>' +
				'<p class="tt-saved-views__hint">' + escapeHtml( i18n.default_hint || '' ) + '</p>' +
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

	/** onResult( action, name, isDefault ) — action: 'save' | 'delete' | '' */
	function openManage( currentName, currentDefault, onResult ) {
		var dialog = ensureManage();
		if ( ! dialog ) {
			var typed = window.prompt( i18n.name_label || '', currentName );
			if ( typed === null ) { onResult( '', '', currentDefault ); return; }
			onResult( 'save', String( typed ).trim(), currentDefault );
			return;
		}

		var nameEl = dialog.querySelector( '[data-tt-sv-name]' );
		var defEl  = dialog.querySelector( '[data-tt-sv-default]' );
		nameEl.value = currentName;
		defEl.checked = !! currentDefault;

		var closeHandler = function () {
			dialog.removeEventListener( 'close', closeHandler );
			var action = dialog.returnValue;
			if ( action !== 'save' && action !== 'delete' ) { action = ''; }
			onResult( action, String( nameEl.value || '' ).trim(), !! defEl.checked );
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

	// #3990 — do the filters set now differ from a view's stored ones? Both
	// sides in the shape SavedViews::currentFilters() uses: strings, no
	// empties, no "no default" marker. Order does not matter.
	function differs( stored, current ) {
		var ignore = 'tt_views';
		var a = Object.keys( stored ).filter( function ( k ) { return k !== ignore && stored[ k ] !== '' && stored[ k ] !== null; } );
		var b = Object.keys( current ).filter( function ( k ) { return k !== ignore; } );
		if ( a.length !== b.length ) { return true; }
		return a.some( function ( k ) { return ! ( k in current ) || String( stored[ k ] ) !== String( current[ k ] ); } );
	}

	function patchFilters( id, filters, onDone ) {
		return fetch( rest + 'filter-presets/' + encodeURIComponent( id ), {
			method: 'PATCH',
			headers: headers(),
			credentials: 'same-origin',
			body: JSON.stringify( { filters: filters } )
		} ).then( function ( r ) {
			if ( ! r.ok ) { onDone( false ); failWith( r ); return; }
			window.location.reload();
		} ).catch( function () {
			onDone( false );
			onError();
		} );
	}

	/* A view of this reader's on this surface with this name, or null. */
	function viewNamed( root, name ) {
		var wanted = name.toLowerCase();
		var found = null;
		root.querySelectorAll( '.tt-saved-views__item[data-tt-view-id]' ).forEach( function ( li ) {
			if ( found ) { return; }
			if ( String( li.getAttribute( 'data-tt-view-name' ) || '' ).trim().toLowerCase() === wanted ) {
				found = { id: li.getAttribute( 'data-tt-view-id' ), name: li.getAttribute( 'data-tt-view-name' ) || name };
			}
		} );
		return found;
	}

	function bind( root ) {
		var viewKey = root.getAttribute( 'data-view-key' ) || '';
		var keys = keysFor( root );

		var toggle = root.querySelector( '[data-tt-view-save-toggle]' );
		var form = root.querySelector( '[data-tt-view-save-form]' );
		var nameInput = root.querySelector( '[data-tt-view-name]' );
		var confirmBtn = root.querySelector( '[data-tt-view-save-confirm]' );

		var cancelBtn = root.querySelector( '[data-tt-view-save-cancel]' );

		function closeSaveForm() {
			if ( ! form ) { return; }
			form.setAttribute( 'hidden', '' );
			if ( nameInput ) { nameInput.value = ''; }
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.focus();
			}
		}

		if ( toggle && form ) {
			toggle.addEventListener( 'click', function () {
				var hidden = form.hasAttribute( 'hidden' );
				if ( hidden ) { form.removeAttribute( 'hidden' ); } else { form.setAttribute( 'hidden', '' ); }
				toggle.setAttribute( 'aria-expanded', hidden ? 'true' : 'false' );
				if ( hidden && nameInput ) { nameInput.focus(); }
			} );
		}

		// #3296 — an explicit Save takes a real Cancel (CLAUDE.md §6). The
		// form used to sit inline in a strip you could click away from; in a
		// dropdown there is nothing to click away to, so backing out needs a
		// control. It clears the field as well as closing: a name left behind
		// would reappear the next time the form opened.
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', closeSaveForm );
		}

		if ( confirmBtn ) {
			confirmBtn.addEventListener( 'click', function () {
				var name = ( nameInput && nameInput.value || '' ).trim();
				if ( ! name ) {
					notify( i18n.name_required || 'Name required.' );
					if ( nameInput ) { nameInput.focus(); }
					return;
				}
				// #3990 — a name already taken is an offer to replace that
				// view's filters, not a refusal. Names are the reader's own on
				// this surface, all of them rendered as chips.
				var existing = viewNamed( root, name );
				if ( existing ) {
					var msg = ( i18n.replace_confirm || '%s' ).replace( '%s', existing.name );
					askConfirm( msg, i18n.replace || 'Replace', false, function ( ok ) {
						if ( ! ok ) { return; }
						confirmBtn.disabled = true;
						patchFilters( existing.id, currentFilters( keys ), function () { confirmBtn.disabled = false; } );
					} );
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

		// #3990 — "Update ‹name›": store the filters set now on the view the
		// reader opened. Only `filters` is sent, so its name and default flag
		// stay as they are. Shown only while the filters differ from it; a
		// list that filters in place changes them without a reload, so it is
		// re-checked whenever the menu opens.
		var updateBtn = root.querySelector( '[data-tt-view-update]' );
		if ( updateBtn ) {
			var stored = {};
			try { stored = JSON.parse( updateBtn.getAttribute( 'data-tt-view-filters' ) || '{}' ) || {}; } catch ( e ) { stored = {}; }

			var refreshUpdate = function () {
				var now = currentFilters( keys );
				var has = Object.keys( now ).some( function ( k ) { return k !== 'tt_views'; } );
				updateBtn.hidden = ! ( has && differs( stored, now ) );
			};
			var drop = root.querySelector( 'details' );
			if ( drop ) { drop.addEventListener( 'toggle', function () { if ( drop.open ) { refreshUpdate(); } } ); }

			updateBtn.addEventListener( 'click', function () {
				updateBtn.disabled = true;
				patchFilters( updateBtn.getAttribute( 'data-tt-view-update' ), currentFilters( keys ), function () { updateBtn.disabled = false; } );
			} );
		}

		root.querySelectorAll( '[data-tt-view-manage]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = btn.getAttribute( 'data-tt-view-manage' );
				// The manage button sits in the dropdown, not in the chip, so
				// the view's name and default flag are read off its chip.
				var li = btn.closest( '.tt-saved-views__item' )
					|| ( id ? root.querySelector( '.tt-saved-views__item[data-tt-view-id="' + id.replace( /[^0-9]/g, '' ) + '"]' ) : null );
				var current = li ? ( li.getAttribute( 'data-tt-view-name' ) || '' ) : '';
				var wasDefault = li ? li.getAttribute( 'data-tt-view-default' ) === '1' : false;
				if ( ! id ) { return; }

				openManage( current, wasDefault, function ( action, name, isDefault ) {
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
					if ( name === current && isDefault === wasDefault ) { return; }

					var body = {};
					if ( name !== current ) { body.name = name; }
					if ( isDefault !== wasDefault ) { body.is_default = isDefault; }

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
