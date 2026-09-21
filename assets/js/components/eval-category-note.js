/**
 * Evaluation category notes (#3949): the toggle opens and closes its
 * panel, the counter follows the text, and a toggle whose note has text
 * carries `.has-note` and the "Edit note on …" name.
 *
 * Delegated on the document, so the flat evaluation form and the wizard's
 * rating steps share one listener, including rows added after load.
 * Markup: `EvalCategoryNote::button()` / `::panel()`.
 */
( function () {
	'use strict';

	function panelFor( btn ) {
		var id = btn.getAttribute( 'aria-controls' );
		return id ? document.getElementById( id ) : null;
	}

	function syncButton( panel ) {
		if ( ! panel || ! panel.id ) return;
		var text = panel.querySelector( '[data-tt-evf-note-text]' );
		var btn  = document.querySelector( '[data-tt-evf-note-toggle][aria-controls="' + panel.id + '"]' );
		if ( ! text ) return;
		var has = text.value.trim() !== '';
		var count = panel.querySelector( '[data-tt-evf-note-count]' );
		if ( count ) {
			var max = parseInt( text.getAttribute( 'maxlength' ) || '500', 10 );
			count.textContent = text.value.length + '/' + max;
		}
		if ( btn ) {
			btn.classList.toggle( 'has-note', has );
			var name = btn.getAttribute( has ? 'data-label-edit' : 'data-label-add' );
			if ( name ) btn.setAttribute( 'aria-label', name );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target && e.target.closest ? e.target.closest( '[data-tt-evf-note-toggle]' ) : null;
		if ( ! btn ) return;
		var panel = panelFor( btn );
		if ( ! panel ) return;
		var open = btn.getAttribute( 'aria-expanded' ) !== 'true';
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		panel.hidden = ! open;
		if ( open ) {
			var text = panel.querySelector( '[data-tt-evf-note-text]' );
			if ( text ) text.focus();
		}
	} );

	document.addEventListener( 'input', function ( e ) {
		var t = e.target;
		if ( ! t || ! t.matches || ! t.matches( '[data-tt-evf-note-text]' ) ) return;
		syncButton( t.closest( '[data-tt-evf-note]' ) );
	} );

	// Escape in an open note closes it and returns focus to its toggle.
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Escape' ) return;
		var t = e.target;
		if ( ! t || ! t.matches || ! t.matches( '[data-tt-evf-note-text]' ) ) return;
		var panel = t.closest( '[data-tt-evf-note]' );
		if ( ! panel ) return;
		var btn = document.querySelector( '[data-tt-evf-note-toggle][aria-controls="' + panel.id + '"]' );
		panel.hidden = true;
		if ( btn ) {
			btn.setAttribute( 'aria-expanded', 'false' );
			btn.focus();
		}
	} );
} )();
