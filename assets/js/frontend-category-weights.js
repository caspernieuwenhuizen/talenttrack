/**
 * TalentTrack — category weights, live total (#2977)
 *
 * The rule the screen enforces is "these must add up to 100". Reporting that
 * after a failed save is the worst moment to say it, so the sum is shown
 * while typing and Save is held until it is right.
 *
 * The wp-admin original does the same thing in jQuery. This is the same
 * behaviour in vanilla JS, per CLAUDE.md §2 — no jQuery for new code.
 *
 * Progressive: with JavaScript off the inputs and Save still work, and the
 * server runs the identical `validateSumsTo100()` check and reports the total
 * in a flash message. Nothing here is the only thing standing between a bad
 * value and the database.
 */
( function () {
	'use strict';

	/**
	 * Copy comes from the server via `wp_localize_script`, never from a
	 * literal here — CLAUDE.md §4 is explicit that hardcoded English in JS
	 * is not translatable and therefore not shippable.
	 *
	 * The empty-string fallback is a deliberate degradation: if the localize
	 * object is somehow absent, the hint renders blank rather than in a
	 * language the reader did not choose. The colour and the total still
	 * carry the state.
	 */
	function i18n( key ) {
		var cfg = window.TT_CATEGORY_WEIGHTS;
		return ( cfg && cfg.i18n && cfg.i18n[ key ] ) || '';
	}

	function wire( form ) {
		var inputs = form.querySelectorAll( '.tt-cw-input' );
		var sumEl  = form.querySelector( '[data-tt-cw-sum="1"]' );
		var hintEl = form.querySelector( '[data-tt-cw-hint="1"]' );
		var save   = form.querySelector( '[data-tt-cw-save="1"]' );

		if ( ! inputs.length || ! sumEl ) return;

		function recompute() {
			var sum = 0;
			Array.prototype.forEach.call( inputs, function ( input ) {
				var v = parseInt( input.value, 10 );
				if ( ! isNaN( v ) ) sum += v;
			} );

			var ok = sum === 100;

			sumEl.textContent = sum + '%';
			form.classList.toggle( 'is-balanced', ok );
			form.classList.toggle( 'is-unbalanced', ! ok );

			if ( hintEl ) {
				hintEl.textContent = ok ? i18n( 'sum_ok' ) : i18n( 'sum_bad' );
			}

			// Disabled rather than hidden: the button staying put, greyed,
			// is what tells someone the form is nearly right. A button that
			// vanishes reads as a bug.
			if ( save ) save.disabled = ! ok;
		}

		Array.prototype.forEach.call( inputs, function ( input ) {
			input.addEventListener( 'input', recompute );
			input.addEventListener( 'change', recompute );
		} );

		recompute();
	}

	function init() {
		var forms = document.querySelectorAll( '[data-tt-cw-form="1"]' );
		Array.prototype.forEach.call( forms, wire );

		// Reset discards a configured set, so it asks first. Delegated so it
		// survives any later re-render of the panel.
		document.addEventListener( 'click', function ( e ) {
			var reset = e.target.closest && e.target.closest( '[data-tt-confirm]' );
			if ( ! reset ) return;
			if ( ! window.confirm( reset.getAttribute( 'data-tt-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
