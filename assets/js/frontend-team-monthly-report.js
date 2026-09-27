/**
 * Report composition panel — the team monthly report (#3459, epic #3457) and
 * the player report, which enqueues the same file.
 *
 * The panel is a plain GET form and works without this file: tick sections,
 * press "Update report". With it, nothing reloads until that button is
 * pressed (#3988): ticking, unticking, reordering and switching the layout
 * are collected in the form and applied together. The selection then goes
 * into the URL as one readable `blocks=kpi,status,…` value rather than a run
 * of `blk[]` parameters, so the address a coach copies is the report they are
 * looking at. The window (`period` or `from`/`to`) rides along in the form's
 * hidden fields and is never lost.
 *
 * While the form differs from what the page shows, a hint beside the button
 * says so: the report below, the PDF and the snapshot still follow the last
 * applied selection. The ordering script signals a reorder with the
 * `tt-mr-panel-change` event, since moving a row fires no `change`.
 */
( function () {
	'use strict';

	var form = document.querySelector( '[data-tt-mr-panel]' );
	if ( ! form ) return;

	var pending = form.querySelector( '[data-tt-mr-pending]' );

	function params() {
		var out = new URLSearchParams();
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
			if ( el.type === 'submit' || el.type === 'button' ) return;
			// A `name[]` field carries several values; each one is kept.
			if ( el.name.slice( -2 ) === '[]' ) {
				out.append( el.name, el.value );
			} else {
				out.set( el.name, el.value );
			}
		} );

		out.set( 'blocks', blocks.join( ',' ) );
		return out;
	}

	var applied = params().toString();

	function refresh() {
		if ( ! pending ) return;
		pending.hidden = params().toString() === applied;
	}

	/*
	 * #4095 — an option the chosen layout cannot print is disabled, with the
	 * reason under it. The server renders that for the layout the page was
	 * opened with; switching the layout here re-evaluates it from the reasons
	 * each option carries per layout, so no rule is duplicated in script.
	 */
	function applyLayout() {
		var layout = form.querySelector( 'input[name="layout"]:checked' );
		if ( ! layout ) return;
		var key = layout.value.toLowerCase();

		Array.prototype.forEach.call( form.querySelectorAll( '[data-tt-mr-show-option]' ), function ( option ) {
			var why   = option.getAttribute( 'data-tt-mr-why-' + key ) || '';
			var input = option.querySelector( 'input' );
			var text  = option.querySelector( '[data-tt-mr-why]' );
			if ( input ) input.disabled = why !== '';
			if ( text ) text.textContent = why;
			option.classList.toggle( 'is-unavailable', why !== '' );
		} );

		var current = form.querySelector( 'input[name="opt_tests_show"]:checked' );
		if ( current && current.disabled ) {
			var fallback = form.querySelector( 'input[name="opt_tests_show"]:not(:disabled)' );
			if ( fallback ) fallback.checked = true;
		}

		Array.prototype.forEach.call( form.querySelectorAll( '[data-tt-mr-when-layout]' ), function ( el ) {
			el.hidden = el.getAttribute( 'data-tt-mr-when-layout' ) !== layout.value;
		} );
	}

	form.addEventListener( 'change', function ( e ) {
		if ( e.target && e.target.name === 'layout' ) applyLayout();
	} );
	form.addEventListener( 'change', refresh );
	form.addEventListener( 'tt-mr-panel-change', refresh );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var action = form.getAttribute( 'action' ) || window.location.pathname;
		var joiner = action.indexOf( '?' ) === -1 ? '?' : '&';
		window.location.assign( action + joiner + params().toString() );
	} );
} )();
