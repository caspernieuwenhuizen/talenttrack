/**
 * Player report — put the chosen sections in order (#3962, #3988).
 *
 * The panel works without this file: each chosen section has Move up / Move
 * down links that reload the report in the new order. With it, ordering
 * happens in the page and nothing reloads until "Update report", which sends
 * the sections in page order:
 *
 * - a pointer can drag a chosen section to its place;
 * - Move up / Move down move the row in the page instead of following their
 *   link, so a section ticked a moment ago is not lost by pressing one;
 * - ticking a section on gives it a handle and arrows straight away, so it can
 *   be ordered before applying; unticking takes them away.
 *
 * Only chosen sections move, and never above the letterhead. The arrows'
 * names come from the row (`data-tt-pr-label-up` / `-down`), translated on the
 * server. A move tells the panel script with `tt-mr-panel-change`, which is
 * what shows the "not applied yet" hint.
 */
( function () {
	'use strict';

	var list = document.querySelector( '[data-tt-pr-order]' );
	if ( ! list ) return;

	var form = list.closest( 'form' );
	var dragged = null;
	var before = '';

	function allRows() {
		return Array.prototype.slice.call( list.querySelectorAll( '[data-tt-pr-row]' ) );
	}

	function rows() {
		return Array.prototype.slice.call( list.querySelectorAll( '[data-tt-pr-row][draggable="true"]' ) );
	}

	function order() {
		return rows().map( function ( row ) { return row.getAttribute( 'data-tt-pr-row' ); } ).join( ',' );
	}

	function changed() {
		if ( ! form ) return;
		var ev;
		if ( typeof window.CustomEvent === 'function' ) {
			ev = new CustomEvent( 'tt-mr-panel-change', { bubbles: true } );
		} else {
			ev = document.createEvent( 'Event' );
			ev.initEvent( 'tt-mr-panel-change', true, false );
		}
		form.dispatchEvent( ev );
	}

	function moveButton( row, dir ) {
		var label = row.getAttribute( 'data-tt-pr-label-' + dir ) || '';
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'tt-pr-order__move';
		btn.setAttribute( 'data-tt-pr-move', dir );
		btn.setAttribute( 'aria-label', label );
		btn.title = label;
		btn.textContent = dir === 'up' ? '↑' : '↓';
		return btn;
	}

	/*
	 * A chosen row gets a handle and two arrow buttons; the server's links
	 * (and its inert placeholders at the ends) are replaced, because an arrow
	 * at the end of the list may be needed again after a move.
	 */
	function decorate( row ) {
		row.classList.add( 'is-on' );
		row.setAttribute( 'draggable', 'true' );

		if ( ! row.querySelector( '.tt-pr-order__handle' ) ) {
			var handle = document.createElement( 'span' );
			handle.className = 'tt-pr-order__handle';
			handle.setAttribute( 'aria-hidden', 'true' );
			handle.textContent = '⋮⋮';
			row.insertBefore( handle, row.firstChild );
		}

		var moves = row.querySelector( '.tt-pr-order__moves' );
		if ( ! moves ) {
			moves = document.createElement( 'span' );
			moves.className = 'tt-pr-order__moves';
			row.appendChild( moves );
		}
		if ( ! moves.querySelector( 'button[data-tt-pr-move]' ) ) {
			moves.textContent = '';
			moves.appendChild( moveButton( row, 'up' ) );
			moves.appendChild( moveButton( row, 'down' ) );
		}
	}

	function undecorate( row ) {
		row.classList.remove( 'is-on', 'is-dragging', 'is-drop-before', 'is-drop-after' );
		row.removeAttribute( 'draggable' );
		var handle = row.querySelector( '.tt-pr-order__handle' );
		if ( handle ) handle.parentNode.removeChild( handle );
		var moves = row.querySelector( '.tt-pr-order__moves' );
		if ( moves ) moves.parentNode.removeChild( moves );
	}

	/* The first chosen row cannot go up, the last cannot go down. */
	function markEnds() {
		var chosen = rows();
		chosen.forEach( function ( row, i ) {
			var up = row.querySelector( '[data-tt-pr-move="up"]' );
			var down = row.querySelector( '[data-tt-pr-move="down"]' );
			if ( up ) {
				up.classList.toggle( 'is-end', i === 0 );
				up.disabled = i === 0;
			}
			if ( down ) {
				down.classList.toggle( 'is-end', i === chosen.length - 1 );
				down.disabled = i === chosen.length - 1;
			}
		} );
	}

	function lastChosen() {
		var chosen = rows();
		return chosen.length ? chosen[ chosen.length - 1 ] : list.querySelector( '[data-tt-pr-row]' );
	}

	function placeAfter( row, anchor ) {
		if ( ! anchor || anchor === row ) return;
		list.insertBefore( row, anchor.nextSibling );
	}

	rows().forEach( decorate );
	markEnds();

	// Ticking a section on puts it at the end of the chosen ones, ready to be
	// moved; unticking takes it out of the order.
	list.addEventListener( 'change', function ( e ) {
		var box = e.target;
		if ( ! box || box.name !== 'blk[]' || box.type !== 'checkbox' ) return;
		var row = box.closest( '[data-tt-pr-row]' );
		if ( ! row ) return;
		if ( box.checked ) {
			var anchor = lastChosen();
			decorate( row );
			placeAfter( row, anchor );
		} else {
			undecorate( row );
			placeAfter( row, lastChosen() );
		}
		markEnds();
	} );

	list.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-tt-pr-move]' ) : null;
		if ( ! btn ) return;
		e.preventDefault();
		var row = btn.closest( '[data-tt-pr-row]' );
		var chosen = rows();
		var i = chosen.indexOf( row );
		if ( i === -1 ) return;
		var up = btn.getAttribute( 'data-tt-pr-move' ) === 'up';
		var other = chosen[ up ? i - 1 : i + 1 ];
		if ( ! other ) return;
		list.insertBefore( row, up ? other : other.nextSibling );
		markEnds();
		// Keep the keyboard where it was: on the same arrow of the moved row,
		// or the other arrow when this one just reached the end.
		var same = row.querySelector( '[data-tt-pr-move="' + ( up ? 'up' : 'down' ) + '"]' );
		var opposite = row.querySelector( '[data-tt-pr-move="' + ( up ? 'down' : 'up' ) + '"]' );
		if ( same && ! same.disabled ) {
			same.focus();
		} else if ( opposite ) {
			opposite.focus();
		}
		changed();
	} );

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
		allRows().forEach( function ( r ) { r.classList.remove( 'is-drop-before', 'is-drop-after' ); } );
		row.classList.add( after ? 'is-drop-after' : 'is-drop-before' );
		list.insertBefore( dragged, after ? row.nextSibling : row );
	} );

	list.addEventListener( 'drop', function ( e ) {
		if ( dragged ) e.preventDefault();
	} );

	list.addEventListener( 'dragend', function () {
		if ( ! dragged ) return;
		dragged.classList.remove( 'is-dragging' );
		allRows().forEach( function ( r ) { r.classList.remove( 'is-drop-before', 'is-drop-after' ); } );
		dragged = null;
		markEnds();
		if ( order() !== before ) changed();
	} );
} )();
