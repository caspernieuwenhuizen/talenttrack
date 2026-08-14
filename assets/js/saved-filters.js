/* #2385 — personal saved filter presets for the standard reports' filter
   bar. Captures the live query on save, POSTs it, and reloads so the
   server-rendered list stays the source of truth. No globals beyond
   window.TT (nonce/rest) and window.TT_SavedFilters (config). */
( function () {
	'use strict';

	var cfg = window.TT_SavedFilters || {};
	var i18n = cfg.i18n || {};
	var keys = cfg.keys || [ 'period', 'activity_type_key', 'from', 'to', 'team_id', 'n' ];

	var rest = ( ( window.TT && window.TT.rest_url ) || '/wp-json/talenttrack/v1/' ).replace( /\/+$/, '/' );
	var nonce = ( window.TT && window.TT.rest_nonce ) || ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';

	function headers() {
		var h = { 'Content-Type': 'application/json' };
		if ( nonce ) { h['X-WP-Nonce'] = nonce; }
		return h;
	}

	// Whitelist the current URL's filter params into a plain object.
	function currentFilters() {
		var params = new URLSearchParams( window.location.search );
		var out = {};
		keys.forEach( function ( k ) {
			var v = params.get( k );
			if ( v !== null && v !== '' ) { out[ k ] = v; }
		} );
		return out;
	}

	function bind( root ) {
		var reportKey = root.getAttribute( 'data-report-key' ) || '';

		var toggle = root.querySelector( '[data-tt-preset-save-toggle]' );
		var form = root.querySelector( '[data-tt-preset-save-form]' );
		var nameInput = root.querySelector( '[data-tt-preset-name]' );
		var confirmBtn = root.querySelector( '[data-tt-preset-save-confirm]' );

		if ( toggle && form ) {
			toggle.addEventListener( 'click', function () {
				var hidden = form.hasAttribute( 'hidden' );
				if ( hidden ) { form.removeAttribute( 'hidden' ); } else { form.setAttribute( 'hidden', '' ); }
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
				fetch( rest + 'reports/filter-presets', {
					method: 'POST',
					headers: headers(),
					credentials: 'same-origin',
					body: JSON.stringify( {
						report_key: reportKey,
						name: name,
						filters: currentFilters()
					} )
				} ).then( function ( r ) {
					if ( ! r.ok ) { throw new Error( 'save' ); }
					// Reload so the server-rendered list picks up the new preset.
					window.location.reload();
				} ).catch( function () {
					confirmBtn.disabled = false;
					window.alert( i18n.error || 'Error.' );
				} );
			} );
		}

		root.querySelectorAll( '[data-tt-preset-delete]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var id = btn.getAttribute( 'data-tt-preset-delete' );
				if ( ! id ) { return; }
				if ( ! window.confirm( i18n.delete_confirm || 'Delete this saved view?' ) ) { return; }
				btn.disabled = true;
				fetch( rest + 'reports/filter-presets/' + encodeURIComponent( id ), {
					method: 'DELETE',
					headers: headers(),
					credentials: 'same-origin'
				} ).then( function ( r ) {
					if ( ! r.ok ) { throw new Error( 'delete' ); }
					var li = btn.closest( '.tt-saved-filters__item' );
					if ( li && li.parentNode ) { li.parentNode.removeChild( li ); }
				} ).catch( function () {
					btn.disabled = false;
					window.alert( i18n.error || 'Error.' );
				} );
			} );
		} );
	}

	function init() {
		document.querySelectorAll( '[data-tt-saved-filters]' ).forEach( bind );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
