<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MinutesQuery (#1034) — per-player minutes aggregation for a team
 * over a date window, partitioned by match-type (game_subtype_key).
 *
 * Sources of truth:
 *   - `tt_match_prep_lineup`            → starts per half
 *   - `tt_match_execution_substitutions` → subs_in / subs_off events
 *   - `tt_match_prep.half_length_minutes` → minute totals
 *   - `tt_activities.game_subtype_key`   → League / Cup / Friendly bucket
 *
 * For each match activity in the window, the same `computeMinutes()`
 * helper used by the match-finish endpoint resolves per-player minutes
 * from the lineup + substitution log. Per-player totals are summed
 * across all activities in the window.
 *
 * v1 scope:
 *   - Team-scoped only. A player-detail variant lives in a follow-up.
 *   - No REST endpoint — the view consumes the service directly. A
 *     `GET /talenttrack/v1/teams/{id}/minutes` endpoint is in #1034's
 *     scoped follow-ups.
 *   - No `Analytics\FactRegistry` integration. Same follow-up.
 */
final class MinutesQuery {

    /**
     * @return list<array{
     *     player_id:int, first_name:string, last_name:string, jersey_number:?int,
     *     total_minutes:int, matches:int, starts:int, subs_in:int, subs_off:int,
     *     by_type:array<string,int>,
     *     available_minutes:int
     * }>
     */
    public function forTeam( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        if ( $team_id <= 0 ) return [];

        // 1. Match activities for the team in the window. Both 'match'
        //    and 'game' keys treated as match-type (see #988 follow-up
        //    on the legacy 'game' / new 'match' co-existence).
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, game_subtype_key, session_date
               FROM {$p}tt_activities
              WHERE club_id = %d
                AND team_id = %d
                AND LOWER(activity_type_key) IN ( 'match', 'game' )
                AND session_date BETWEEN %s AND %s
              ORDER BY session_date ASC",
            $club_id, $team_id, $from, $to
        ) );
        if ( empty( $activities ) ) return [];

        $exec_repo = new MatchExecutionRepository();
        $prep_repo = new MatchPrepRepository();

        // Aggregators keyed by player_id.
        $totals      = []; // total minutes
        $matches     = []; // distinct matches the player got on for
        $starts      = [];
        $subs_in     = [];
        $subs_off    = [];
        $by_type     = []; // [pid][type_key] => minutes
        $available_minutes = 0; // squad-wide; same for every player on roster

        foreach ( $activities as $a ) {
            $aid = (int) $a->id;
            $type_key = (string) ( $a->game_subtype_key ?? '' );
            if ( $type_key === '' ) $type_key = 'unknown';

            $prep = $prep_repo->findByActivity( $aid );
            if ( ! $prep ) continue; // match without a prep — skip (no lineup data)

            $half_length = (int) $prep->half_length_minutes;
            if ( $half_length <= 0 ) $half_length = 35; // sane fallback
            $match_length = $half_length * 2;
            $available_minutes += $match_length;

            $lineup = $prep_repo->listLineup( (int) $prep->id );
            $start1 = [];
            $start2 = [];
            foreach ( $lineup as $l ) {
                if ( (int) $l->half === 1 ) $start1[] = (int) $l->player_id;
                if ( (int) $l->half === 2 ) $start2[] = (int) $l->player_id;
            }

            $exec = $exec_repo->findByActivity( $aid );
            $exec_id = $exec ? (int) $exec->id : 0;

            // #1489 — persisted per-player minutes (written to
            // tt_attendance.minutes_played by the match execution on
            // finish / finalize / pending-review edit) are the single
            // source of truth. Reading them fixes the "no data" case
            // where a live-tracked match recorded minutes but the
            // read-time recompute yielded nothing (e.g. the prep lineup
            // was cleared or never mirrored). Fall back to the
            // lineup-based recompute only when nothing was persisted yet
            // — a plan-only week, or a match finished before minutes were
            // written — so those weeks still appear.
            $minutes_map = self::persistedMinutes( $aid, $club_id );
            if ( empty( $minutes_map ) ) {
                $minutes_map = $exec_id > 0
                    ? $exec_repo->computeMinutes( $exec_id, $start1, $start2, $half_length, $half_length )
                    : [];
                if ( empty( $minutes_map ) ) {
                    // Match has a lineup but no execution / persisted
                    // minutes — credit starters with the full half length
                    // so plan-only weeks don't disappear from the report.
                    foreach ( $start1 as $pid ) $minutes_map[ $pid ] = ( $minutes_map[ $pid ] ?? 0 ) + $half_length;
                    foreach ( $start2 as $pid ) $minutes_map[ $pid ] = ( $minutes_map[ $pid ] ?? 0 ) + $half_length;
                }
            }

            // Starts counter — once per activity, even if started both halves.
            $on_pitch = [];
            foreach ( array_merge( $start1, $start2 ) as $pid ) {
                if ( ! isset( $starts[ $pid ] ) ) $starts[ $pid ] = 0;
                if ( ! isset( $on_pitch[ $pid ] ) ) {
                    $starts[ $pid ]++;
                    $on_pitch[ $pid ] = true;
                }
            }

            // Subs in / off counters from substitution log.
            $sub_rows = $exec_id > 0 ? $exec_repo->listSubstitutions( $exec_id ) : [];
            $subbed_on = [];
            $subbed_off = [];
            foreach ( $sub_rows as $sub ) {
                $on  = (int) $sub->player_on_id;
                $off = (int) $sub->player_off_id;
                if ( $on  > 0 ) $subbed_on[ $on ]   = true;
                if ( $off > 0 ) $subbed_off[ $off ] = true;
            }
            foreach ( array_keys( $subbed_on ) as $pid ) {
                if ( ! isset( $subs_in[ $pid ] ) ) $subs_in[ $pid ] = 0;
                $subs_in[ $pid ]++;
                if ( ! isset( $on_pitch[ $pid ] ) ) $on_pitch[ $pid ] = true;
            }
            foreach ( array_keys( $subbed_off ) as $pid ) {
                if ( ! isset( $subs_off[ $pid ] ) ) $subs_off[ $pid ] = 0;
                $subs_off[ $pid ]++;
            }

            // Fold minutes + match-type bucket per player.
            foreach ( $minutes_map as $pid => $mins ) {
                $pid = (int) $pid;
                if ( $pid <= 0 ) continue;
                $mins = (int) $mins;
                $totals[ $pid ]  = ( $totals[ $pid ]  ?? 0 ) + $mins;
                if ( ! isset( $by_type[ $pid ] ) ) $by_type[ $pid ] = [];
                $by_type[ $pid ][ $type_key ] = ( $by_type[ $pid ][ $type_key ] ?? 0 ) + $mins;
                // #1489 — a player with persisted minutes played in this
                // match even if they aren't in the (possibly empty) prep
                // lineup / sub log, so count the appearance.
                if ( isset( $on_pitch[ $pid ] ) || $mins > 0 ) {
                    $matches[ $pid ] = ( $matches[ $pid ] ?? 0 ) + 1;
                }
            }
        }

        if ( empty( $totals ) ) return [];

        // Player display info for the aggregated player_ids.
        $ids = array_keys( $totals );
        $in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, jersey_number
               FROM {$p}tt_players
              WHERE id IN ($in) AND club_id = %d",
            array_merge( $ids, [ $club_id ] )
        ) );

        $rows = [];
        foreach ( (array) $players as $pl ) {
            $pid = (int) $pl->id;
            $rows[] = [
                'player_id'         => $pid,
                'first_name'        => (string) $pl->first_name,
                'last_name'         => (string) $pl->last_name,
                'jersey_number'     => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
                'total_minutes'     => (int) ( $totals[ $pid ]  ?? 0 ),
                'matches'           => (int) ( $matches[ $pid ] ?? 0 ),
                'starts'            => (int) ( $starts[ $pid ]  ?? 0 ),
                'subs_in'           => (int) ( $subs_in[ $pid ] ?? 0 ),
                'subs_off'          => (int) ( $subs_off[ $pid ]?? 0 ),
                'by_type'           => $by_type[ $pid ] ?? [],
                'available_minutes' => $available_minutes,
            ];
        }

        // Default sort: total minutes desc, last_name asc.
        usort( $rows, function ( $a, $b ) {
            if ( $a['total_minutes'] !== $b['total_minutes'] ) {
                return $b['total_minutes'] - $a['total_minutes'];
            }
            return strcasecmp( $a['last_name'], $b['last_name'] );
        } );

        return $rows;
    }

    /**
     * #1489 — per-player persisted minutes for one activity, written to
     * tt_attendance.minutes_played by MatchExecutionRepository on finish
     * / finalize. Excludes guests and zero / NULL minutes (only players
     * who actually got on the pitch).
     *
     * @return array<int,int> player_id => minutes
     */
    private static function persistedMinutes( int $activity_id, int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT player_id, minutes_played
               FROM {$p}tt_attendance
              WHERE activity_id = %d
                AND club_id = %d
                AND is_guest = 0
                AND minutes_played IS NOT NULL
                AND minutes_played > 0",
            $activity_id, $club_id
        ) );
        $map = [];
        foreach ( (array) $rows as $r ) {
            $pid = (int) $r->player_id;
            if ( $pid > 0 ) $map[ $pid ] = (int) $r->minutes_played;
        }
        return $map;
    }
}
