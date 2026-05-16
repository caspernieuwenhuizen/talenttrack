/* #0093 chunk 6 — sticky minutes ticker. The headline UI of the
 * tournament planner.
 *
 * Each card shows a player's photo (initials fallback), name,
 * played-so-far / expected-total / target as a coloured bar with
 * numbers, plus starts + full-matches badges.
 *
 * Layout:
 *   - Mobile (< 1024px): horizontal-scroll strip pinned to the
 *     bottom of the viewport above the safe-area inset.
 *   - Desktop (>= 1024px): fixed right sidebar.
 *
 * Hydrates from GET /tournaments/{id}/totals on load and listens
 * to the `tt-tournament-totals-changed` CustomEvent dispatched by
 * the planner-grid PATCH response (chunk 5) for live updates.
 */
(function () {
    'use strict';

    function tt() { return window.TT || {}; }
    function restUrl() { return ( tt().rest_url || '/wp-json/talenttrack/v1/' ).replace( /\/+$/, '/' ); }
    function restNonce() { return tt().rest_nonce || ''; }

    function escapeHtml( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
    }

    function api( method, path ) {
        var url = restUrl() + path.replace( /^\/+/, '' );
        return fetch( url, {
            method: method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-WP-Nonce': restNonce() },
        } ).then( function ( res ) { return res.json(); } );
    }

    function hydrate() {
        var root = document.querySelector( '[data-tt-minutes-ticker="1"]' );
        if ( ! root ) return;
        var tournamentId = parseInt( root.getAttribute( 'data-tournament-id' ), 10 );
        if ( ! tournamentId ) return;

        // Initial fetch.
        api( 'GET', 'tournaments/' + tournamentId + '/totals' )
            .then( function ( j ) {
                if ( j && j.data && Array.isArray( j.data.players ) ) {
                    render( root, j.data.players );
                }
            } );

        // Live updates from the planner grid.
        document.addEventListener( 'tt-tournament-totals-changed', function ( e ) {
            if ( ! e.detail || e.detail.tournament_id !== tournamentId ) return;
            if ( Array.isArray( e.detail.totals ) ) render( root, e.detail.totals );
        } );

        // Sort dropdown.
        var sortSel = root.querySelector( '[data-tt-ticker-sort="1"]' );
        if ( sortSel ) {
            sortSel.addEventListener( 'change', function () {
                var players = JSON.parse( root.dataset.players || '[]' );
                render( root, players );
            } );
        }
    }

    function render( root, players ) {
        root.dataset.players = JSON.stringify( players );

        var sortSel = root.querySelector( '[data-tt-ticker-sort="1"]' );
        var mode    = sortSel ? sortSel.value : 'default';
        var sorted  = players.slice();
        if ( mode === 'minutes_asc' ) {
            sorted.sort( function ( a, b ) {
                var aTotal = ( a.played_minutes || 0 ) + ( a.expected_minutes || 0 );
                var bTotal = ( b.played_minutes || 0 ) + ( b.expected_minutes || 0 );
                return aTotal - bTotal;
            } );
        } else if ( mode === 'starts_asc' ) {
            sorted.sort( function ( a, b ) { return ( a.starts || 0 ) - ( b.starts || 0 ); } );
        } else if ( mode === 'no_full' ) {
            sorted.sort( function ( a, b ) { return ( a.full_matches || 0 ) - ( b.full_matches || 0 ); } );
        }

        var strip = root.querySelector( '[data-tt-ticker-strip="1"]' );
        if ( ! strip ) return;

        if ( sorted.length === 0 ) {
            strip.innerHTML = '<p class="tt-muted" style="padding:8px;">Add players to the squad to see minute totals.</p>';
            return;
        }

        strip.innerHTML = sorted.map( function ( p ) {
            return renderCard( p );
        } ).join( '' );
    }

    function renderCard( p ) {
        var target   = p.target_minutes || 0;
        var played   = p.played_minutes || 0;
        var expected = p.expected_minutes || 0;
        var scheduled = played + expected; // what the planner currently has them on
        var pct = target > 0 ? Math.min( 100, Math.round( ( scheduled / target ) * 100 ) ) : 0;
        var state = scheduled >= target ? 'ok'
            : scheduled >= target * 0.85 ? 'warn'
            : 'low';

        var initials = ( ( p.first_name || ' ' )[ 0 ] + ( p.last_name || ' ' )[ 0 ] ).toUpperCase();
        var avatar = p.photo_url
            ? '<img class="tt-ticker-avatar" src="' + escapeHtml( p.photo_url ) + '" alt="">'
            : '<span class="tt-ticker-avatar tt-ticker-avatar-initials">' + escapeHtml( initials ) + '</span>';

        return '<article class="tt-ticker-card tt-ticker-card--' + state + '">' +
            '<header class="tt-ticker-card-head">' +
                avatar +
                '<span class="tt-ticker-name">' + escapeHtml( p.full_name || ( ( p.first_name || '' ) + ' ' + ( p.last_name || '' ) ) ) + '</span>' +
            '</header>' +
            '<div class="tt-ticker-bar"><div class="tt-ticker-bar-fill" style="width:' + pct + '%;"></div></div>' +
            '<div class="tt-ticker-numbers"><strong>' + scheduled + '</strong>/' + target + ' min</div>' +
            '<div class="tt-ticker-badges">' +
                '<span title="Starts">⚡ ' + ( p.starts || 0 ) + '</span>' +
                '<span title="Full matches">🏆 ' + ( p.full_matches || 0 ) + '</span>' +
            '</div>' +
        '</article>';
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', hydrate );
    } else {
        hydrate();
    }
} )();
