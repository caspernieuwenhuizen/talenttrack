/*
 * frontend-matrix.js (#2654) — the two things the matrix grid needs a
 * script for.
 *
 * 1. **Search.** The grid has a few hundred rows. Finding `player_notes`
 *    by scrolling is not finding it.
 * 2. **Feedback on a click.** The form saves on submit, not per cell, so
 *    without repainting the toggle a coach cannot tell whether their tap
 *    registered — and the "unsaved changes" pill is what stops them
 *    navigating away believing it was already saved.
 *
 * Everything else is server-rendered. No user-facing English lives here:
 * the one string the script writes is a count.
 */
( function () {
    'use strict';

    function init() {
        var form = document.getElementById( 'tt-matrix-form' );
        if ( !form ) { return; }

        paintOnChange( form );
        wireSearch( form );
    }

    /**
     * A cell the user just changed is no longer a shipped default,
     * whichever way it went — so it paints as edited or as off, never as
     * the quiet "came this way" green.
     */
    function paintOnChange( form ) {
        var dirty = document.querySelector( '[data-tt-matrix-dirty]' );

        form.addEventListener( 'change', function ( ev ) {
            var input = ev.target;
            if ( !input || !input.name ) { return; }

            if ( input.type === 'checkbox' && input.name.indexOf( 'cell[' ) === 0 ) {
                var label = input.closest( '.tt-matrix-toggle' );
                if ( label ) {
                    label.classList.remove( 'is-default' );
                    label.classList.toggle( 'is-on', input.checked );
                    label.classList.toggle( 'is-edited', input.checked );
                }
            } else if ( input.name.indexOf( 'scope[' ) !== 0 ) {
                return;
            }

            if ( dirty ) { dirty.hidden = false; }
        } );
    }

    /**
     * Filter rows by entity slug, label or owning module. A category
     * header hides when every row under it has gone, so the grid never
     * shows a heading over nothing.
     */
    function wireSearch( form ) {
        var input = document.getElementById( 'tt-matrix-search-input' );
        if ( !input ) { return; }

        var rows = Array.prototype.slice.call( form.querySelectorAll( '[data-tt-matrix-haystack]' ) );
        var heads = Array.prototype.slice.call( form.querySelectorAll( '.tt-matrix-cat' ) );
        var counter = document.querySelector( '[data-tt-matrix-count]' );
        var empty = form.querySelector( '.tt-matrix-empty' );
        var total = rows.length;

        function apply() {
            var query = ( input.value || '' ).trim().toLowerCase();
            var tokens = query.length ? query.split( /\s+/ ) : [];
            var visible = 0;

            rows.forEach( function ( row ) {
                var hay = row.getAttribute( 'data-tt-matrix-haystack' ) || '';
                var match = tokens.every( function ( t ) { return hay.indexOf( t ) !== -1; } );
                row.hidden = !match;
                if ( match ) { visible++; }
            } );

            heads.forEach( function ( head ) {
                var any = false;
                var next = head.nextElementSibling;
                while ( next && !next.classList.contains( 'tt-matrix-cat' ) ) {
                    if ( !next.hidden ) { any = true; break; }
                    next = next.nextElementSibling;
                }
                head.hidden = !any;
            } );

            if ( counter ) {
                counter.textContent = tokens.length ? visible + ' / ' + total : '';
            }
            if ( empty ) { empty.hidden = visible !== 0; }
        }

        input.addEventListener( 'input', apply );
        input.addEventListener( 'keydown', function ( ev ) {
            if ( ev.key === 'Escape' ) { input.value = ''; apply(); }
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
