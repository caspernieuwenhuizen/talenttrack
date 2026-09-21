/**
 * Evaluation form behaviour (`CoachForms::renderEvalForm()`).
 *
 * - Shows the match fields when the selected type asks for them.
 * - Low-rating comment policy: highlights every rating at or below the
 *   threshold and shows the warning while some low rating has neither a
 *   note on its own row nor staff notes; in `hard` mode it blocks the
 *   submit.
 * - Basic / Detailed control per category card.
 * - Recomputes a category's main rating from its sub ratings.
 * - Fills the player strip once a player is picked on the create form.
 *
 * Configuration is read from data attributes on the form, never from a
 * page global.
 */
( function () {
	'use strict';

	function num( v, fallback ) {
		var n = parseFloat( v );
		return isNaN( n ) ? fallback : n;
	}

	function initials( name ) {
		var parts = String( name ).trim().split( /\s+/ );
		var out = '';
		for ( var i = 0; i < parts.length && out.length < 2; i++ ) {
			if ( parts[ i ] !== '' ) out += parts[ i ].charAt( 0 ).toUpperCase();
		}
		return out !== '' ? out : '?';
	}

	function wire( form ) {
		var typeMeta = {};
		try {
			typeMeta = JSON.parse( form.getAttribute( 'data-tt-evf-type-meta' ) || '{}' ) || {};
		} catch ( e ) {
			typeMeta = {};
		}
		var lowThreshold = num( form.getAttribute( 'data-tt-evf-low-threshold' ), 3 );
		var lowMode      = form.getAttribute( 'data-tt-evf-low-mode' ) || 'soft';
		var ratingMin    = num( form.getAttribute( 'data-tt-evf-min' ), 0 );
		var ratingMax    = num( form.getAttribute( 'data-tt-evf-max' ), 10 );
		var ratingStep   = num( form.getAttribute( 'data-tt-evf-step' ), 1 );
		if ( ! ratingStep || ratingStep <= 0 ) ratingStep = 1;

		// Match fields follow the selected type.
		var typeSel     = form.querySelector( '#tt_fe_eval_type' );
		var matchFields = form.querySelector( '[data-tt-evf-match]' );
		if ( typeSel && matchFields ) {
			typeSel.addEventListener( 'change', function () {
				matchFields.hidden = String( typeMeta[ typeSel.value ] ) !== '1';
			} );
		}

		// Low-rating comment policy.
		var notesEl   = form.querySelector( '[data-tt-low-rating-notes]' );
		var warningEl = form.querySelector( '[data-tt-low-rating-warning]' );

		// #3949 — a low rating is explained by its own row's note: the sub
		// row's note for a sub rating, the card's note for a main rating.
		function ownNote( inp ) {
			var row = inp.closest( '.tt-evf-sub' );
			if ( row ) return row.querySelector( '[data-tt-evf-note-text]' );
			var card = inp.closest( '[data-tt-eval-cat]' );
			return card ? card.querySelector( '.tt-evf-cat__note [data-tt-evf-note-text]' ) : null;
		}

		function evaluate() {
			var triggered = false;
			form.querySelectorAll( 'input[type="number"][name^="ratings["]' ).forEach( function ( inp ) {
				var v = parseFloat( inp.value );
				var low = ! isNaN( v ) && v <= lowThreshold;
				inp.classList.toggle( 'is-low', low );
				if ( ! low ) return;
				var own = ownNote( inp );
				if ( ! ( own && own.value.trim() !== '' ) ) triggered = true;
			} );
			var notesEmpty = ! notesEl || notesEl.value.trim() === '';
			if ( warningEl ) warningEl.hidden = ! ( triggered && notesEmpty );
			return { triggered: triggered, notesEmpty: notesEmpty };
		}
		form.addEventListener( 'input', evaluate );
		evaluate();
		if ( lowMode === 'hard' ) {
			form.addEventListener( 'submit', function ( e ) {
				var s = evaluate();
				if ( s.triggered && s.notesEmpty ) {
					e.preventDefault();
					if ( notesEl ) notesEl.focus();
				}
			}, true );
		}

		// Basic / Detailed control. Hiding the sub ratings does not
		// unmount them, so values survive a flip back and forth.
		form.addEventListener( 'click', function ( e ) {
			var btn = e.target && e.target.closest ? e.target.closest( '[data-tt-rate-detail-toggle] button[data-mode]' ) : null;
			if ( ! btn ) return;
			var wrap = btn.closest( '[data-tt-rate-detail-toggle]' );
			var card = btn.closest( '[data-tt-eval-cat]' );
			var mode = btn.getAttribute( 'data-mode' );
			wrap.setAttribute( 'data-state', mode );
			wrap.querySelectorAll( 'button[data-mode]' ).forEach( function ( b ) {
				b.setAttribute( 'aria-selected', b === btn ? 'true' : 'false' );
			} );
			var panel = card ? card.querySelector( '[data-tt-rate-subs]' ) : null;
			if ( panel ) panel.hidden = mode !== 'detailed';
		} );

		// A sub rating recomputes its category's main rating as the mean
		// of the filled-in subs, rounded to the step and clamped to the
		// scale. The server stays authoritative on save.
		function roundToStep( val ) {
			return Math.round( val / ratingStep ) * ratingStep;
		}

		function recalcMainFromSubs( subInput ) {
			var panel = subInput.closest( '[data-tt-rate-subs]' );
			var card  = subInput.closest( '[data-tt-eval-cat]' );
			if ( ! panel || ! card ) return;
			var mainInput = card.querySelector( '[data-tt-evf-main-rating]' );
			if ( ! mainInput ) return;

			var sum = 0, count = 0;
			panel.querySelectorAll( 'input[type="number"][name^="ratings["]' ).forEach( function ( inp ) {
				var v = parseFloat( inp.value );
				if ( ! isNaN( v ) && v > 0 ) { sum += v; count += 1; }
			} );
			// Every sub cleared: leave a manually entered main alone.
			if ( count === 0 ) return;

			var avg = roundToStep( sum / count );
			if ( avg < ratingMin ) avg = ratingMin;
			if ( avg > ratingMax ) avg = ratingMax;
			var decimals = 0;
			if ( ratingStep < 1 ) decimals = Math.max( 0, -Math.floor( Math.log10( ratingStep ) ) );
			mainInput.value = decimals > 0 ? avg.toFixed( decimals ) : String( avg );
			mainInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}

		form.addEventListener( 'input', function ( e ) {
			var inp = e.target;
			if ( ! inp || ! inp.matches ) return;
			if ( ! inp.matches( '[data-tt-rate-subs] input[type="number"][name^="ratings["]' ) ) return;
			recalcMainFromSubs( inp );
		} );

		// Create form without a preset player: the strip fills in once a
		// player is picked. A picker row's label reads "Name — Team".
		var subject = form.querySelector( '[data-tt-evf-subject][data-tt-evf-subject-live]' );
		var picked  = form.querySelector( '[data-tt-psp-value]' );
		if ( subject && picked ) {
			var nameEl   = subject.querySelector( '[data-tt-evf-subject-name]' );
			var metaEl   = subject.querySelector( '[data-tt-evf-subject-meta]' );
			var avatarEl = subject.querySelector( '[data-tt-evf-subject-avatar]' );
			var dataEl   = form.querySelector( '[data-tt-psp-data]' );
			var rows     = [];
			try {
				rows = dataEl ? ( JSON.parse( dataEl.textContent || '[]' ) || [] ) : [];
			} catch ( e ) {
				rows = [];
			}
			// The picker fires `change` before it writes its own label, so
			// read the label from its data rather than from the DOM.
			var sync = function () {
				var id = parseInt( picked.value || '0', 10 );
				var label = '';
				for ( var i = 0; i < rows.length; i++ ) {
					if ( parseInt( rows[ i ].id, 10 ) === id ) { label = String( rows[ i ].label || '' ); break; }
				}
				if ( ! id || label === '' ) {
					subject.hidden = true;
					return;
				}
				var parts = label.split( ' — ' );
				var name  = parts[ 0 ];
				if ( nameEl ) nameEl.textContent = name;
				if ( metaEl ) metaEl.textContent = parts.length > 1 ? parts.slice( 1 ).join( ' — ' ) : '';
				if ( avatarEl ) avatarEl.textContent = initials( name );
				subject.hidden = false;
			};
			picked.addEventListener( 'change', sync );
			sync();
		}
	}

	function init() {
		var form = document.getElementById( 'tt-eval-form' );
		if ( form && form.hasAttribute( 'data-tt-evf' ) ) wire( form );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
