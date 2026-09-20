/* #0093 chunk 6 — sticky minutes ticker. The headline UI of the
 * tournament planner.
 *
 * Each card shows a player's photo (initials fallback), name,
 * minutes played and minutes still planned against the target, as a
 * two-part coloured bar with numbers, plus starts + full-matches
 * badges. Played and planned are never added into one figure on the
 * face of the card — before kick-off that read as minutes already on
 * the pitch.
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

    /* #3815 — every visible string comes from the server via
     * wp_localize_script, so the card speaks the reader's language.
     * The fallbacks are English so a stale cached script still renders
     * something rather than an empty badge. */
    function i18n( key, fallback ) {
        var bag = ( window.TT_TournamentTicker && window.TT_TournamentTicker.i18n ) || {};
        return bag[ key ] || fallback;
    }

    /* Substitute the numbered placeholders in a translated pattern and
     * return HTML. The pattern is escaped first — a catalogue is data,
     * not markup — and only the caller's own values carry tags. */
    function format( pattern, values ) {
        return values.reduce( function ( out, value, i ) {
            return out.split( '%' + ( i + 1 ) + '$s' ).join( value );
        }, escapeHtml( pattern ) );
    }

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
            strip.innerHTML = '<p class="tt-muted tt-ticker-empty">'
                + escapeHtml( i18n( 'emptySquad', 'Add players to the squad to see minute totals.' ) )
                + '</p>';
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

        /* #3815 — the state and the bar stay keyed off played + planned.
         * Before the first kick-off every player has 0 played, so a state
         * driven by played minutes alone would paint the whole squad red
         * and tell a coach nothing about whether the plan covers everyone.
         * The numbers below are what stops that total reading as minutes
         * already on the pitch. */
        var scheduled = played + expected;
        var pct = target > 0 ? Math.min( 100, Math.round( ( scheduled / target ) * 100 ) ) : 0;
        var state = scheduled >= target ? 'ok'
            : scheduled >= target * 0.85 ? 'warn'
            : 'low';

        // Split the fill so the solid part is what has actually been played.
        var playedPct  = target > 0 ? Math.min( 100, Math.round( ( played / target ) * 100 ) ) : 0;
        var plannedPct = Math.max( 0, pct - playedPct );

        var numbers = expected > 0
            ? format(
                i18n( 'minutesPlayedPlanned', '%1$s played + %2$s planned / %3$s min' ),
                [ '<strong>' + played + '</strong>', '<strong>' + expected + '</strong>', target ]
            )
            : format(
                i18n( 'minutesPlayed', '%1$s played / %2$s min' ),
                [ '<strong>' + played + '</strong>', target ]
            );

        var startsLabel = escapeHtml( i18n( 'starts', 'Starts' ) );
        var fullLabel   = escapeHtml( i18n( 'fullMatches', 'Full matches' ) );

        var initials = ( ( p.first_name || ' ' )[ 0 ] + ( p.last_name || ' ' )[ 0 ] ).toUpperCase();
        var avatar = p.photo_url
            ? '<img class="tt-ticker-avatar" src="' + escapeHtml( p.photo_url ) + '" alt="">'
            : '<span class="tt-ticker-avatar tt-ticker-avatar-initials">' + escapeHtml( initials ) + '</span>';

        return '<article class="tt-ticker-card tt-ticker-card--' + state + '">' +
            '<header class="tt-ticker-card-head">' +
                avatar +
                '<span class="tt-ticker-name">' + escapeHtml( p.full_name || ( ( p.first_name || '' ) + ' ' + ( p.last_name || '' ) ) ) + '</span>' +
            '</header>' +
            '<div class="tt-ticker-bar">' +
                '<div class="tt-ticker-bar-fill" style="width:' + playedPct + '%;"></div>' +
                '<div class="tt-ticker-bar-fill tt-ticker-bar-fill--planned" style="width:' + plannedPct + '%;"></div>' +
            '</div>' +
            '<div class="tt-ticker-numbers">' + numbers + '</div>' +
            '<div class="tt-ticker-badges">' +
                '<span title="' + startsLabel + '" aria-label="' + startsLabel + '">⚡ ' + ( p.starts || 0 ) + '</span>' +
                '<span title="' + fullLabel + '" aria-label="' + fullLabel + '">🏆 ' + ( p.full_matches || 0 ) + '</span>' +
            '</div>' +
        '</article>';
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', hydrate );
    } else {
        hydrate();
    }
} )();
