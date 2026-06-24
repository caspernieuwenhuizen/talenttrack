/* ---------------------------------------------------------------------------
 * Deep-rate category accordion (#1732).
 *
 * Player-first new-evaluation rating step. Each main category is a
 * `<details class="tt-rate-cat">` with an editable main-category star row
 * and (optionally) sub-skill rows in its body, plus a read-only star + word
 * mirror in the collapsed summary.
 *
 * Two jobs, both per category:
 *   1. Sub-skill → main average: when a sub-skill rating changes, set the
 *      main category to the rounded average of its non-zero subs (mirrors
 *      RateActorsStep). The star widget repaints via the dispatched input.
 *   2. Summary mirror: reflect the main category's value into the summary's
 *      read-only stars + average word, so a collapsed category still shows
 *      its score. Driven off the same value, so collapsing never loses it.
 *
 * Star inputs are the shared RatingInputComponent (rating-input.js); this
 * script only reacts to their value changes — it never owns the value.
 * --------------------------------------------------------------------------- */

(function () {
    'use strict';

    function wireCat( cat ) {
        if ( cat.dataset.ttRateCatWired === '1' ) return;
        cat.dataset.ttRateCatWired = '1';

        var mainInput = cat.querySelector( '[data-tt-rate-main]' );

        function syncSummary() {
            if ( ! mainInput ) return;
            var empty = mainInput.hasAttribute( 'data-tt-rating-empty' ) || mainInput.value === '';
            var val   = parseFloat( mainInput.value );

            // Read-only stars mirror the main value.
            cat.querySelectorAll( '.tt-rate-cat-star' ).forEach( function ( star ) {
                var sv = parseFloat( star.getAttribute( 'data-value' ) );
                var on = ! empty && ! isNaN( val ) && ! isNaN( sv ) && val >= sv - 0.001;
                star.classList.toggle( 'is-on', on );
            } );

            // Average word mirrors the main row's qualitative readout.
            var avgEl = cat.querySelector( '[data-tt-rate-cat-avg]' );
            if ( avgEl ) {
                var readout = cat.querySelector( '.tt-rate-cat-row--main [data-tt-rating-readout]' );
                if ( empty || ! readout ) {
                    avgEl.innerHTML = '&mdash;';
                    avgEl.classList.add( 'tt-rate-cat-avg--unset' );
                } else {
                    avgEl.textContent = readout.textContent;
                    avgEl.classList.remove( 'tt-rate-cat-avg--unset' );
                }
            }
        }

        function recalcFromSubs() {
            if ( ! mainInput ) return;
            var subs = cat.querySelectorAll( '[data-tt-rate-sub-parent]' );
            var sum = 0, count = 0;
            subs.forEach( function ( s ) {
                if ( s.hasAttribute( 'data-tt-rating-empty' ) ) return;
                var v = parseFloat( s.value );
                if ( v > 0 ) { sum += v; count++; }
            } );
            if ( count === 0 ) return; // all subs cleared — leave main as-is
            var avg = Math.round( sum / count ); // whole star
            var max = parseFloat( mainInput.getAttribute( 'max' ) );
            if ( ! isNaN( max ) && avg > max ) avg = max;
            if ( String( avg ) === mainInput.value && ! mainInput.hasAttribute( 'data-tt-rating-empty' ) ) return;
            mainInput.value = String( avg );
            mainInput.removeAttribute( 'data-tt-rating-empty' );
            // Repaint the main stars + readout through the component's own
            // input-driven render; syncSummary then follows via the listener.
            mainInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
        }

        function onChange( e ) {
            if ( e.target && e.target.matches && e.target.matches( '[data-tt-rate-sub-parent]' ) ) {
                recalcFromSubs();
            }
            syncSummary();
        }

        cat.addEventListener( 'input', onChange );
        cat.addEventListener( 'change', onChange );

        // Initial paint — derive main from any pre-filled subs, then mirror.
        recalcFromSubs();
        syncSummary();
    }

    function init( root ) {
        ( root || document ).querySelectorAll( '.tt-rate-cat' ).forEach( wireCat );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () { init(); } );
    } else {
        init();
    }

    if ( typeof window !== 'undefined' ) {
        window.TT = window.TT || {};
        window.TT.EvalRateAccordion = { init: init };
    }
}());
