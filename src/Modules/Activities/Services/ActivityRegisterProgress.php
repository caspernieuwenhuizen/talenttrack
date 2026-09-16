<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ActivityRegisterProgress (#3447, epic #3442) — how much of a completed
 * activity's register actually exists, as the `N/N` the list card prints.
 *
 * A completed activity says nothing about whether anyone recorded who was
 * there: the evaluation wizard can skip its own attendance step, a match
 * finished without match prep writes neither attendance nor minutes, and
 * the wizard-off "Mark completed" path is a bare status flip. Today that
 * only surfaces in a report nobody opens; a right-aligned count on the
 * list makes it visible where the coach already looks.
 *
 * **Actual rows only.** `tt_attendance` holds the planned roster and the
 * recorded register in the same table, separated by `record_type`
 * (`expected` / `actual`, migration 0121), and the expected rows carry
 * real statuses (`ActivitiesRestController::plannedStatusMap()` maps
 * Expected → Present). Counting them would make this readout report a
 * full register for exactly the activity whose register is missing — the
 * bug this epic exists to fix, relocated into the fix. The same missing
 * predicate has produced three separate defects (#3390, #3443, #3444).
 *
 * The denominator is the planned roster where the coach captured one,
 * falling back to the team's current active roster. That keeps a
 * September training reading `14/14` after a player leaves in March,
 * instead of drifting to `13/14` on its own — and finally gives the
 * `expected` rows a job.
 *
 * Minutes use a different denominator on purpose: the players marked
 * Present or Late, not the squad. A player who was absent is not missing
 * minutes. Matches only.
 *
 * The counts are a projection, so they live here rather than in the view
 * (CLAUDE.md §4) — the list card, the REST payload and any future
 * consumer get the same answer. `prime()` is the single door every read
 * goes through, so a list page costs two queries rather than two per row.
 */
final class ActivityRegisterProgress {

    /** Everything expected is recorded. */
    public const OK = 'ok';

    /** Somebody started and stopped. */
    public const PARTIAL = 'partial';

    /** Completed with nothing recorded — the case that is invisible today. */
    public const GAP = 'gap';

    /**
     * Activity id → raw counts, seeded by `prime()`.
     *
     * @var array<int, array{recorded:int, planned:int, min_expected:int, min_recorded:int}>
     */
    private static array $counts = [];

    /**
     * Team id → current active roster size.
     *
     * @var array<int, int>
     */
    private static array $rosters = [];

    /**
     * Read the counts for a whole page in two queries — one `GROUP BY
     * activity_id` over the rendered ids, one roster size per team on the
     * page. `renderActivityCard()` runs inside the bucket loop, so a
     * per-card read would be an N+1 across the list.
     *
     * Misses are seeded as zeroes: "this activity has no register" is a
     * real answer, and caching it is what stops a later `forRow()` call
     * re-querying a row the batch already covered.
     *
     * @param array<int, object> $rows Activity rows carrying id / team_id.
     */
    public static function prime( array $rows ): void {
        $activity_ids = [];
        $team_ids     = [];
        foreach ( $rows as $row ) {
            if ( ! is_object( $row ) ) continue;
            $id = (int) ( $row->id ?? 0 );
            if ( $id > 0 && ! isset( self::$counts[ $id ] ) ) $activity_ids[ $id ] = true;
            $team = (int) ( $row->team_id ?? 0 );
            if ( $team > 0 && ! isset( self::$rosters[ $team ] ) ) $team_ids[ $team ] = true;
        }
        if ( $activity_ids !== [] ) self::loadCounts( array_keys( $activity_ids ) );
        if ( $team_ids !== [] )     self::loadRosters( array_keys( $team_ids ) );
    }

    /**
     * The readout for one activity row, or null when the activity has no
     * register to be missing — anything not completed, a meeting, an
     * "other" activity, or an activity with no denominator to divide by.
     *
     * @return array{
     *   attendance: array{recorded:int, expected:int, state:string},
     *   minutes: array{recorded:int, expected:int, state:string}|null
     * }|null
     */
    public static function forRow( object $row ): ?array {
        $id = (int) ( $row->id ?? 0 );
        if ( $id <= 0 ) return null;

        $status = strtolower( trim( (string) ( $row->activity_status_key ?? '' ) ) );
        if ( $status !== ActivityStatusKey::COMPLETED ) return null;

        $type = strtolower( trim( (string) ( $row->activity_type_key ?? '' ) ) );
        if ( ! self::typeKeepsRegister( $type ) ) return null;

        if ( ! isset( self::$counts[ $id ] ) ) self::prime( [ $row ] );
        $counts = self::$counts[ $id ] ?? [ 'recorded' => 0, 'planned' => 0, 'min_expected' => 0, 'min_recorded' => 0 ];

        $team     = (int) ( $row->team_id ?? 0 );
        $expected = $counts['planned'] > 0 ? $counts['planned'] : ( self::$rosters[ $team ] ?? 0 );
        // Nothing to divide by — a club-wide activity with no team and no
        // captured plan. Printing `3/0` would be worse than printing
        // nothing, so the card renders as it does today.
        if ( $expected <= 0 ) return null;

        $recorded = min( $counts['recorded'], $expected );

        $minutes = null;
        if ( ActivityTypeKey::isMatchLike( $type ) && $counts['min_expected'] > 0 ) {
            $minutes = [
                'recorded' => $counts['min_recorded'],
                'expected' => $counts['min_expected'],
                'state'    => self::state( $counts['min_recorded'], $counts['min_expected'] ),
            ];
        }

        return [
            'attendance' => [
                'recorded' => $recorded,
                'expected' => $expected,
                'state'    => self::state( $recorded, $expected ),
            ],
            'minutes' => $minutes,
        ];
    }

    /**
     * The same projection shaped for JSON, so a non-WordPress front end
     * draws the same row. `null` where the PHP card renders nothing.
     *
     * @return array{
     *   attendance: array{recorded:int, expected:int, state:string},
     *   minutes: array{recorded:int, expected:int, state:string}|null
     * }|null
     */
    public static function restPayload( object $row ): ?array {
        return self::forRow( $row );
    }

    /**
     * A meeting has no register and an "other" activity has no roster
     * contract, so neither can be missing one. Everything else — training,
     * game, tournament, and any operator-renamed key — keeps its count.
     */
    private static function typeKeepsRegister( string $type_key ): bool {
        return ! in_array( $type_key, [ ActivityTypeKey::MEETING, ActivityTypeKey::OTHER ], true );
    }

    private static function state( int $recorded, int $expected ): string {
        if ( $recorded <= 0 )       return self::GAP;
        if ( $recorded >= $expected ) return self::OK;
        return self::PARTIAL;
    }

    /**
     * One `GROUP BY activity_id` pass over the page's ids.
     *
     * Every numerator is fenced to `record_type = 'actual'` and
     * `is_guest = 0`; the planned denominator is the `expected` count,
     * guests excluded there too, matching the scope the hardened minutes
     * reports use (#2193). Minutes are counted only on the Present / Late
     * rows they are owed for, so `recorded` can never exceed `expected`.
     * Status literals are Title Case per `AttendanceStatus`; the tables
     * are `_ci`, so the comparison is case-insensitive either way.
     *
     * @param list<int> $activity_ids
     */
    private static function loadCounts( array $activity_ids ): void {
        global $wpdb;
        $p            = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id,
                    SUM( CASE WHEN record_type = 'actual' AND is_guest = 0
                                   AND status IS NOT NULL AND status <> '' THEN 1 ELSE 0 END ) AS recorded,
                    SUM( CASE WHEN record_type = 'expected' AND is_guest = 0 THEN 1 ELSE 0 END ) AS planned,
                    SUM( CASE WHEN record_type = 'actual' AND is_guest = 0
                                   AND status IN ( 'Present', 'Late' ) THEN 1 ELSE 0 END ) AS min_expected,
                    SUM( CASE WHEN record_type = 'actual' AND is_guest = 0
                                   AND status IN ( 'Present', 'Late' )
                                   AND minutes_played IS NOT NULL THEN 1 ELSE 0 END ) AS min_recorded
               FROM {$p}tt_attendance
              WHERE club_id = %d AND activity_id IN ({$placeholders})
              GROUP BY activity_id",
            array_merge( [ CurrentClub::id() ], $activity_ids )
        ) );

        $found = [];
        foreach ( $rows ?: [] as $r ) {
            $found[ (int) $r->activity_id ] = [
                'recorded'     => (int) $r->recorded,
                'planned'      => (int) $r->planned,
                'min_expected' => (int) $r->min_expected,
                'min_recorded' => (int) $r->min_recorded,
            ];
        }
        foreach ( $activity_ids as $id ) {
            self::$counts[ $id ] = $found[ $id ]
                ?? [ 'recorded' => 0, 'planned' => 0, 'min_expected' => 0, 'min_recorded' => 0 ];
        }
    }

    /**
     * Current non-archived roster size per team, one query for the whole
     * page. Same shape the attendance breakdown uses, so the fallback
     * denominator agrees with the detail page's.
     *
     * @param list<int> $team_ids
     */
    private static function loadRosters( array $team_ids ): void {
        global $wpdb;
        $p            = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT team_id, COUNT(*) AS n
               FROM {$p}tt_players
              WHERE club_id = %d AND team_id IN ({$placeholders})
                AND status = 'active' AND archived_at IS NULL
              GROUP BY team_id",
            array_merge( [ CurrentClub::id() ], $team_ids )
        ) );

        $found = [];
        foreach ( $rows ?: [] as $r ) {
            $found[ (int) $r->team_id ] = (int) $r->n;
        }
        foreach ( $team_ids as $tid ) {
            self::$rosters[ $tid ] = $found[ $tid ] ?? 0;
        }
    }
}
