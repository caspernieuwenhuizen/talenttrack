/**
 * My evaluations — open a row's category breakdown on demand (#3478).
 *
 * The breakdown used to be rendered into the page for every evaluation and
 * hidden; for a player with 208 evaluations that was 2.5 MB of HTML. Now the
 * toggle fetches it from GET /players/{id}/evaluations/{eid}/detail the first
 * time a row is opened, and caches it in the DOM for the rest of the visit.
 *
 * Delegated at document level so it keeps working if the dashboard re-renders
 * the list. Config arrives on window.TTMyEvaluations (wp_localize_script).
 */
( function () {
	'use strict';

	if ( window.__ttMyEvalsBound ) return;
	window.__ttMyEvalsBound = true;

	var cfg  = window.TTMyEvaluations || {};
	var i18n = cfg.i18n || {};

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) node.className = cls;
		if ( text !== undefined ) node.textContent = text;
		return node;
	}

	function formatRating( value ) {
		var n = Number( value );
		if ( ! isFinite( n ) ) return '';
		return n.toLocaleString( document.documentElement.lang || undefined, {
			minimumFractionDigits: 1,
			maximumFractionDigits: 1
		} );
	}

	function render( detail, groups ) {
		detail.textContent = '';
		if ( ! groups || ! groups.length ) {
			detail.appendChild( el( 'p', 'tt-mye-detail-empty', i18n.empty || '' ) );
			return;
		}
		groups.forEach( function ( group ) {
			var wrap = el( 'div', 'tt-mye-detail-group' );
			if ( group.label ) {
				wrap.appendChild( el( 'div', 'tt-mye-detail-heading', group.label ) );
			}
			// #3949 — the coach's note on the category, under its heading.
			if ( group.note ) {
				wrap.appendChild( el( 'p', 'tt-mye-detail-note', group.note ) );
			}
			var list = el( 'ul', 'tt-mye-detail-list' );
			( group.subs || [] ).forEach( function ( sub ) {
				var li = el( 'li' );
				li.appendChild( el( 'span', 'tt-mye-detail-label', sub.label ) );
				li.appendChild( el( 'span', 'tt-mye-detail-rating', sub.rating === null ? '—' : formatRating( sub.rating ) ) );
				if ( sub.note ) {
					li.appendChild( el( 'p', 'tt-mye-detail-note', sub.note ) );
				}
				list.appendChild( li );
			} );
			wrap.appendChild( list );
			detail.appendChild( wrap );
		} );
	}

	function load( btn, detail ) {
		var evalId = btn.getAttribute( 'data-eval-id' );
		if ( ! evalId || ! cfg.detailUrl ) return;

		detail.dataset.state = 'loading';
		detail.textContent   = '';
		detail.appendChild( el( 'p', 'tt-mye-detail-loading', i18n.loading || '' ) );

		fetch( cfg.detailUrl + encodeURIComponent( evalId ) + '/detail', {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce || '' }
		} )
			.then( function ( res ) {
				if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
				return res.json();
			} )
			.then( function ( data ) {
				render( detail, data && data.groups );
				detail.dataset.state = 'loaded';
			} )
			.catch( function () {
				// A failed fetch says so, and the next open tries again.
				detail.textContent = '';
				detail.appendChild( el( 'p', 'tt-mye-detail-error', i18n.error || '' ) );
				detail.dataset.state = '';
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target && e.target.closest ? e.target.closest( '[data-tt-mye-toggle]' ) : null;
		if ( ! btn ) return;

		var open   = btn.getAttribute( 'aria-expanded' ) === 'true';
		var detail = document.getElementById( btn.getAttribute( 'aria-controls' ) );
		btn.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		if ( ! detail ) return;

		detail.hidden = open;
		if ( ! open && detail.dataset.state !== 'loaded' && detail.dataset.state !== 'loading' ) {
			load( btn, detail );
		}
	} );
} )();
