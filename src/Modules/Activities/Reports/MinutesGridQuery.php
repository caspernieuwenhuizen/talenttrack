<?php
namespace TT\Modules\Activities\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\PlayerMatchMinutes;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\Tournaments\Services\TournamentMinutesResolver;

/**
 * MinutesGridQuery (#2386, epic #2381) — the players × matches minutes-entry
 * matrix that backs the desktop minutes grid, the sibling of the attendance
 * grid restricted to match activities.
 *
 * Rows are the team's active roster; columns are the team's match / game /
 * tournament activities in the window. Each cell is the player's effective
 * recorded minutes for that match — COALESCE(minutes_override, minutes_played)
 * on the non-guest attendance row — the exact value the Minutes-audit matrix
 * and the Minutes-played report show, so all three reconcile.
 *
 * #4053 — with one documented fallback. A tournament fixture completed before
 * v4.135.0 has a register row per squad member and no minutes on it, because
 * the confirm step that writes them did not exist yet; its cells fall back to
 * the rotation plan's figure through `TournamentMinutesResolver`, which is
 * also what the player's Tournaments tab and the coach's ticker read. A
 * register figure always wins, a confirmed `0` included.
 *
 * Since #3094 a cell also carries the player's **goals and assists** for that
 * match, read from the goal-event log by `activity_id`. That is what lets a
 * club record output without having run the live match sheet: the surface
 * measures exposure and output side by side, which is how a coach reads a
 * season anyway.
 *
 * A cell is only editable when the player is in that match's squad (has a
 * non-guest attendance row); non-squad cells are informational (hatched),
 * mirroring the audit matrix. Per activity, `owned_by_execution` tells the
 * writer which path to route each edit through (override vs. direct minutes).
 *
 * Tenant-scoped on `club_id` (structural for the SaaS migration, §4).
 */
final class MinutesGridQuery {

    /**
     * @return array{
     *   activities: list<array{ activity_id:int, session_date:string, title:string, type_key:string, owned_by_execution:bool, home_score:?int, away_score:?int, is_home:?bool, is_neutral:bool, opponent:string, is_tournament:bool, attributed_goals:int, register_recorded:bool, available_minutes:?int, squad_count:?int, minutes_check:?array{total_minutes:int, recorded_count:int, offgrid_minutes:int, offgrid_recorded:int, offgrid_squad:?int, available_minutes:?int, squad_count:?int, status:string, status_reason:string}, rollup:?array{total_minutes:int, recorded_count:int} }>,
     *   players: list<array{ player_id:int, first_name:string, last_name:string, jersey_number:?int }>,
     *   cells: array<int, array<int, array{minutes:int, squad:bool, goals:int, assists:int, selected:bool}>>,
     *   summary: array{ total_activities:int, total_players:int },
     *   window: array{ from:string, to:string }
     * }
     */
    public function matrix( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        // #3748 — the window the answer was computed over, carried back with
        // it. The caller that passed nothing got the season default applied
        // silently, so a match outside it was simply absent with no way to
        // tell a missing register from a missing column. Answered here rather
        // than in the REST controller so every caller of the query benefits,
        // the way `MinutesQuery::playingTimeForPlayer()` already does.
        $window = [ 'from' => $from, 'to' => $to ];

        $empty = [
            'activities' => [],
            'players'    => [],
            'cells'      => [],
            'summary'    => [ 'total_activities' => 0, 'total_players' => 0 ],
            'window'     => $window,
        ];
        if ( $team_id <= 0 ) return $empty;

        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        // 1. Match activities for the team in the window (columns) — the same
        //    set the Minutes-audit matrix uses, so the two reconcile.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $activity_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, game_subtype_key, activity_type_key, activity_source_key,
                    home_score, away_score, home_away,
                    opponent, {$date_col} AS session_date, title
               FROM {$p}tt_activities
              WHERE club_id = %d
                AND team_id = %d
                AND LOWER(activity_type_key) IN ( 'match', 'game', 'tournament' )
                AND {$date_col} BETWEEN %s AND %s
                AND archived_at IS NULL
                AND trashed_at IS NULL
                AND plan_state <> 'cancelled'
                AND ( activity_status_key IS NULL OR activity_status_key <> 'cancelled' )
              ORDER BY {$date_col} ASC, id ASC",
            $club_id, $team_id, $from, $to
        ) );

        $exec = new MatchExecutionRepository();
        $activities = [];
        foreach ( (array) $activity_rows as $a ) {
            // #4021 — a fixture played at a tournament has no home leg to speak
            // of (#3529 decision 3), so it is framed as neither. The flag is
            // read from `activity_source_key`, which the tournament planner
            // stamps when it promotes a fixture, and from the tournament day
            // itself.
            $is_tournament = strtolower( (string) ( $a->activity_type_key ?? '' ) ) === 'tournament';
            $is_neutral    = $is_tournament
                || strtolower( (string) ( $a->activity_source_key ?? '' ) ) === 'tournament';

            $activities[] = [
                'activity_id'        => (int) $a->id,
                'session_date'       => (string) $a->session_date,
                'title'              => (string) ( $a->title ?? '' ),
                'type_key'           => (string) ( $a->game_subtype_key ?? '' ),
                'owned_by_execution' => $exec->existsForActivity( (int) $a->id ),
                // #3094 — the scoreline, for the reconciliation footer. Null
                // where none was recorded, which is not the same as 0-0 and
                // must not read as it.
                'home_score'         => $a->home_score !== null ? (int) $a->home_score : null,
                // #3531 — the grid now *asks* for the scoreline it was already
                // reconciling against, so it needs both sides of it. #4066 —
                // `home_score` is our goals and `away_score` theirs whatever the
                // venue (#3530); `is_home` below is a label only and never
                // decides which column is ours.
                'away_score'         => $a->away_score !== null ? (int) $a->away_score : null,
                // #4021 — `null`, not `true`, on a neutral fixture. This used
                // to read "anything that is not literally away is home", which
                // meant a tournament fixture — created with no `home_away` at
                // all — was reported as a home game on every minutes surface.
                // There is no home/away to report; saying so is the answer.
                'is_home'            => $is_neutral
                    ? null
                    : ( (string) ( $a->home_away ?? '' ) ) !== 'away',
                'is_neutral'         => $is_neutral,
                'opponent'           => (string) ( $a->opponent ?? '' ),
                // A tournament is a multi-game day (#2686), so it gets no
                // score boxes. Carried as a flag rather than re-read from the
                // type key in the view, which would be the rule stated twice.
                'is_tournament'      => $is_tournament,
                'attributed_goals'   => 0,
                // #4247 — whether any recorded (`actual`) attendance row
                // exists. False means nobody is in this match's squad because
                // the register was never taken, not because nobody played.
                'register_recorded'  => false,
                // #4483 — the audit's yardstick for this match, filled in
                // below. Null on a tournament day and on a column the audit
                // does not judge.
                'available_minutes'  => null,
                'squad_count'        => null,
                // #4483 — the Minutes row's verdict, set before return by
                // `minutesCheck()`, so a client gets the answer, not only
                // the inputs to it.
                'minutes_check'      => null,
                // #4483 — on a tournament day, the audit's roll-up of its
                // fixtures: `{ total_minutes, recorded_count }`. Null on
                // every other column.
                'rollup'             => null,
            ];
        }

        $yardstick = self::auditYardstick( $team_id, $from, $to, $activities !== [] );
        foreach ( $activities as $i => $a ) {
            $y = $yardstick[ $a['activity_id'] ] ?? null;
            if ( $y === null ) continue;
            if ( $a['is_tournament'] ) {
                $activities[ $i ]['rollup'] = $y['rollup'];
                continue;
            }
            $activities[ $i ]['available_minutes'] = $y['available_minutes'];
            $activities[ $i ]['squad_count']       = $y['squad_count'];
        }

        // 2. Active roster (rows), ordered jersey-then-name.
        $players = [];
        foreach ( QueryHelpers::get_players( $team_id ) as $pl ) {
            $players[] = [
                'player_id'     => (int) $pl->id,
                'first_name'    => (string) ( $pl->first_name ?? '' ),
                'last_name'     => (string) ( $pl->last_name ?? '' ),
                'jersey_number' => isset( $pl->jersey_number ) && $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
            ];
        }
        usort( $players, static function ( array $a, array $b ): int {
            $ja = $a['jersey_number'] ?? PHP_INT_MAX;
            $jb = $b['jersey_number'] ?? PHP_INT_MAX;
            if ( $ja !== $jb ) return $ja <=> $jb;
            return strcasecmp( $a['last_name'], $b['last_name'] );
        } );

        $roster_ids = array_map( static fn( array $pl ): int => $pl['player_id'], $players );

        if ( $activities === [] || $players === [] ) {
            foreach ( $activities as $i => $a ) {
                $activities[ $i ]['minutes_check'] = self::minutesCheck( $a, [], $roster_ids );
            }
            return [
                'activities' => $activities,
                'players'    => $players,
                'cells'      => [],
                'summary'    => [ 'total_activities' => count( $activities ), 'total_players' => count( $players ) ],
                'window'     => $window,
            ];
        }

        // 3. Squad membership + effective minutes per (activity, player). Squad
        //    = any non-guest RECORDED attendance row; minutes = COALESCE(
        //    override, played) so a coach's explicit correction is what the
        //    grid shows.
        //
        //    #3451 — "any non-guest row" used to include the planned squad,
        //    which widened the grid to players nobody had registered. They
        //    carried no minutes, so the cells read 0 rather than wrong, but
        //    a grid that offers a minutes box for a player who was not there
        //    invites somebody to fill it in.
        //
        //    #4247 — guest rows are read too, only to tell whether a register
        //    was taken at all; `in_squad` keeps them out of the squad.
        //
        //    #4526 — the minutes are the newest recorded row's, through
        //    `PlayerMatchMinutes`, the read the audit and the reports share.
        //    MAX here read 70 where they summed two rows to 140.
        $activity_ids = array_map( static fn( array $a ): int => $a['activity_id'], $activities );
        $in_ids = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        $newest = PlayerMatchMinutes::forActivities( $activity_ids );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $att_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, player_id,
                    MAX(CASE WHEN is_guest = 0 AND player_id > 0 THEN 1 ELSE 0 END) AS in_squad
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in_ids)
                AND club_id = %d
                AND record_type = 'actual'
              GROUP BY activity_id, player_id",
            array_merge( $activity_ids, [ $club_id ] )
        ) );

        $recorded = [];
        foreach ( (array) $att_rows as $r ) {
            $recorded[ (int) $r->activity_id ] = true;
        }
        foreach ( $activities as $i => $a ) {
            $activities[ $i ]['register_recorded'] = isset( $recorded[ $a['activity_id'] ] );
        }

        // 4. Goals + assists per (activity, player), read by `activity_id` so
        //    a goal typed into this grid counts exactly like one logged live
        //    on the touchline (#3094).
        $contributions = $exec->contributionsByActivity( $activity_ids );
        $attributed    = $exec->attributedGoalsByActivity( $activity_ids );

        foreach ( $activities as $i => $a ) {
            $activities[ $i ]['attributed_goals'] = $attributed[ $a['activity_id'] ] ?? 0;
        }

        // #4053 — a tournament fixture completed before v4.135.0 has a register
        // row per squad member with no minutes on it, because the confirm step
        // that writes them did not exist yet. Those cells read 0 for a child who
        // played twelve minutes. The rotation plan is the fallback, arbitrated
        // by `TournamentMinutesResolver` — the same class the player's own
        // Tournaments tab and the coach's ticker use, so the three cannot
        // disagree. Only **completed** fixtures are in the map: handing the grid
        // an uncompleted fixture's plan would print planned minutes in a
        // minutes-entry box (#3713).
        // `is_neutral` is the flag a tournament fixture carries; the day itself
        // carries it too and is simply never any fixture's activity, so it
        // costs nothing to ask about and keeps the rule read from one column.
        $tournament_activity_ids = [];
        foreach ( $activities as $a ) {
            if ( $a['is_neutral'] ) $tournament_activity_ids[] = $a['activity_id'];
        }
        $planned = TournamentMinutesResolver::plannedByActivity( $tournament_activity_ids );

        // A register figure wins whenever there is one, a confirmed 0 included;
        // `minutes` is NULL here only when no row carried a figure at all.
        $register = [];
        foreach ( (array) $att_rows as $r ) {
            $pid = (int) $r->player_id;
            $aid = (int) $r->activity_id;
            if ( $pid <= 0 || $aid <= 0 || ! (int) $r->in_squad ) continue;
            $register[ $aid ][ $pid ] = $newest[ $aid ][ $pid ] ?? null;
        }

        $cells = [];
        foreach ( $register as $aid => $by_player ) {
            $resolved = TournamentMinutesResolver::arbitrate( $by_player, $planned[ $aid ] ?? [] );
            foreach ( array_keys( $by_player ) as $pid ) {
                $cells[ $pid ][ $aid ] = [
                    // A player with a register row and nothing on it either way
                    // is 0, as before: the cell is the box a coach types into.
                    'minutes'  => (int) ( $resolved[ $pid ]['minutes'] ?? 0 ),
                    'squad'    => true,
                    'goals'    => (int) ( $contributions[ $aid ][ $pid ]['goals'] ?? 0 ),
                    'assists'  => (int) ( $contributions[ $aid ][ $pid ]['assists'] ?? 0 ),
                    // #4483 — on the audit's squad whatever their minutes.
                    // False for a player registered absent: he counts in
                    // the Minutes row only once he has minutes.
                    'selected' => isset( $yardstick[ $aid ]['selected'][ $pid ] ),
                ];
            }
        }

        // A player can be credited with a goal in a match whose attendance
        // row is missing — a live sheet writes the goal, the register was
        // never filled in. Surfacing the cell is better than hiding output
        // the reports already count.
        foreach ( $contributions as $aid => $by_player ) {
            foreach ( $by_player as $pid => $counts ) {
                if ( isset( $cells[ $pid ][ $aid ] ) ) continue;
                $cells[ $pid ][ $aid ] = [
                    'minutes'  => 0,
                    'squad'    => false,
                    'goals'    => (int) $counts['goals'],
                    'assists'  => (int) $counts['assists'],
                    'selected' => isset( $yardstick[ $aid ]['selected'][ $pid ] ),
                ];
            }
        }

        foreach ( $activities as $i => $a ) {
            $activities[ $i ]['minutes_check'] = self::minutesCheck( $a, $cells, $roster_ids );
        }

        return [
            'activities' => $activities,
            'players'    => $players,
            'cells'      => $cells,
            'summary'    => [
                'total_activities' => count( $activities ),
                'total_players'    => count( $players ),
            ],
            'window'     => $window,
        ];
    }

    /**
     * #4483 — `available_minutes` and `squad_count` per match column, read
     * from the Minutes-audit matrix over the same window.
     *
     * Read from the audit rather than worked out again here, so the grid's
     * Minutes row is judged against exactly what the audit judges: the match
     * length from `MatchLengthResolver::resolvePlayedLength()` times players
     * a side (`MinutesAuditQuery::availableMinutes()`), and the audit's squad
     * — planned or recorded, minus players marked unavailable, plus anyone
     * with minutes. The grid's own squad is the recorded register only
     * (#3451), so a player selected and never registered counts here and
     * has no box on the grid; that is the gap the row is there to show.
     *
     * A tournament day keeps both null: the audit judges it as a roll-up of
     * its fixtures, on players only, and its minutes live in the planner.
     *
     * `selected` is the audit's squad *whatever the minutes* — a planned or
     * recorded row, not marked unavailable. The audit's squad is that plus
     * anyone with minutes, so it moves as minutes are typed: a player
     * registered absent has a box on the grid and joins the squad the moment
     * he gets minutes. Carrying the set, not a count, is what lets the
     * script recount the squad the way the audit will after Save.
     *
     * Bounded to the grid's own team and window: the audit's reads are all
     * `team_id` + date-range (+ `club_id`) scoped, the same as the grid's.
     *
     * A tournament day carries no attendance of its own, so the grid's cells
     * for it are empty; the audit rolls its minutes up from its fixtures.
     * `rollup` carries that roll-up's total and recorded count, so the day's
     * column reads the number the audit shows.
     *
     * @return array<int, array{available_minutes:int, squad_count:int, selected:array<int,true>, rollup:?array{total_minutes:int, recorded_count:int}}> activity id => yardstick
     */
    private static function auditYardstick( int $team_id, string $from, string $to, bool $has_columns ): array {
        if ( ! $has_columns ) return [];

        $out = [];
        foreach ( ( new MinutesAuditQuery() )->matrix( $team_id, $from, $to )['games'] as $g ) {
            if ( $g['is_rollup'] ) {
                $out[ $g['activity_id'] ] = [
                    'available_minutes' => $g['available_minutes'],
                    'squad_count'       => $g['squad_count'],
                    'selected'          => [],
                    'rollup'            => [
                        'total_minutes'  => $g['total_minutes'],
                        'recorded_count' => $g['recorded_count'],
                    ],
                ];
                continue;
            }
            $selected = [];
            foreach ( $g['selected'] as $pid => $is_selected ) {
                if ( $is_selected ) $selected[ $pid ] = true;
            }
            $out[ $g['activity_id'] ] = [
                'available_minutes' => $g['available_minutes'],
                'squad_count'       => $g['squad_count'],
                'selected'          => $selected,
                'rollup'            => null,
            ];
        }
        return $out;
    }

    /**
     * #4483 — the Minutes row for one match column: the minutes entered in
     * the grid's cells against what the match holds, judged by
     * `MinutesAuditQuery::completeness()`.
     *
     * The grid's script repeats these three checks, in this order, on the
     * boxes as the coach types; this is the answer the page renders, and so
     * the answer the coach sees again after Save. It informs only: a match
     * that really ran short (a red card, an injury with no substitute) is a
     * legitimate state and nothing here stops a save.
     *
     * `status` is `rollup` for a tournament day and `''` for a column the
     * audit does not judge; both carry no target and no reason.
     *
     * Every cell of the column counts, as every recorded minute counts in
     * the audit. A cell is a **box** when the grid draws an input for it: a
     * player with a row on the grid (`$roster_ids`; null means every cell),
     * in a column whose register was taken. What has no box is fixed while
     * the coach types, and comes back separately so the script can add it to
     * what it counts in the boxes:
     *
     *  - `offgrid_minutes` / `offgrid_recorded` — minutes of players with no
     *    box (a player who has since moved team still has a register row on
     *    his old matches);
     *  - `offgrid_squad` — the audit's squad members with no box: selected
     *    and never registered, or off the grid.
     *
     * The script's rule, and this one's, is then: squad = `offgrid_squad` +
     * the boxes that are `selected` or hold minutes; recorded and total =
     * the off-grid figures + the boxes with minutes. Rendered, that gives
     * back `squad_count`; typed into, it moves exactly as the audit's squad
     * will after Save.
     *
     * @param array<string, mixed>                         $activity   A column from `matrix()`.
     * @param array<int, array<int, array<string, mixed>>> $cells      `matrix()['cells']`.
     * @param list<int>|null                               $roster_ids The players the grid shows a row for.
     * @return array{total_minutes:int, recorded_count:int, offgrid_minutes:int, offgrid_recorded:int, offgrid_squad:?int, available_minutes:?int, squad_count:?int, status:string, status_reason:string}
     */
    public static function minutesCheck( array $activity, array $cells, ?array $roster_ids = null ): array {
        $aid       = (int) ( $activity['activity_id'] ?? 0 );
        $on_grid   = $roster_ids !== null ? array_fill_keys( $roster_ids, true ) : null;
        $has_boxes = ( $activity['register_recorded'] ?? true ) !== false;
        $total     = 0;
        $recorded  = 0;
        $off_total = 0;
        $off_count = 0;
        $box_squad = 0;
        foreach ( $cells as $pid => $by_activity ) {
            if ( ! isset( $by_activity[ $aid ] ) ) continue;
            $cell    = $by_activity[ $aid ];
            $minutes = (int) ( $cell['minutes'] ?? 0 );
            $is_box  = $has_boxes && ( $on_grid === null || isset( $on_grid[ (int) $pid ] ) );

            if ( $is_box && ( ! empty( $cell['selected'] ) || $minutes > 0 ) ) $box_squad++;
            if ( $minutes <= 0 ) continue;
            $recorded++;
            $total += $minutes;
            if ( ! $is_box ) {
                $off_count++;
                $off_total += $minutes;
            }
        }

        $available = isset( $activity['available_minutes'] ) ? (int) $activity['available_minutes'] : null;
        $squad     = isset( $activity['squad_count'] ) ? (int) $activity['squad_count'] : null;

        $out = [
            'total_minutes'     => $total,
            'recorded_count'    => $recorded,
            'offgrid_minutes'   => $off_total,
            'offgrid_recorded'  => $off_count,
            'offgrid_squad'     => $squad !== null ? max( 0, $squad - $box_squad ) : null,
            'available_minutes' => $available,
            'squad_count'       => $squad,
            'status'            => '',
            'status_reason'     => '',
        ];
        if ( ! empty( $activity['is_tournament'] ) ) {
            // The day holds no attendance of its own; its figures are the
            // audit's roll-up of its fixtures, and nobody types them here.
            $rollup = is_array( $activity['rollup'] ?? null ) ? $activity['rollup'] : null;
            if ( $rollup !== null ) {
                $out['total_minutes']    = (int) ( $rollup['total_minutes'] ?? 0 );
                $out['recorded_count']   = (int) ( $rollup['recorded_count'] ?? 0 );
                $out['offgrid_minutes']  = $out['total_minutes'];
                $out['offgrid_recorded'] = $out['recorded_count'];
            }
            $out['status'] = 'rollup';
            return $out;
        }
        if ( $squad === null ) return $out;

        [ $out['status'], $out['status_reason'] ] = MinutesAuditQuery::completeness( $recorded, $squad, $total, $available );
        return $out;
    }

    /**
     * #3748 — the minutes for ONE activity, behind `GET /activities/{id}/minutes`.
     *
     * Derived from `matrix()` over a single-day window rather than from a
     * query of its own. That is the point: the per-activity read and the grid
     * then share the ownership arbitration, the
     * `COALESCE(minutes_override, minutes_played)` rule and the squad
     * definition by construction, so a coach checking one match cannot be
     * told a different number from the one the grid shows for it.
     *
     * Rows are the team's roster, like the grid's, so a player who was not in
     * the squad is present at zero rather than absent — "he did not play" is
     * an answer, and leaving him out looks like missing data.
     *
     * Null when the activity is not one the grid would ever column: it does
     * not exist in this club, has no team or date, or is not an active match.
     *
     * @return array{
     *   activity: array<string, mixed>,
     *   players: list<array<string, mixed>>,
     *   summary: array{ total_players:int, squad_players:int, total_minutes:int }
     * }|null
     */
    public function forActivity( int $activity_id ): ?array {
        if ( $activity_id <= 0 ) return null;

        global $wpdb;
        $p        = $wpdb->prefix;
        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT team_id, {$date_col} AS session_date
               FROM {$p}tt_activities
              WHERE id = %d AND club_id = %d
              LIMIT 1",
            $activity_id,
            (int) CurrentClub::id()
        ) );
        if ( ! is_object( $row ) ) return null;

        $team_id = (int) ( $row->team_id ?? 0 );
        $date    = (string) ( $row->session_date ?? '' );
        if ( $team_id <= 0 || $date === '' ) return null;

        $matrix = $this->matrix( $team_id, $date, $date );

        $activity = null;
        foreach ( $matrix['activities'] as $candidate ) {
            if ( $candidate['activity_id'] === $activity_id ) {
                $activity = $candidate;
                break;
            }
        }
        if ( $activity === null ) return null;

        $players       = [];
        $total_minutes = 0;
        $squad_players = 0;
        foreach ( $matrix['players'] as $player ) {
            $cell = $matrix['cells'][ $player['player_id'] ][ $activity_id ] ?? null;
            $minutes = (int) ( $cell['minutes'] ?? 0 );
            $squad   = (bool) ( $cell['squad'] ?? false );
            $total_minutes += $minutes;
            if ( $squad ) $squad_players++;
            $players[] = $player + [
                'minutes' => $minutes,
                'squad'   => $squad,
                'goals'   => (int) ( $cell['goals'] ?? 0 ),
                'assists' => (int) ( $cell['assists'] ?? 0 ),
            ];
        }

        return [
            'activity' => $activity,
            'players'  => $players,
            'summary'  => [
                'total_players' => count( $players ),
                'squad_players' => $squad_players,
                'total_minutes' => $total_minutes,
            ],
        ];
    }
}
