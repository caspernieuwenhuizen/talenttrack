/**
 * frontend-report-schedules.js (#4963) — the schedule form's "What they get",
 * refreshed from `GET /reports/{id}/schedules/preview` as the recipients or
 * the period change. The server renders the first answer; this only keeps
 * it current. No other behaviour: the form posts without it.
 */
( function () {
	'use strict';

	var cfg  = window.TTReportSchedules || {};
	var form = document.querySelector( '[data-tt-rsch-form]' );
	var box  = document.querySelector( '[data-tt-rsch-preview]' );
	if ( ! form || ! box || ! cfg.preview ) return;

	var words = cfg.i18n || {};
	var timer = null;
	var asked = 0;

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) node.className = cls;
		if ( text !== undefined ) node.textContent = text;
		return node;
	}

	function chosen() {
		var out = [];
		form.querySelectorAll( '[name="recipients[]"]' ).forEach( function ( input ) {
			if ( input.tagName === 'SELECT' ) {
				Array.prototype.forEach.call( input.selectedOptions, function ( o ) { out.push( o.value ); } );
			} else if ( input.checked ) {
				out.push( input.value );
			}
		} );
		return out;
	}

	function draw( data ) {
		box.textContent = '';
		box.appendChild( el( 'h2', 'tt-rsch-preview-title', words.gets ) );
		if ( data.message ) {
			box.appendChild( el( 'p', 'tt-notice', data.message ) );
			return;
		}
		var n = Number( data.receivers || 0 );
		box.appendChild( el( 'p', 'tt-rsch-receivers', String( n === 1 ? words.receiversOne : words.receiversMany ).replace( '%d', String( n ) ) ) );
		// #5135 — who does not receive it, and why: the run's own skip list.
		( data.left_out_people || [] ).forEach( function ( group ) {
			var wrap = el( 'div', 'tt-rsch-skipped' );
			wrap.setAttribute( 'data-reason', group.reason );
			wrap.appendChild( el( 'p', 'tt-rsch-skipped-why', group.message ) );
			var names = group.names || [];
			if ( names.length && names.length <= Number( words.inline || 5 ) ) {
				var text = names.length === 1 ? names[ 0 ] : names.slice( 0, -1 ).join( words.comma ) + words.and + names[ names.length - 1 ];
				wrap.appendChild( el( 'p', 'tt-rsch-skipped-names', text ) );
			} else if ( names.length ) {
				var details = el( 'details', 'tt-rsch-skipped-list' );
				details.appendChild( el( 'summary', '', String( words.showNames ).replace( '%d', String( names.length ) ) ) );
				var list = el( 'ul' );
				names.forEach( function ( name ) { list.appendChild( el( 'li', '', name ) ); } );
				details.appendChild( list );
				wrap.appendChild( details );
			}
			box.appendChild( wrap );
		} );
		if ( ! data.blocks || ! data.blocks.length ) {
			box.appendChild( el( 'p', 'tt-notice', words.none ) );
		} else {
			var list = el( 'ul', 'tt-rsch-blocks' );
			data.blocks.forEach( function ( b ) { list.appendChild( el( 'li', '', b.label ) ); } );
			box.appendChild( list );
		}
		if ( data.left_out && data.left_out.length ) {
			box.appendChild( el( 'h3', 'tt-rsch-preview-sub', words.leftOut ) );
			var left = el( 'ul', 'tt-rsch-left' );
			data.left_out.forEach( function ( b ) {
				var li = el( 'li' );
				li.appendChild( el( 'span', 'tt-rsch-left-name', b.label ) );
				li.appendChild( document.createTextNode( ' ' ) );
				li.appendChild( el( 'span', 'tt-rsch-left-why', b.reason ) );
				left.appendChild( li );
			} );
			box.appendChild( left );
		}
	}

	function refresh() {
		var params = new URLSearchParams();
		chosen().forEach( function ( v ) { params.append( 'recipients[]', v ); } );
		var period = form.querySelector( '[data-tt-rsch-period]' );
		if ( period ) params.append( 'period', period.value );

		var mine = ++asked;
		box.setAttribute( 'aria-busy', 'true' );
		fetch( cfg.preview + ( cfg.preview.indexOf( '?' ) === -1 ? '?' : '&' ) + params.toString(), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( res ) {
			return res.json().then( function ( body ) { return { ok: res.ok, body: body }; } );
		} ).then( function ( r ) {
			if ( mine !== asked ) return;
			if ( r.ok && r.body && r.body.data ) {
				draw( r.body.data );
			} else {
				var message = r.body && r.body.errors && r.body.errors[0] ? r.body.errors[0].message : words.failed;
				draw( { message: message } );
			}
		} ).catch( function () {
			if ( mine === asked ) draw( { message: words.failed } );
		} ).then( function () {
			if ( mine === asked ) box.removeAttribute( 'aria-busy' );
		} );
	}

	form.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( ! t || ( t.name !== 'recipients[]' && ! t.hasAttribute( 'data-tt-rsch-period' ) ) ) return;
		window.clearTimeout( timer );
		timer = window.setTimeout( refresh, 300 );
	} );
}() );
