/**
 * Player report — drag sections into order (#3962).
 *
 * The panel works without this file: each chosen section has Move up / Move
 * down links that reload the report in the new order. With it, a pointer can
 * also drag a chosen section to its place. Dropping it moves the row in the
 * page and submits the panel, which sends the sections in page order, so the
 * report comes back in the order the coach dragged into.
 *
 * Only chosen sections move, and never above the letterhead: the rows that
 * can be dragged are the ones the server marked draggable.
 */
( function () {
	'use strict';

	var list = document.querySelector( '[data-tt-pr-order]' );
	if ( ! list ) return;

	var form = list.closest( 'form' );
	var dragged = null;
	var before = '';

	function rows() {
		return Array.prototype.slice.call( list.querySelectorAll( '[data-tt-pr-row][draggable="true"]' ) );
	}

	function order() {
		return rows().map( function ( row ) { return row.getAttribute( 'data-tt-pr-row' ); } ).join( ',' );
	}

	function clearMarks() {
		rows().forEach( function ( row ) {
			row.classList.remove( 'is-drop-before', 'is-drop-after' );
		} );
	}

	list.addEventListener( 'dragstart', function ( e ) {
		var row = e.target.closest ? e.target.closest( '[data-tt-pr-row][draggable="true"]' ) : null;
		if ( ! row ) return;
		dragged = row;
		before = order();
		row.classList.add( 'is-dragging' );
		if ( e.dataTransfer ) {
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', row.getAttribute( 'data-tt-pr-row' ) );
		}
	} );

	list.addEventListener( 'dragover', function ( e ) {
		if ( ! dragged ) return;
		var row = e.target.closest ? e.target.closest( '[data-tt-pr-row][draggable="true"]' ) : null;
		if ( ! row || row === dragged ) return;
		e.preventDefault();
		var box = row.getBoundingClientRect();
		var after = e.clientY > box.top + box.height / 2;
		clearMarks();
		row.classList.add( after ? 'is-drop-after' : 'is-drop-before' );
		list.insertBefore( dragged, after ? row.nextSibling : row );
	} );

	list.addEventListener( 'drop', function ( e ) {
		if ( dragged ) e.preventDefault();
	} );

	list.addEventListener( 'dragend', function () {
		if ( ! dragged ) return;
		dragged.classList.remove( 'is-dragging' );
		clearMarks();
		dragged = null;
		if ( order() !== before && form ) {
			if ( typeof form.requestSubmit === 'function' ) {
				form.requestSubmit();
			} else {
				form.submit();
			}
		}
	} );
} )();
