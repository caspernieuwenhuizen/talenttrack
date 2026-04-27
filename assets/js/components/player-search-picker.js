/**
 * PlayerSearchPickerComponent hydrator.
 *
 * Binds to every `[data-tt-psp]` element on the page. Each element
 * carries:
 *   - data-tt-psp-search   : the search input
 *   - data-tt-psp-results  : a UL that receives result rows
 *   - data-tt-psp-value    : the hidden input holding the selected id
 *   - data-tt-psp-selected : the visible "selected player" chip (hidden when empty)
 *   - data-tt-psp-data     : a <script> JSON payload of all candidate rows
 *
 * Behaviour:
 *   - Type-to-filter (case-insensitive, contains-match across name + team).
 *   - Click a row to select; hides search, shows selected chip.
 *   - Click the × clear button to reset and re-show the search input.
 *   - Form-internal: hidden input value drives form submit.
 *
 * Multiple instances per page are isolated. No global state.
 */
(function () {
    'use strict';

    function escapeHtml( s ) {
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    function bind( root ) {
        if ( root.dataset.ttPspBound === '1' ) return;
        root.dataset.ttPspBound = '1';

        var searchEl   = root.querySelector( '[data-tt-psp-search]' );
        var resultsEl  = root.querySelector( '[data-tt-psp-results]' );
        var valueEl    = root.querySelector( '[data-tt-psp-value]' );
        var selectedEl = root.querySelector( '[data-tt-psp-selected]' );
        var labelEl    = root.querySelector( '[data-tt-psp-selected-label]' );
        var clearEl    = root.querySelector( '[data-tt-psp-clear]' );
        var dataEl     = root.querySelector( '[data-tt-psp-data]' );
        var teamFilter = root.querySelector( '[data-tt-psp-team-filter]' );

        if ( ! searchEl || ! resultsEl || ! valueEl || ! dataEl ) return;

        // Source-of-truth row list (immutable). `rows` below is the
        // currently-active filtered subset.
        var allRows = [];
        try {
            allRows = JSON.parse( dataEl.textContent || '[]' );
        } catch ( _ ) {
            allRows = [];
        }
        var rows = allRows.slice();

        function applyTeamFilter() {
            var tid = teamFilter ? parseInt( teamFilter.value || '0', 10 ) : 0;
            rows = tid > 0
                ? allRows.filter( function ( r ) { return r.team_id === tid; } )
                : allRows.slice();
        }

        function setSelection( id, label ) {
            valueEl.value = id ? String( id ) : '';
            if ( id ) {
                if ( labelEl ) labelEl.textContent = label;
                if ( selectedEl ) selectedEl.style.display = '';
                searchEl.style.display = 'none';
                if ( teamFilter ) teamFilter.style.display = 'none';
                searchEl.value = '';
            } else {
                if ( selectedEl ) selectedEl.style.display = 'none';
                searchEl.style.display = '';
                if ( teamFilter ) teamFilter.style.display = '';
                searchEl.focus();
            }
            resultsEl.hidden = true;
            resultsEl.innerHTML = '';
        }

        function renderResults( query ) {
            var q = ( query || '' ).toLowerCase().trim();
            if ( q.length < 1 ) {
                resultsEl.hidden = true;
                resultsEl.innerHTML = '';
                return;
            }
            var matches = rows.filter( function ( r ) {
                return ( r.search || '' ).indexOf( q ) !== -1;
            } ).slice( 0, 25 );

            if ( matches.length === 0 ) {
                resultsEl.hidden = false;
                resultsEl.innerHTML = '<li class="tt-psp-empty">' + escapeHtml( '—' ) + '</li>';
                return;
            }

            resultsEl.innerHTML = matches.map( function ( r ) {
                return '<li class="tt-psp-row" role="option" data-id="' + escapeHtml( r.id ) + '">' + escapeHtml( r.label ) + '</li>';
            } ).join( '' );
            resultsEl.hidden = false;
        }

        searchEl.addEventListener( 'input', function () {
            renderResults( searchEl.value );
        } );

        if ( teamFilter ) {
            teamFilter.addEventListener( 'change', function () {
                applyTeamFilter();
                // Re-render the visible result list against the new filter.
                renderResults( searchEl.value );
            } );
        }

        searchEl.addEventListener( 'focus', function () {
            if ( searchEl.value ) renderResults( searchEl.value );
        } );

        // Hide the result list when focus leaves the component, but
        // give clicks a chance to register first (mousedown inside
        // results runs before this blur fires).
        searchEl.addEventListener( 'blur', function () {
            setTimeout( function () {
                if ( ! root.contains( document.activeElement ) ) {
                    resultsEl.hidden = true;
                }
            }, 120 );
        } );

        resultsEl.addEventListener( 'mousedown', function ( e ) {
            var li = e.target.closest( '.tt-psp-row' );
            if ( ! li ) return;
            e.preventDefault();
            var id = parseInt( li.getAttribute( 'data-id' ), 10 ) || 0;
            var match = null;
            for ( var i = 0; i < rows.length; i++ ) {
                if ( rows[ i ].id === id ) { match = rows[ i ]; break; }
            }
            if ( match ) setSelection( id, match.label );
        } );

        if ( clearEl ) {
            clearEl.addEventListener( 'click', function () {
                setSelection( 0, '' );
            } );
        }

        // Public API: external code can update the team filter by
        // dispatching a `tt-psp:set-team` event with a `team_id`
        // detail. Used by the session/eval form to refresh the picker
        // when team changes (F1).
        root.addEventListener( 'tt-psp:set-team', function ( e ) {
            var teamId = ( e && e.detail && e.detail.team_id ) ? parseInt( e.detail.team_id, 10 ) : 0;
            // Reload data via a custom data-tt-psp-source URL if present;
            // otherwise just filter the already-loaded rows.
            var src = root.getAttribute( 'data-tt-psp-source' );
            if ( src && teamId > 0 ) {
                var url = src + ( src.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'team_id=' + teamId;
                fetch( url, { credentials: 'same-origin', headers: window.TT && window.TT.rest_nonce ? { 'X-WP-Nonce': window.TT.rest_nonce } : {} } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( payload ) {
                        var newRows = ( payload && payload.data && payload.data.rows ) || ( payload && payload.rows ) || [];
                        rows = newRows;
                        setSelection( 0, '' );
                    } )
                    .catch( function () { /* ignore */ } );
            } else {
                // Client-side filter: keep only rows for that team.
                rows = rows.filter( function ( r ) {
                    return teamId === 0 || r.team_id === teamId;
                } );
                setSelection( 0, '' );
            }
        } );
    }

    function init() {
        document.querySelectorAll( '[data-tt-psp]' ).forEach( bind );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

    // Expose an init for code that injects new pickers after DOM ready.
    window.ttPlayerSearchPicker = { init: init };
} )();
