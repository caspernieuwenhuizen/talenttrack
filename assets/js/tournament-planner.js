/* #0093 chunk 5 — tournament planner grid. Click-to-swap UX:
 *
 *   - First click on a cell or BENCH player selects that player.
 *   - Second click on another cell / BENCH swaps the two slots.
 *   - Click the selected cell again to deselect.
 *
 * Works on touch + desktop without HTML5 drag API. Drag-and-drop
 * polish ships separately.
 *
 * Per-match grid hydrates on first expand. Each cell is a (period,
 * slot) pair; BENCH lives at the bottom of each period column. The
 * full assignments payload is PATCHed after every interaction; the
 * server is the source of truth, the grid re-renders from the
 * response.
 */
(function () {
    'use strict';

    function tt() { return window.TT || {}; }
    function restUrl() {
        return ( tt().rest_url || '/wp-json/talenttrack/v1/' ).replace( /\/+$/, '/' );
    }
    function restNonce() { return tt().rest_nonce || ''; }

    function escapeHtml( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
    }

    function api( method, path, body ) {
        var url = restUrl() + path.replace( /^\/+/, '' );
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-WP-Nonce': restNonce() },
        };
        if ( body != null ) {
            opts.headers[ 'Content-Type' ] = 'application/json';
            opts.body = JSON.stringify( body );
        }
        return fetch( url, opts ).then( function ( res ) {
            return res.json().then( function ( json ) { return { ok: res.ok, status: res.status, json: json }; } );
        } );
    }

    /**
     * Find every planner root on the page and hydrate. Each root
     * carries data-tournament-id + data-match-id; the JS fetches the
     * planner bundle and renders the grid.
     */
    function hydrate() {
        var roots = document.querySelectorAll( '[data-tt-tournament-planner="1"]' );
        roots.forEach( function ( root ) {
            var tournamentId = parseInt( root.getAttribute( 'data-tournament-id' ), 10 );
            var matchId      = parseInt( root.getAttribute( 'data-match-id' ), 10 );
            if ( ! tournamentId || ! matchId ) return;

            var toggle = root.querySelector( '[data-tt-planner-toggle="1"]' );
            var body   = root.querySelector( '[data-tt-planner-body="1"]' );
            if ( toggle && body ) {
                toggle.addEventListener( 'click', function () {
                    if ( body.hasAttribute( 'hidden' ) ) {
                        body.removeAttribute( 'hidden' );
                        toggle.setAttribute( 'aria-expanded', 'true' );
                        if ( ! body.dataset.loaded ) {
                            loadPlanner( tournamentId, matchId, body );
                            body.dataset.loaded = '1';
                        }
                    } else {
                        body.setAttribute( 'hidden', 'hidden' );
                        toggle.setAttribute( 'aria-expanded', 'false' );
                    }
                } );
            }

            // Lifecycle action buttons (Kick off / Complete).
            var actionBtns = root.querySelectorAll( '[data-tt-match-action]' );
            actionBtns.forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    var action = btn.getAttribute( 'data-tt-match-action' );
                    if ( ! action ) return;
                    var confirmMsg = action === 'complete'
                        ? 'Mark this match as completed? Attendance will be written for every squad member.'
                        : null;
                    if ( confirmMsg && ! window.confirm( confirmMsg ) ) return;
                    btn.disabled = true;
                    var prev = btn.textContent;
                    btn.textContent = '…';
                    api( 'POST', 'tournaments/' + tournamentId + '/matches/' + matchId + '/' + action, {} )
                        .then( function ( res ) {
                            if ( res.ok ) {
                                // Reload the page so server-side state (badges, button
                                // visibility, etc.) refreshes correctly.
                                window.location.reload();
                            } else {
                                btn.disabled = false;
                                btn.textContent = prev;
                                var msg = ( res.json && res.json.errors && res.json.errors[0] ) ? res.json.errors[0].message : 'Action failed.';
                                window.alert( msg );
                            }
                        } )
                        .catch( function () {
                            btn.disabled = false;
                            btn.textContent = prev;
                        } );
                } );
            } );

            // Auto-balance button.
            var auto = root.querySelector( '[data-tt-planner-auto="1"]' );
            if ( auto && body ) {
                auto.addEventListener( 'click', function () {
                    // Expand the body if it's collapsed so the user
                    // sees the new grid.
                    if ( body.hasAttribute( 'hidden' ) ) {
                        body.removeAttribute( 'hidden' );
                        if ( toggle ) toggle.setAttribute( 'aria-expanded', 'true' );
                    }
                    if ( ! body.dataset.loaded ) {
                        // First load fetches the slot_labels + squad
                        // first, then auto-balance.
                        loadPlanner( tournamentId, matchId, body );
                        body.dataset.loaded = '1';
                        setTimeout( function () { autoBalance( tournamentId, matchId, body, auto ); }, 250 );
                    } else {
                        autoBalance( tournamentId, matchId, body, auto );
                    }
                } );
            }
        } );
    }

    function loadPlanner( tournamentId, matchId, body ) {
        body.innerHTML = '<p class="tt-muted">Loading planner…</p>';
        api( 'GET', 'tournaments/' + tournamentId + '/matches/' + matchId + '/planner' )
            .then( function ( res ) {
                if ( ! res.ok || ! res.json || ! res.json.data ) {
                    body.innerHTML = '<p class="tt-notice tt-notice-error">Could not load the planner.</p>';
                    return;
                }
                renderPlanner( res.json.data, body, tournamentId, matchId );
            } )
            .catch( function () {
                body.innerHTML = '<p class="tt-notice tt-notice-error">Could not load the planner.</p>';
            } );
    }

    /**
     * Render the period × slot grid. State (assignments + selection)
     * is kept on the body element itself via dataset.
     */
    function renderPlanner( data, body, tournamentId, matchId ) {
        body.dataset.tournamentId = String( tournamentId );
        body.dataset.matchId      = String( matchId );
        body.dataset.assignments  = JSON.stringify( data.assignments || [] );
        body.dataset.periods      = String( data.periods || 1 );
        body.dataset.squad        = JSON.stringify( data.squad || [] );
        body.dataset.slotLabels   = JSON.stringify( data.formation && data.formation.slot_labels ? data.formation.slot_labels : [] );
        body.dataset.minutesPerPeriod = String( data.minutes_per_period || 0 );

        repaint( body );
    }

    /**
     * #0093 chunk 7 — auto-balance trigger. Wired from the match
     * card's "Auto-balance" button. POSTs to /auto-plan, replaces the
     * grid's local state with the response's assignments, repaints.
     */
    function autoBalance( tournamentId, matchId, body, button ) {
        if ( button ) {
            button.disabled = true;
            button.textContent = 'Balancing…';
        }
        api( 'POST', 'tournaments/' + tournamentId + '/matches/' + matchId + '/auto-plan', {} )
            .then( function ( res ) {
                if ( button ) {
                    button.disabled = false;
                    button.textContent = 'Auto-balance';
                }
                if ( ! res.ok ) {
                    var msg = ( res.json && res.json.errors && res.json.errors[0] ) ? res.json.errors[0].message : 'Could not auto-balance.';
                    if ( body ) body.insertAdjacentHTML( 'afterbegin', '<p class="tt-notice tt-notice-error">' + escapeHtml( msg ) + '</p>' );
                    return;
                }
                if ( res.json && res.json.data && res.json.data.assignments && body ) {
                    body.dataset.assignments = JSON.stringify( res.json.data.assignments );
                    repaint( body );
                    if ( res.json.data.totals ) {
                        document.dispatchEvent( new CustomEvent( 'tt-tournament-totals-changed', {
                            detail: { tournament_id: tournamentId, totals: res.json.data.totals },
                        } ) );
                    }
                }
            } )
            .catch( function () {
                if ( button ) {
                    button.disabled = false;
                    button.textContent = 'Auto-balance';
                }
            } );
    }

    /**
     * Build a fast lookup map from the assignments array.
     * Key: "period|position_code" → player_id  (for non-bench slots)
     * Key: "period|BENCH"          → [player_id, …]
     */
    function indexAssignments( assignments ) {
        var slots = {};      // period|code → player_id
        var bench = {};      // period      → [player_id, …]
        var byPlayer = {};   // period|player_id → position_code
        assignments.forEach( function ( a ) {
            var k = a.period_index + '|' + a.position_code;
            if ( a.position_code === 'BENCH' ) {
                if ( ! bench[ a.period_index ] ) bench[ a.period_index ] = [];
                bench[ a.period_index ].push( a.player_id );
            } else {
                slots[ k ] = a.player_id;
            }
            byPlayer[ a.period_index + '|' + a.player_id ] = a.position_code;
        } );
        return { slots: slots, bench: bench, byPlayer: byPlayer };
    }

    function repaint( body ) {
        var periods   = parseInt( body.dataset.periods, 10 ) || 1;
        var squad     = JSON.parse( body.dataset.squad || '[]' );
        var slotLabels= JSON.parse( body.dataset.slotLabels || '[]' );
        var assignments = JSON.parse( body.dataset.assignments || '[]' );
        var minutesPerPeriod = parseInt( body.dataset.minutesPerPeriod, 10 ) || 0;

        var idx = indexAssignments( assignments );
        var playerById = {};
        squad.forEach( function ( s ) { playerById[ s.player_id ] = s; } );

        // Build column headers.
        var html = '<div class="tt-planner-grid" style="overflow-x:auto;">';
        html += '<table class="tt-planner-table"><thead><tr><th></th>';
        for ( var p = 0; p < periods; p++ ) {
            var pStart = p * minutesPerPeriod;
            var pEnd   = ( p + 1 ) * minutesPerPeriod;
            html += '<th>P' + ( p + 1 ) + ' <small>(' + pStart + "–" + pEnd + "')</small></th>";
        }
        html += '</tr></thead><tbody>';

        // One row per (formation line × slot in line). The flat list
        // of slot codes is line-major: [GK, RB, CB, LB, RM, CM, LM, RW, ST, LW].
        var flatSlots = [];
        slotLabels.forEach( function ( line ) {
            line.forEach( function ( code ) { flatSlots.push( code ); } );
        } );

        if ( flatSlots.length === 0 ) {
            html += '<tr><td colspan="' + ( periods + 1 ) + '" class="tt-muted">';
            html += 'No formation set on this match. Edit the match to pick a formation.';
            html += '</td></tr>';
        }

        flatSlots.forEach( function ( code ) {
            html += '<tr><th class="tt-planner-slot-label">' + escapeHtml( code ) + '</th>';
            for ( var p = 0; p < periods; p++ ) {
                var pid = idx.slots[ p + '|' + code ];
                html += renderCell( p, code, pid, playerById );
            }
            html += '</tr>';
        } );

        // Bench row.
        html += '<tr class="tt-planner-bench-row"><th class="tt-planner-slot-label">' + escapeHtml( 'BENCH' ) + '</th>';
        for ( var p = 0; p < periods; p++ ) {
            html += '<td class="tt-planner-cell tt-planner-bench" data-period="' + p + '" data-position="BENCH">';
            var benchIds = idx.bench[ p ] || [];
            // Players in the squad NOT assigned anywhere in this period are also benched implicitly.
            var assignedHere = {};
            for ( var pos in idx.slots ) {
                if ( pos.indexOf( p + '|' ) === 0 ) assignedHere[ idx.slots[ pos ] ] = true;
            }
            benchIds.forEach( function ( pid ) { assignedHere[ pid ] = true; } );
            // Render all benched (explicit) + unassigned squad members.
            var benched = [];
            benchIds.forEach( function ( pid ) { benched.push( pid ); } );
            squad.forEach( function ( s ) { if ( ! assignedHere[ s.player_id ] ) benched.push( s.player_id ); } );
            benched.forEach( function ( pid ) {
                var pl = playerById[ pid ];
                if ( ! pl ) return;
                html += '<button type="button" class="tt-planner-chip tt-planner-chip-bench" data-player-id="' + pid + '" data-period="' + p + '" data-position="BENCH">' + escapeHtml( pl.full_name ) + '</button>';
            } );
            html += '</td>';
        }
        html += '</tr>';

        html += '</tbody></table></div>';
        html += '<p class="tt-muted" style="font-size:12px;margin-top:6px;">Click a player chip, then click another cell to swap. Click the same chip again to deselect.</p>';
        body.innerHTML = html;

        wireInteractions( body );
    }

    function renderCell( period, code, playerId, playerById ) {
        var html = '<td class="tt-planner-cell" data-period="' + period + '" data-position="' + escapeHtml( code ) + '">';
        if ( playerId ) {
            var pl = playerById[ playerId ];
            var name = pl ? pl.full_name : ( 'Player #' + playerId );
            html += '<button type="button" class="tt-planner-chip" data-player-id="' + playerId + '" data-period="' + period + '" data-position="' + escapeHtml( code ) + '">' + escapeHtml( name ) + '</button>';
        } else {
            html += '<button type="button" class="tt-planner-chip tt-planner-chip-empty" data-period="' + period + '" data-position="' + escapeHtml( code ) + '">+</button>';
        }
        html += '</td>';
        return html;
    }

    function wireInteractions( body ) {
        body.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest && e.target.closest( '.tt-planner-chip' );
            if ( ! btn ) return;
            e.preventDefault();
            var selected = body.querySelector( '.tt-planner-chip.is-selected' );

            if ( selected === btn ) {
                btn.classList.remove( 'is-selected' );
                return;
            }
            if ( ! selected ) {
                if ( btn.classList.contains( 'tt-planner-chip-empty' ) ) return; // can't select an empty cell first
                btn.classList.add( 'is-selected' );
                return;
            }

            // Two chips chosen — swap them.
            swap( body, selected, btn );
        } );
    }

    function swap( body, a, b ) {
        var assignments = JSON.parse( body.dataset.assignments || '[]' );

        var aPeriod = parseInt( a.getAttribute( 'data-period' ), 10 );
        var aPos    = a.getAttribute( 'data-position' );
        var aPid    = parseInt( a.getAttribute( 'data-player-id' ) || '0', 10 );

        var bPeriod = parseInt( b.getAttribute( 'data-period' ), 10 );
        var bPos    = b.getAttribute( 'data-position' );
        var bPid    = parseInt( b.getAttribute( 'data-player-id' ) || '0', 10 );

        // Cross-period swaps are allowed — useful for "Casper opens at GK
        // in P1, then sits in P2 with Sven coming in." The dest cell's
        // period dictates where the dropped player lands.
        // Compute the new assignment list:
        var next = assignments.filter( function ( r ) {
            // Drop any row at the source position OR at the dest position OR
            // that has either of our two players in those periods.
            if ( r.period_index === aPeriod && r.player_id === aPid ) return false;
            if ( r.period_index === bPeriod && r.player_id === bPid ) return false;
            if ( r.period_index === aPeriod && r.position_code === aPos && aPos !== 'BENCH' ) return false;
            if ( r.period_index === bPeriod && r.position_code === bPos && bPos !== 'BENCH' ) return false;
            return true;
        } );

        // Player A goes where B was.
        if ( aPid ) {
            next.push( { period_index: bPeriod, player_id: aPid, position_code: bPos } );
        }
        // Player B goes where A was — but only if B had a player and the
        // source slot wasn't an empty placeholder.
        if ( bPid ) {
            next.push( { period_index: aPeriod, player_id: bPid, position_code: aPos } );
        }

        // Commit optimistically + PATCH.
        body.dataset.assignments = JSON.stringify( next );
        repaint( body );

        var tournamentId = parseInt( body.dataset.tournamentId, 10 );
        var matchId      = parseInt( body.dataset.matchId, 10 );
        api( 'PATCH', 'tournaments/' + tournamentId + '/matches/' + matchId + '/assignments', { assignments: next } )
            .then( function ( res ) {
                if ( ! res.ok ) {
                    // Roll back: reload from server.
                    loadPlanner( tournamentId, matchId, body );
                    return;
                }
                // Re-broadcast totals so the (chunk 6) minutes ticker can update.
                if ( res.json && res.json.data && res.json.data.totals ) {
                    document.dispatchEvent( new CustomEvent( 'tt-tournament-totals-changed', { detail: { tournament_id: tournamentId, totals: res.json.data.totals } } ) );
                }
            } )
            .catch( function () {
                loadPlanner( tournamentId, matchId, body );
            } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', hydrate );
    } else {
        hydrate();
    }
} )();
