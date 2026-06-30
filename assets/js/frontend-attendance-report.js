/*
 * frontend-attendance-report.js (#2137) — inline drill-down accordion for
 * the team attendance report. Each team row carries a <button aria-expanded>
 * disclosure; tapping it fetches that team's per-player attendance from the
 * REST surface (GET /reports/attendance?team_id=N&from=&to=&activity_type_key=),
 * injects a sub-table once (lazy + cached client-side), and toggles it.
 *
 * Progressive enhancement: with JS off, each disclosure is accompanied by a
 * server-rendered "View players" link to the player report pre-filtered to
 * the team, so the drill-down is reachable without the gesture (CLAUDE.md §2).
 *
 * Vanilla JS, no build step. Reads config from window.TT_ATTENDANCE_REPORT
 * (rest_url, nonce, i18n). One team open at a time.
 */
( function () {
    'use strict';

    var CFG = window.TT_ATTENDANCE_REPORT || {};
    var I18N = CFG.i18n || {};

    function t( key, fallback ) {
        return ( I18N && I18N[ key ] ) ? I18N[ key ] : fallback;
    }

    function buildUrl( base, params ) {
        var qs = [];
        Object.keys( params ).forEach( function ( k ) {
            var v = params[ k ];
            if ( v === '' || v === null || typeof v === 'undefined' ) {
                return;
            }
            qs.push( encodeURIComponent( k ) + '=' + encodeURIComponent( v ) );
        } );
        return base + ( qs.length ? ( '?' + qs.join( '&' ) ) : '' );
    }

    function pct( present, total ) {
        if ( ! total ) {
            return '—';
        }
        return ( Math.round( ( present / total ) * 1000 ) / 10 ).toFixed( 1 ) + '%';
    }

    function playerName( row ) {
        var name = ( ( row.first_name || '' ) + ' ' + ( row.last_name || '' ) ).trim();
        return name || ( '#' + ( row.player_id || 0 ) );
    }

    function renderSubTable( players ) {
        if ( ! players || ! players.length ) {
            var empty = document.createElement( 'p' );
            empty.className = 'tt-att-sub-empty';
            empty.textContent = t( 'empty', 'No player attendance in this window.' );
            return empty;
        }

        var table = document.createElement( 'table' );
        table.className = 'tt-table tt-att-sub-table';

        var thead = document.createElement( 'thead' );
        var hrow = document.createElement( 'tr' );
        [ t( 'player', 'Player' ), t( 'present', 'Present %' ) ].forEach( function ( label, i ) {
            var th = document.createElement( 'th' );
            th.textContent = label;
            if ( i === 1 ) {
                th.className = 'tt-num';
            }
            hrow.appendChild( th );
        } );
        thead.appendChild( hrow );
        table.appendChild( thead );

        var tbody = document.createElement( 'tbody' );
        players.forEach( function ( row ) {
            var tr = document.createElement( 'tr' );
            if ( row.flagged ) {
                tr.className = 'is-flagged';
            }

            var nameTd = document.createElement( 'td' );
            nameTd.textContent = playerName( row );
            if ( row.flagged ) {
                var badge = document.createElement( 'span' );
                badge.className = 'tt-flag-badge';
                badge.textContent = '⚠ ' + ( row.missed || 0 );
                badge.setAttribute( 'title', t( 'flagged', 'At risk' ) );
                nameTd.appendChild( document.createTextNode( ' ' ) );
                nameTd.appendChild( badge );
            }
            tr.appendChild( nameTd );

            var pctTd = document.createElement( 'td' );
            pctTd.className = 'tt-num';
            pctTd.textContent = pct( row.present || 0, row.total || 0 );
            tr.appendChild( pctTd );

            tbody.appendChild( tr );
        } );
        table.appendChild( tbody );
        return table;
    }

    function setMessage( cell, text ) {
        cell.textContent = '';
        var p = document.createElement( 'p' );
        p.className = 'tt-att-sub-msg';
        p.textContent = text;
        cell.appendChild( p );
    }

    function loadPlayers( table, teamId, cell, done ) {
        var url = buildUrl( CFG.rest_url, {
            team_id: teamId,
            from: table.getAttribute( 'data-tt-att-from' ) || '',
            to: table.getAttribute( 'data-tt-att-to' ) || '',
            activity_type_key: table.getAttribute( 'data-tt-att-type' ) || ''
        } );

        setMessage( cell, t( 'loading', 'Loading players…' ) );

        fetch( url, {
            headers: { 'X-WP-Nonce': CFG.nonce || '' },
            credentials: 'same-origin'
        } ).then( function ( res ) {
            if ( ! res.ok ) {
                throw new Error( 'HTTP ' + res.status );
            }
            return res.json();
        } ).then( function ( data ) {
            var players = ( data && data.players ) ? data.players : [];
            cell.textContent = '';
            cell.appendChild( renderSubTable( players ) );
            done( true );
        } ).catch( function () {
            setMessage( cell, t( 'error', 'Could not load players. Try again.' ) );
            done( false );
        } );
    }

    function collapseOthers( table, exceptBtn ) {
        var open = table.querySelectorAll( '.tt-att-disclosure[aria-expanded="true"]' );
        Array.prototype.forEach.call( open, function ( btn ) {
            if ( btn === exceptBtn ) {
                return;
            }
            btn.setAttribute( 'aria-expanded', 'false' );
            var sub = document.getElementById( btn.getAttribute( 'aria-controls' ) );
            if ( sub ) {
                sub.hidden = true;
            }
        } );
    }

    function bindTable( table ) {
        table.addEventListener( 'click', function ( ev ) {
            var btn = ev.target.closest( '.tt-att-disclosure' );
            if ( ! btn || ! table.contains( btn ) ) {
                return;
            }
            ev.preventDefault();

            var subRow = document.getElementById( btn.getAttribute( 'aria-controls' ) );
            if ( ! subRow ) {
                return;
            }
            var expanded = btn.getAttribute( 'aria-expanded' ) === 'true';

            if ( expanded ) {
                btn.setAttribute( 'aria-expanded', 'false' );
                subRow.hidden = true;
                return;
            }

            collapseOthers( table, btn );
            btn.setAttribute( 'aria-expanded', 'true' );
            subRow.hidden = false;

            var teamId = btn.closest( '.tt-att-team-row' ).getAttribute( 'data-tt-att-team' );
            var cell = subRow.querySelector( '.tt-att-sub-cell' );

            // Lazy + cached: only fetch on first expand.
            if ( cell.getAttribute( 'data-tt-loaded' ) === '1' ) {
                return;
            }
            loadPlayers( table, teamId, cell, function ( ok ) {
                if ( ok ) {
                    cell.setAttribute( 'data-tt-loaded', '1' );
                }
            } );
        } );
    }

    function init() {
        var tables = document.querySelectorAll( '.tt-att-team-table' );
        if ( ! tables.length || ! CFG.rest_url ) {
            return;
        }
        // JS is on — mark the table so CSS can hide the no-JS fallback link.
        Array.prototype.forEach.call( tables, function ( table ) {
            table.classList.add( 'tt-att-js' );
            bindTable( table );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
