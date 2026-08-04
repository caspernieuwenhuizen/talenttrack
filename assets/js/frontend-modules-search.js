/*
 * TalentTrack — client-side filter for the frontend Modules & features
 * page (#2300, ?tt_view=modules). Narrows the visible module cards and
 * their nested feature rows by label/description text. Pure enhancement:
 * with this script absent the full list simply renders unfiltered.
 */
( function () {
	'use strict';

	var input = document.getElementById( 'tt-modules-search-input' );
	if ( ! input ) {
		return;
	}

	var scope    = document.querySelector( '.tt-modules-form' ) || document;
	var cards    = Array.prototype.slice.call( scope.querySelectorAll( '.tt-module-card' ) );
	var sections = Array.prototype.slice.call( scope.querySelectorAll( '.tt-modules-cat' ) );
	var empty    = document.querySelector( '.tt-modules-empty' );

	function textOf( root, selector ) {
		var el = root.querySelector( selector );
		return el ? ( el.textContent || '' ) : '';
	}

	// Precompute the searchable text once, per card and per feature row.
	cards.forEach( function ( card ) {
		card.ttModuleText = ( textOf( card, '.tt-module-name' ) + ' ' + textOf( card, '.tt-module-desc' ) ).toLowerCase();
		card.ttFeatures   = Array.prototype.slice.call( card.querySelectorAll( '.tt-feature-item' ) );
		card.ttFeatures.forEach( function ( item ) {
			item.ttFeatureText = ( textOf( item, '.tt-feature-name' ) + ' ' + textOf( item, '.tt-feature-desc' ) ).toLowerCase();
		} );
		card.ttDetails = card.querySelector( '.tt-module-features' );
	} );

	function apply( raw ) {
		var q = raw.trim().toLowerCase();
		var anyVisible = false;

		cards.forEach( function ( card ) {
			var moduleHit   = q === '' || card.ttModuleText.indexOf( q ) !== -1;
			var featureHits = 0;

			card.ttFeatures.forEach( function ( item ) {
				var directHit = q !== '' && item.ttFeatureText.indexOf( q ) !== -1;
				if ( directHit ) {
					featureHits++;
				}
				// Show a feature row when the query is empty, when its parent
				// module name matched (keep the whole module), or when the row
				// itself matched.
				item.hidden = ! ( q === '' || moduleHit || directHit );
			} );

			var cardVisible = q === '' || moduleHit || featureHits > 0;
			card.hidden = ! cardVisible;
			if ( cardVisible ) {
				anyVisible = true;
			}

			// Auto-expand the feature panel when the match is a feature the
			// module name didn't cover, so the hit is visible without a manual
			// expand. Restore the collapsed default once the box is cleared.
			if ( card.ttDetails ) {
				if ( q !== '' && cardVisible && ! moduleHit && featureHits > 0 ) {
					card.ttDetails.open = true;
				} else if ( q === '' ) {
					card.ttDetails.open = false;
				}
			}
		} );

		sections.forEach( function ( section ) {
			section.hidden = ! section.querySelector( '.tt-module-card:not([hidden])' );
		} );

		if ( empty ) {
			empty.hidden = anyVisible;
		}
	}

	input.addEventListener( 'input', function () {
		apply( input.value );
	} );

	// Escape clears the filter (mirrors the native search-field clear).
	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && input.value !== '' ) {
			input.value = '';
			apply( '' );
			e.stopPropagation();
		}
	} );
}() );
