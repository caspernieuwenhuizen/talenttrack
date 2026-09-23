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

    /**
     * #4032 — the completion step's copy, from the catalogue. Never a literal:
     * the only confirm before this was a hard-coded English `window.confirm`
     * on a Dutch install (CLAUDE.md §4).
     */
    function i18n( key, fallback ) {
        var bag = ( window.TT_TournamentPlanner || {} ).i18n || {};
        return bag[ key ] || fallback || '';
    }

    /** printf-style `%1$d` / `%s` substitution, in argument order. */
    function fmt( template, args ) {
        var out = String( template );
        args.forEach( function ( value, index ) {
            out = out
                .replace( '%' + ( index + 1 ) + '$d', String( value ) )
                .replace( '%' + ( index + 1 ) + '$s', String( value ) );
        } );
        return out.replace( '%s', String( args[0] == null ? '' : args[0] ) );
    }
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
     * #3532 — the fixture's result, saved on blur.
     *
     * A tournament day has no single scoreline (#2686), so the result lives
     * per fixture and these two boxes are the only place it can be entered.
     *
     * Saved per box rather than behind a page-wide Save because the rest of
     * this card already works that way — the planner's assignments PATCH as
     * you drag — and a second commit model on one screen would be worse than
     * either. A cleared box sends null: "no result recorded", not 0-0, which
     * is what keeps a fixture nobody has played out of the day's results.
     */
    function bindScores() {
        document.querySelectorAll( '[data-tt-tour-score]' ).forEach( function ( input ) {
            var root = input.closest( '[data-tt-tournament-planner="1"]' );
            if ( ! root ) {
                return;
            }

            input.addEventListener( 'blur', function () {
                var tournamentId = parseInt( root.getAttribute( 'data-tournament-id' ), 10 );
                var matchId = parseInt( root.getAttribute( 'data-match-id' ), 10 );
                if ( ! tournamentId || ! matchId ) {
                    return;
                }

                var raw = String( input.value ).replace( /[^0-9]/g, '' );
                input.value = raw === '' ? '' : String( Math.min( 99, parseInt( raw, 10 ) ) );

                var body = {};
                body[ input.getAttribute( 'data-tt-tour-score' ) ] = input.value === ''
                    ? null
                    : parseInt( input.value, 10 );

                input.classList.remove( 'is-error' );
                api( 'PATCH', 'tournaments/' + tournamentId + '/matches/' + matchId, body )
                    .then( function ( res ) {
                        if ( ! res.ok ) {
                            // The typed value stays in the box. Clearing it
                            // would throw away the work the save just failed
                            // to store.
                            input.classList.add( 'is-error' );
                        }
                    } )
                    ['catch']( function () {
                        input.classList.add( 'is-error' );
                    } );
            } );
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

                    // #4032 — completing a fixture goes through the confirm
                    // step: the per-player minutes it is about to record,
                    // pre-filled, and any period whose lineup does not fill the
                    // formation, named. It used to be a one-tap English
                    // `window.confirm` that locked in whatever was in the grid.
                    if ( action === 'complete' ) {
                        openCompletionStep( tournamentId, matchId, btn );
                        return;
                    }
                    if ( ! window.confirm( i18n( 'kickoffConfirm', 'Kick off this match?' ) ) ) return;
                    runMatchAction( tournamentId, matchId, action, btn, null );
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

    /**
     * POST a lifecycle action and reload, so server-rendered state (badges,
     * which buttons exist, the ticker) comes back consistent.
     */
    function runMatchAction( tournamentId, matchId, action, btn, payload ) {
        btn.disabled = true;
        var prev = btn.textContent;
        btn.textContent = '…';
        return api( 'POST', 'tournaments/' + tournamentId + '/matches/' + matchId + '/' + action, payload || {} )
            .then( function ( res ) {
                if ( res.ok ) {
                    window.location.reload();
                    return res;
                }
                btn.disabled = false;
                btn.textContent = prev;
                var msg = ( res.json && res.json.errors && res.json.errors[0] )
                    ? res.json.errors[0].message
                    : i18n( 'completeFailed', 'The match could not be completed.' );
                window.alert( msg );
                return res;
            } )
            ['catch']( function () {
                btn.disabled = false;
                btn.textContent = prev;
            } );
    }

    /**
     * #4032 — the completion step.
     *
     * Two things a coach needs before a fixture's minutes go onto fifteen
     * children's records, and neither was on screen: what those minutes are,
     * and whether the grid they come from actually fielded a team. The step
     * reads `GET .../completion`, shows the per-player minutes pre-filled from
     * the rotation plan so the answer is confirm-or-adjust rather than a blank
     * form on a phone, and puts any under-filled period at the top in words.
     *
     * If the read fails it says so and stops. It deliberately does NOT fall
     * back to posting the completion: committing minutes the coach was never
     * shown is the bug this step exists to fix.
     */
    function openCompletionStep( tournamentId, matchId, btn ) {
        btn.disabled = true;
        var prev = btn.textContent;
        btn.textContent = i18n( 'loading', 'Loading…' );

        api( 'GET', 'tournaments/' + tournamentId + '/matches/' + matchId + '/completion' )
            .then( function ( res ) {
                btn.disabled = false;
                btn.textContent = prev;
                if ( ! res.ok || ! res.json || ! res.json.data ) {
                    window.alert( i18n( 'loadFailed', 'Could not load the completion step.' ) );
                    return;
                }
                renderCompletionStep( res.json.data, tournamentId, matchId, btn );
            } )
            ['catch']( function () {
                btn.disabled = false;
                btn.textContent = prev;
            } );
    }

    function renderCompletionStep( data, tournamentId, matchId, btn ) {
        var players = data.players || [];
        var short   = data.short_periods || [];

        var dialog = document.createElement( 'dialog' );
        dialog.className = 'tt-tour-complete';
        dialog.setAttribute( 'aria-label', i18n( 'completeTitle', 'Complete match' ) );

        var html = '<form method="dialog" class="tt-tour-complete__form">';
        html += '<h2 class="tt-tour-complete__title">' + escapeHtml( i18n( 'completeTitle', 'Complete match' ) ) + '</h2>';
        html += '<p class="tt-tour-complete__intro">' + escapeHtml( i18n( 'completeIntro', '' ) ) + '</p>';

        if ( short.length ) {
            html += '<div class="tt-tour-complete__warn" role="alert">';
            html += '<strong>' + escapeHtml( i18n( 'lineupShort', '' ) ) + '</strong>';
            html += '<ul>';
            short.forEach( function ( row ) {
                html += '<li>' + escapeHtml( fmt(
                    i18n( 'lineupPeriod', 'Period %1$d: %2$d of %3$d positions filled' ),
                    [ ( row.period || 0 ) + 1, row.filled || 0, row.slots || 0 ]
                ) ) + '</li>';
            } );
            html += '</ul>';
            html += '<p>' + escapeHtml( i18n( 'lineupAnyway', '' ) ) + '</p>';
            html += '</div>';
        }

        html += '<ul class="tt-tour-complete__players">';
        players.forEach( function ( player ) {
            var roleKey = player.role === 'start' ? 'roleStart' : ( player.role === 'sub' ? 'roleSub' : 'roleBench' );
            html += '<li class="tt-tour-complete__player">';
            html += '<span class="tt-tour-complete__name">' + escapeHtml( player.full_name ) + '';
            html += '<span class="tt-tour-complete__role">' + escapeHtml( i18n( roleKey, '' ) ) + '</span>';
            html += '</span>';
            html += '<input class="tt-input tt-tour-complete__min" type="number" inputmode="numeric"'
                + ' min="0" max="' + ( data.duration_min || 0 ) + '" step="1"'
                + ' value="' + escapeHtml( String( player.minutes == null ? 0 : player.minutes ) ) + '"'
                + ' data-player-id="' + escapeHtml( String( player.player_id ) ) + '"'
                + ' aria-label="' + escapeHtml( fmt( i18n( 'minutesFor', 'Minutes for %s' ), [ player.full_name ] ) ) + '">';
            html += '</li>';
        } );
        html += '</ul>';

        html += '<p class="tt-tour-complete__total" data-tt-complete-total="1"></p>';

        // Cancel first in the DOM, Save right on screen via flex order
        // (CLAUDE.md §6): least-committal first for the keyboard, the commit
        // under the thumb on a phone.
        html += '<div class="tt-form-actions tt-tour-complete__actions">';
        html += '<button type="button" class="tt-btn tt-btn-secondary" data-tt-complete-cancel="1">'
            + escapeHtml( i18n( 'cancel', 'Cancel' ) ) + '</button>';
        html += '<button type="button" class="tt-btn tt-btn-primary" data-tt-complete-confirm="1">'
            + escapeHtml( i18n( 'completeTitle', 'Complete match' ) ) + '</button>';
        html += '</div>';
        html += '</form>';

        dialog.innerHTML = html;
        // Inside the scoped wrapper, so the plugin's own button and input
        // styles apply — every rule is `.tt-dashboard`-scoped, on the
        // assumption that the theme is hostile. `showModal()` lifts it into the
        // top layer regardless of where it sits in the DOM.
        ( btn.closest( '.tt-dashboard' ) || document.body ).appendChild( dialog );

        function total() {
            var sum = 0;
            dialog.querySelectorAll( '.tt-tour-complete__min' ).forEach( function ( input ) {
                sum += parseInt( input.value, 10 ) || 0;
            } );
            var out = dialog.querySelector( '[data-tt-complete-total="1"]' );
            if ( out ) out.textContent = fmt( i18n( 'minutesTotal', '%s minutes in total' ), [ sum ] );
        }
        total();
        dialog.addEventListener( 'input', total );

        function close() {
            if ( dialog.open ) dialog.close();
            dialog.remove();
        }

        dialog.querySelector( '[data-tt-complete-cancel="1"]' ).addEventListener( 'click', close );
        dialog.addEventListener( 'cancel', function () { dialog.remove(); } );

        dialog.querySelector( '[data-tt-complete-confirm="1"]' ).addEventListener( 'click', function ( e ) {
            var confirmBtn = e.currentTarget;
            var minutes = [];
            dialog.querySelectorAll( '.tt-tour-complete__min' ).forEach( function ( input ) {
                minutes.push( {
                    player_id: parseInt( input.getAttribute( 'data-player-id' ), 10 ),
                    minutes: parseInt( input.value, 10 ) || 0,
                } );
            } );
            // The shortfall has been shown and read, so the commit carries the
            // override rather than meeting a 409 the coach cannot act on.
            var payload = { minutes: minutes };
            if ( short.length ) payload.force = 1;
            confirmBtn.disabled = true;
            runMatchAction( tournamentId, matchId, 'complete', btn, payload )
                .then( function ( res ) {
                    if ( res && res.ok ) {
                        close();
                        return;
                    }
                    // The sheet stays up on a failure, with the numbers the
                    // coach typed still in it. Dismissing it would throw the
                    // work away along with the error.
                    confirmBtn.disabled = false;
                } );
        } );

        if ( typeof dialog.showModal === 'function' ) {
            dialog.showModal();
        } else {
            dialog.setAttribute( 'open', 'open' );
        }
        var first = dialog.querySelector( '.tt-tour-complete__min' );
        if ( first ) first.focus();
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

        // Per-window substitution summary — computed from the
        // per-row transitions. Surfaces ABOVE the grid so the coach
        // sees the planned swaps at a glance ("at 10': Lukas in for
        // Pim at RB") without having to scan each row visually.
        var subsSummaryHtml = renderSubsSummary( idx, minutesPerPeriod, periods, playerById, slotLabels );

        // Build column headers — with a narrow "transition" column
        // between adjacent period columns so each row can render an
        // arrow when the player changes between periods.
        var html = subsSummaryHtml;
        html += '<div class="tt-planner-grid" style="overflow-x:auto;">';
        html += '<table class="tt-planner-table"><thead><tr><th></th>';
        for ( var p = 0; p < periods; p++ ) {
            var pStart = p * minutesPerPeriod;
            var pEnd   = ( p + 1 ) * minutesPerPeriod;
            html += '<th>P' + ( p + 1 ) + ' <small>(' + pStart + "–" + pEnd + "')</small></th>";
            if ( p < periods - 1 ) {
                // Narrow column header above the transition arrow.
                // Carries the substitution window minute marker.
                html += '<th class="tt-planner-transition-head"><span class="tt-planner-sub-marker">@' + pEnd + "'</span></th>";
            }
        }
        html += '</tr></thead><tbody>';

        // One row per (formation line × slot in line). The flat list
        // of slot codes is line-major: [GK, RB, CB, LB, RM, CM, LM, RW, ST, LW].
        var flatSlots = [];
        slotLabels.forEach( function ( line ) {
            line.forEach( function ( code ) { flatSlots.push( code ); } );
        } );

        // colspan accounts for the slot-label column + every period
        // column + every transition column between periods.
        var totalColspan = 1 + periods + Math.max( 0, periods - 1 );

        if ( flatSlots.length === 0 ) {
            html += '<tr><td colspan="' + totalColspan + '" class="tt-muted">';
            html += 'No formation set on this match. Edit the match to pick a formation.';
            html += '</td></tr>';
        }

        flatSlots.forEach( function ( code ) {
            html += '<tr><th class="tt-planner-slot-label">' + escapeHtml( code ) + '</th>';
            for ( var p = 0; p < periods; p++ ) {
                var pid = idx.slots[ p + '|' + code ];
                // Mark the cell as "changed" when the previous period
                // held a different player at the same slot — visual
                // signal that a substitution lands here.
                var prevPid = p > 0 ? idx.slots[ ( p - 1 ) + '|' + code ] : undefined;
                var changed = p > 0 && prevPid !== pid;
                html += renderCell( p, code, pid, playerById, changed );
                if ( p < periods - 1 ) {
                    // Transition column: arrow when the next period
                    // holds a different player; blank dash when same.
                    var nextPid = idx.slots[ ( p + 1 ) + '|' + code ];
                    html += renderTransition( pid, nextPid );
                }
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
            if ( p < periods - 1 ) {
                // Bench row's transition column is blank (subs are
                // visible row-by-row at the position level above).
                html += '<td class="tt-planner-transition"></td>';
            }
        }
        html += '</tr>';

        html += '</tbody></table></div>';
        html += '<p class="tt-muted" style="font-size:12px;margin-top:6px;">Click a player chip, then click another cell to swap. Click the same chip again to deselect. Arrows mark substitutions.</p>';
        body.innerHTML = html;

        wireInteractions( body );
    }

    /**
     * Render the per-substitution-window summary panel that sits
     * above the grid. For each window between periods, list every
     * IN/OUT swap that happens at that minute, grouped by position.
     *
     * Visual: one collapsible block per window, with the minute
     * marker as the header (e.g. "Substitutions at 10'"), and the
     * swaps listed below as "Pim → Lukas (RB)" lines.
     *
     * Returns an empty string when there's only one period (no
     * substitution windows on this match).
     */
    function renderSubsSummary( idx, minutesPerPeriod, periods, playerById, slotLabels ) {
        if ( periods < 2 ) return '';

        var flatSlots = [];
        slotLabels.forEach( function ( line ) {
            line.forEach( function ( code ) { flatSlots.push( code ); } );
        } );

        var blocks = [];
        for ( var p = 0; p < periods - 1; p++ ) {
            var windowMin = ( p + 1 ) * minutesPerPeriod;
            var swaps = [];
            flatSlots.forEach( function ( code ) {
                var fromPid = idx.slots[ p + '|' + code ];
                var toPid   = idx.slots[ ( p + 1 ) + '|' + code ];
                if ( fromPid === toPid ) return;
                var fromName = fromPid ? ( playerById[ fromPid ] ? playerById[ fromPid ].full_name : 'Player #' + fromPid ) : '—';
                var toName   = toPid   ? ( playerById[ toPid ]   ? playerById[ toPid ].full_name   : 'Player #' + toPid )   : '—';
                swaps.push( {
                    code: code,
                    from_name: fromName,
                    to_name: toName,
                } );
            } );
            blocks.push( {
                window_min: windowMin,
                swaps: swaps,
            } );
        }

        var html = '<div class="tt-planner-subs-summary">';
        html += '<h4 class="tt-planner-subs-title">Planned substitutions</h4>';
        blocks.forEach( function ( b ) {
            html += '<div class="tt-planner-subs-window">';
            html += '<header><strong>@' + b.window_min + "'</strong> ";
            html += '<span class="tt-muted">(' + ( b.swaps.length === 0 ? 'no changes' : b.swaps.length + ' change' + ( b.swaps.length === 1 ? '' : 's' ) ) + ')</span>';
            html += '</header>';
            if ( b.swaps.length ) {
                html += '<ul class="tt-planner-subs-list">';
                b.swaps.forEach( function ( s ) {
                    html += '<li>';
                    html += '<span class="tt-planner-subs-pos">' + escapeHtml( s.code ) + '</span> ';
                    html += '<span class="tt-planner-subs-from">' + escapeHtml( s.from_name ) + '</span>';
                    html += ' <span class="tt-planner-subs-arrow">→</span> ';
                    html += '<span class="tt-planner-subs-to">' + escapeHtml( s.to_name ) + '</span>';
                    html += '</li>';
                } );
                html += '</ul>';
            }
            html += '</div>';
        } );
        html += '</div>';
        return html;
    }

    /**
     * Transition cell between adjacent period cells in the same
     * row. Arrow when the next-period player is different; faint
     * dash when the player stays.
     */
    function renderTransition( fromPid, toPid ) {
        var same = ( fromPid || 0 ) === ( toPid || 0 );
        if ( same ) {
            return '<td class="tt-planner-transition tt-planner-transition--same" aria-hidden="true">·</td>';
        }
        return '<td class="tt-planner-transition tt-planner-transition--swap" title="Substitution" aria-label="Substitution"><span class="tt-planner-transition-arrow">→</span></td>';
    }

    function renderCell( period, code, playerId, playerById, changed ) {
        var cls = 'tt-planner-cell';
        if ( changed ) cls += ' tt-planner-cell--changed';
        var html = '<td class="' + cls + '" data-period="' + period + '" data-position="' + escapeHtml( code ) + '">';
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

    function boot() {
        hydrate();
        bindScores();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }
} )();
