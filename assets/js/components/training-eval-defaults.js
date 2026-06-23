/**
 * Training-eval defaults (#1643).
 *
 * When the evaluation Type dropdown is set to Training, surface the
 * mental main category first and pre-expanded. On any other type,
 * restore the mental category to its natural position and collapse it.
 *
 * Presentation default only — the coach can still rate any category and
 * is never blocked from saving. Policy (which type is "training", which
 * category is the priority) is decided server-side and handed in via the
 * TT_TRAINING_EVAL_DEFAULTS localized object; this script only moves DOM.
 *
 * Two surfaces, two DOM shapes, both handled defensively:
 *   - Flat coach form (CoachForms): a `<form id="tt-eval-form">` whose
 *     categories are `.tt-eval-cat-block[data-tt-eval-cat]` siblings and
 *     whose type select is `#tt_fe_eval_type`.
 *   - Player-first wizard (HybridDeepRateStep, #1732): each main category
 *     is a `<details class="tt-rate-cat">[data-tt-eval-cat-main]` carrying
 *     its sub-skills inside, an insertion anchor
 *     `[data-tt-eval-cats-anchor]`, and a type select `#tt_hdr_eval_type`.
 *     Surfacing the priority category means moving its `<details>` to the
 *     top and opening it.
 */
( function () {
	'use strict';

	var cfg = window.TT_TRAINING_EVAL_DEFAULTS || {};
	var trainingTypeId = parseInt( cfg.trainingTypeId, 10 ) || 0;
	var mentalId       = parseInt( cfg.mentalCategoryId, 10 ) || 0;

	if ( ! mentalId ) return; // nothing to prioritise

	function isTrainingValue( value ) {
		return trainingTypeId > 0 && parseInt( value, 10 ) === trainingTypeId;
	}

	function setToggleState( toggle, detailed ) {
		if ( ! toggle ) return;
		toggle.setAttribute( 'data-state', detailed ? 'detailed' : 'basic' );
		var btns = toggle.querySelectorAll( 'button[data-mode]' );
		Array.prototype.forEach.call( btns, function ( b ) {
			var want = detailed ? 'detailed' : 'basic';
			b.setAttribute( 'aria-selected', b.getAttribute( 'data-mode' ) === want ? 'true' : 'false' );
		} );
	}

	// --- Flat coach form ------------------------------------------------

	function wireFlatForm( form ) {
		var select = form.querySelector( '#tt_fe_eval_type' );
		if ( ! select ) return;

		var block = form.querySelector( '.tt-eval-cat-block[data-tt-eval-cat="' + mentalId + '"]' );
		if ( ! block ) return;

		// Remember the natural slot so a switch away from Training can
		// put it back where the server rendered it.
		var anchor = document.createComment( 'tt-mental-home' );
		if ( block.parentNode ) block.parentNode.insertBefore( anchor, block );

		function toTop() {
			var blocks = form.querySelectorAll( '.tt-eval-cat-block[data-tt-eval-cat]' );
			var first = blocks.length ? blocks[ 0 ] : null;
			if ( first && first !== block && first.parentNode ) {
				first.parentNode.insertBefore( block, first );
			}
			expand( true );
		}

		function restore() {
			if ( anchor.parentNode ) {
				anchor.parentNode.insertBefore( block, anchor.nextSibling );
			}
			expand( false );
		}

		function expand( detailed ) {
			var toggle = block.querySelector( '.tt-rate-detail-toggle' );
			var subs   = block.querySelector( '.tt-rate-subs' );
			if ( ! toggle || ! subs ) return; // no sub-categories — nothing to expand
			setToggleState( toggle, detailed );
			if ( detailed ) subs.removeAttribute( 'hidden' );
			else subs.setAttribute( 'hidden', '' );
		}

		function apply() {
			if ( isTrainingValue( select.value ) ) toTop();
			else restore();
		}

		select.addEventListener( 'change', apply );
		apply();
	}

	// --- Player-first wizard accordion (#1732) -------------------------

	function wireHybridAccordion( select ) {
		var mainCat = document.querySelector( 'details.tt-rate-cat[data-tt-eval-cat-main][data-tt-eval-cat="' + mentalId + '"]' );
		var insertAnchor = document.querySelector( '[data-tt-eval-cats-anchor]' );
		if ( ! mainCat || ! insertAnchor ) return;

		// Home marker so a switch away from Training restores the order.
		var homeMarker = document.createComment( 'tt-mental-home' );
		if ( mainCat.parentNode ) mainCat.parentNode.insertBefore( homeMarker, mainCat );

		function toTop() {
			var parent = insertAnchor.parentNode;
			if ( ! parent ) return;
			parent.insertBefore( mainCat, insertAnchor.nextSibling );
			mainCat.open = true;
		}

		function restore() {
			if ( homeMarker.parentNode ) {
				homeMarker.parentNode.insertBefore( mainCat, homeMarker.nextSibling );
			}
			mainCat.open = false;
		}

		function apply() {
			if ( isTrainingValue( select.value ) ) toTop();
			else restore();
		}

		select.addEventListener( 'change', apply );
		apply();
	}

	function init() {
		var flat = document.getElementById( 'tt-eval-form' );
		if ( flat ) wireFlatForm( flat );

		var hybrid = document.getElementById( 'tt_hdr_eval_type' );
		if ( hybrid ) wireHybridAccordion( hybrid );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
