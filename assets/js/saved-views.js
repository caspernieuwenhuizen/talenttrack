/* #2448 — personal saved filter views for the shared FilterBar. Captures the
   live query on save, POSTs it, and reloads so the server-rendered list stays
   the source of truth. Promoted from saved-filters.js (#2385): the capture
   keys now come from the strip's own data-keys attribute (FilterBar derives
   them from its group config) instead of a hardcoded reports-only list, so
   two bars on one page can't clobber each other's vocabulary.
   No globals beyond window.TT (nonce/rest) and window.TT_SavedViews (i18n). */
( function () {
	'use strict';

	var cfg = window.TT_SavedViews || {};
	var i18n = cfg.i18n || {};

	var rest = ( ( window.TT && window.TT.rest_url ) || '/wp-json/talenttrack/v1/' ).replace( /\/+$/, '/' );
	var nonce = ( window.TT && window.TT.rest_nonce ) || ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';

	function headers() {
		var h = { 'Content-Type': 'application/json' };
		if ( nonce ) { h['X-WP-Nonce'] = nonce; }
		return h;
	}

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
					window.alert( i18n.name_required || 'Name required.' );
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
					if ( ! r.ok ) { throw new Error( 'save' ); }
					// Reload so the server-rendered list picks up the new view.
					window.location.reload();
				} ).catch( function () {
					confirmBtn.disabled = false;
					window.alert( i18n.error || 'Error.' );
				} );
			} );
		}

		root.querySelectorAll( '[data-tt-view-delete]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = btn.getAttribute( 'data-tt-view-delete' );
				if ( ! id ) { return; }
				if ( ! window.confirm( i18n.delete_confirm || 'Delete this saved view?' ) ) { return; }
				btn.disabled = true;
				fetch( rest + 'filter-presets/' + encodeURIComponent( id ), {
					method: 'DELETE',
					headers: headers(),
					credentials: 'same-origin'
				} ).then( function ( r ) {
					if ( ! r.ok ) { throw new Error( 'delete' ); }
					var li = btn.closest( '.tt-saved-views__item' );
					if ( li && li.parentNode ) { li.parentNode.removeChild( li ); }
				} ).catch( function () {
					btn.disabled = false;
					window.alert( i18n.error || 'Error.' );
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
