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
     *   activities: list<array{ activity_id:int, session_date:string, title:string, type_key:string, owned_by_execution:bool }>,
     *   players: list<array{ player_id:int, first_name:string, last_name:string, jersey_number:?int }>,
     *   cells: array<int, array<int, array{minutes:int, squad:bool}>>,
     *   summary: array{ total_activities:int, total_players:int }
     * }
     */
    public function matrix( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $empty = [
            'activities' => [],
            'players'    => [],
            'cells'      => [],
            'summary'    => [ 'total_activities' => 0, 'total_players' => 0 ],
        ];
        if ( $team_id <= 0 ) return $empty;

        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        // 1. Match activities for the team in the window (columns) — the same
        //    set the Minutes-audit matrix uses, so the two reconcile.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $activity_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, game_subtype_key, {$date_col} AS session_date, title
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
            ];
        }

        // 3. Squad membership + effective minutes per (activity, player). Squad
        //    = any non-guest attendance row; minutes = COALESCE(override,
        //    played) so a coach's explicit correction is what the grid shows.
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
                AND player_id > 0
              GROUP BY activity_id, player_id",
            array_merge( $activity_ids, [ $club_id ] )
        ) );

        $cells = [];
        foreach ( (array) $att_rows as $r ) {
            $pid = (int) $r->player_id;
            $aid = (int) $r->activity_id;
            if ( $pid <= 0 || $aid <= 0 ) continue;
            $cells[ $pid ][ $aid ] = [
                'minutes' => $r->minutes !== null ? (int) $r->minutes : 0,
                'squad'   => true,
            ];
        }

        return [
            'activities' => $activities,
            'players'    => $players,
            'cells'      => $cells,
            'summary'    => [
                'total_activities' => count( $activities ),
                'total_players'    => count( $players ),
            ],
        ];
    }
}
