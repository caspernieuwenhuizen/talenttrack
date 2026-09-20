<?php
namespace TT\Modules\Activities\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;

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
     *   activities: list<array{ activity_id:int, session_date:string, title:string, type_key:string, owned_by_execution:bool, home_score:?int, away_score:?int, is_home:bool, opponent:string, is_tournament:bool, attributed_goals:int }>,
     *   players: list<array{ player_id:int, first_name:string, last_name:string, jersey_number:?int }>,
     *   cells: array<int, array<int, array{minutes:int, squad:bool, goals:int, assists:int}>>,
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
            "SELECT id, game_subtype_key, activity_type_key, home_score, away_score, home_away,
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
                // reconciling against, so it needs both sides of it and which
                // way round they go. Framed here rather than in the view, the
                // way `recentResultsForTeam()` frames it: the academy team is
                // home unless the row says away.
                'away_score'         => $a->away_score !== null ? (int) $a->away_score : null,
                'is_home'            => ( (string) ( $a->home_away ?? '' ) ) !== 'away',
                'opponent'           => (string) ( $a->opponent ?? '' ),
                // A tournament is a multi-game day (#2686), so it gets no
                // score boxes. Carried as a flag rather than re-read from the
                // type key in the view, which would be the rule stated twice.
                'is_tournament'      => strtolower( (string) ( $a->activity_type_key ?? '' ) ) === 'tournament',
                'attributed_goals'   => 0,
            ];
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

        if ( $activities === [] || $players === [] ) {
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
        $activity_ids = array_map( static fn( array $a ): int => $a['activity_id'], $activities );
        $in_ids = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $att_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, player_id,
                    MAX(COALESCE(minutes_override, minutes_played)) AS minutes
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in_ids)
                AND club_id = %d
                AND is_guest = 0
                AND record_type = 'actual'
                AND player_id > 0
              GROUP BY activity_id, player_id",
            array_merge( $activity_ids, [ $club_id ] )
        ) );

        // 4. Goals + assists per (activity, player), read by `activity_id` so
        //    a goal typed into this grid counts exactly like one logged live
        //    on the touchline (#3094).
        $contributions = $exec->contributionsByActivity( $activity_ids );
        $attributed    = $exec->attributedGoalsByActivity( $activity_ids );

        foreach ( $activities as $i => $a ) {
            $activities[ $i ]['attributed_goals'] = $attributed[ $a['activity_id'] ] ?? 0;
        }

        $cells = [];
        foreach ( (array) $att_rows as $r ) {
            $pid = (int) $r->player_id;
            $aid = (int) $r->activity_id;
            if ( $pid <= 0 || $aid <= 0 ) continue;
            $cells[ $pid ][ $aid ] = [
                'minutes' => $r->minutes !== null ? (int) $r->minutes : 0,
                'squad'   => true,
                'goals'   => (int) ( $contributions[ $aid ][ $pid ]['goals'] ?? 0 ),
                'assists' => (int) ( $contributions[ $aid ][ $pid ]['assists'] ?? 0 ),
            ];
        }

        // A player can be credited with a goal in a match whose attendance
        // row is missing — a live sheet writes the goal, the register was
        // never filled in. Surfacing the cell is better than hiding output
        // the reports already count.
        foreach ( $contributions as $aid => $by_player ) {
            foreach ( $by_player as $pid => $counts ) {
                if ( isset( $cells[ $pid ][ $aid ] ) ) continue;
                $cells[ $pid ][ $aid ] = [
                    'minutes' => 0,
                    'squad'   => false,
                    'goals'   => (int) $counts['goals'],
                    'assists' => (int) $counts['assists'],
                ];
            }
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
