<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ActivityRegisterProgress (#3446, #3447, epic #3442) — how much of one
 * activity's attendance register exists.
 *
 * Five separate paths reach `activity_status_key = 'completed'`, and none
 * of them asked whether anyone had been marked present. The guard that
 * asks now is one service rather than five checks: the REST controller,
 * the wizard and the render surfaces all need the same answer, and a
 * predicate rebuilt per caller is a predicate that drifts (CLAUDE.md §4).
 * It also means a non-WordPress front end gets the identical answer.
 *
 * ## What counts as recorded
 *
 * `tt_attendance` holds the planned roster and the recorded register in
 * the same table, told apart only by `record_type` (migration 0121), and
 * the planned rows carry the register's own statuses — Expected is stored
 * as `Present`. Counting without that filter is the blind spot #3443
 * fixed in the wizard and #3390 fixed on the player's own screen, so this
 * service states it once, in one place: a recorded row is
 * `record_type = 'actual'`, `is_guest = 0`, and a non-empty `status`.
 *
 * Guests are excluded because the denominator is a roster and a guest is
 * not on it; a status-less row is excluded because match prep's lineup
 * upsert writes one and it means "in the squad", not "was here".
 *
 * ## The denominator
 *
 * The planned roster where one was captured, falling back to the team's
 * current roster (epic #3442, decision 3). Historically honest — a squad
 * of 14 planned in March is not re-judged against a squad of 19 in
 * June — and it finally gives the expected rows a job.
 *
 * ## Two shapes, one predicate (#3447)
 *
 * The completion guard asks about one activity and wants a verdict; the
 * activity list asks about fifty and wants the numbers. Same rule, two
 * access patterns, so they are two entry points over one definition
 * rather than two services that could disagree:
 *
 *   - `state()` / `isEmpty()` — the verdict for a single activity,
 *     reading it from the database on demand.
 *   - `prime()` + `forRow()` — the `N/N` readout for a page of rows,
 *     batched into one `GROUP BY activity_id` plus one roster count for
 *     the teams on the page. `renderActivityCard()` runs inside the
 *     bucket loop, so a per-card read would be an N+1 across the list.
 *
 * `forRow()` also carries **minutes** for match-family activities, which
 * the single-activity verdict has no use for. Minutes are owed by a
 * different population on purpose: the players marked Present or Late,
 * not the squad. A player who was absent is not missing minutes.
 */
final class ActivityRegisterProgress {

    /** Nothing recorded. The activity would complete with an empty register. */
    public const NONE = 'none';

    /**
     * Some of the roster recorded, some not. A legitimate end state — a
     * coach who marks the eight players they are sure about has recorded
     * something true — so nothing interrupts it. #3447's readout is where
     * partial becomes visible.
     */
    public const PARTIAL = 'partial';

    /** Everyone the register was meant to cover is on it. */
    public const COMPLETE = 'complete';

    /**
     * There is no register to be missing: a meeting or other non-roster
     * type, an activity with no team, or a team with nobody on it and no
     * plan. These complete without anything being said.
     */
    public const NOT_APPLICABLE = 'not_applicable';

    /**
     * The types that never carry a register. A meeting has attendees in
     * the ordinary sense but not a squad whose participation belongs on a
     * player's development record, and `other` is by definition the
     * catch-all for activities the academy models loosely.
     *
     * @var list<string>
     */
    private const NO_REGISTER_TYPES = [ ActivityTypeKey::MEETING, ActivityTypeKey::OTHER ];

    /**
     * Per-request memo. A list render asks about the same activity more
     * than once and the answer costs up to three reads.
     *
     * @var array<int, string>
     */
    private static array $memo = [];

    /**
     * #3447 — per-activity raw counts for a primed page. Seeded by
     * `prime()` and read by `forRow()`, `recordedCount()` and
     * `expectedCount()`, so a card that has been primed costs nothing.
     *
     * @var array<int, array{recorded:int, planned:int, min_expected:int, min_recorded:int}>
     */
    private static array $counts = [];

    /**
     * #3447 — team id → current roster size, for the pages where no plan
     * was captured and the fallback denominator applies.
     *
     * @var array<int, int>
     */
    private static array $rosters = [];

    public static function state( int $activity_id ): string {
        if ( $activity_id <= 0 ) return self::NOT_APPLICABLE;
        if ( isset( self::$memo[ $activity_id ] ) ) return self::$memo[ $activity_id ];

        return self::$memo[ $activity_id ] = self::resolve( $activity_id );
    }

    /**
     * The one question the completion guard asks: would completing this
     * activity right now leave every player's participation for the date
     * unrecorded?
     */
    public static function isEmpty( int $activity_id ): bool {
        return self::state( $activity_id ) === self::NONE;
    }

    /**
     * Rows on the register. Public because #3447's `N/N` readout is the
     * same two numbers this service already has.
     */
    public static function recordedCount( int $activity_id ): int {
        if ( $activity_id <= 0 ) return 0;
        if ( isset( self::$counts[ $activity_id ] ) ) return self::$counts[ $activity_id ]['recorded'];
        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_attendance
              WHERE activity_id = %d AND club_id = %d
                AND record_type = 'actual'
                AND is_guest = 0
                AND status IS NOT NULL AND status <> ''",
            $activity_id, CurrentClub::id()
        ) );
    }

    /** How many players the register is meant to cover. */
    public static function expectedCount( int $activity_id ): int {
        if ( $activity_id <= 0 ) return 0;
        global $wpdb;
        $p = $wpdb->prefix;

        $planned = isset( self::$counts[ $activity_id ] )
            ? self::$counts[ $activity_id ]['planned']
            : (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_attendance
                  WHERE activity_id = %d AND club_id = %d
                    AND record_type = 'expected'
                    AND is_guest = 0",
                $activity_id, CurrentClub::id()
            ) );
        if ( $planned > 0 ) return $planned;

        $team_id = self::teamId( $activity_id );
        if ( $team_id <= 0 ) return 0;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_players
              WHERE team_id = %d AND club_id = %d AND archived_at IS NULL",
            $team_id, CurrentClub::id()
        ) );
    }

    /**
     * #3447 — read a whole page's counts up front: one `GROUP BY
     * activity_id` over the rendered ids, plus one roster count for the
     * teams on the page. Misses are seeded as zeroes, because "this
     * activity has no register" is a real answer and caching it is what
     * stops a later `forRow()` re-querying a row the batch covered.
     *
     * @param array<int, object> $rows Activity rows carrying id / team_id.
     */
    public static function prime( array $rows ): void {
        $activity_ids = [];
        $team_ids     = [];
        foreach ( $rows as $row ) {
            $id = (int) ( $row->id ?? 0 );
            if ( $id > 0 && ! isset( self::$counts[ $id ] ) ) $activity_ids[ $id ] = true;
            $team = (int) ( $row->team_id ?? 0 );
            if ( $team > 0 && ! isset( self::$rosters[ $team ] ) ) $team_ids[ $team ] = true;
        }
        if ( $activity_ids !== [] ) self::loadCounts( array_keys( $activity_ids ) );
        if ( $team_ids !== [] )     self::loadRosters( array_keys( $team_ids ) );
    }

    /**
     * #3447 — the `N/N` readout for one row of the activity list, or null
     * where there is no register to be missing: anything not completed, a
     * meeting, an "other" activity, or an activity with no denominator to
     * divide by. `state` on each measure is one of the constants above.
     *
     * Takes the row rather than an id because the list already holds it,
     * and because the batch is keyed off the same two fields.
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
        if ( in_array( $type, self::NO_REGISTER_TYPES, true ) ) return null;

        if ( ! isset( self::$counts[ $id ] ) ) self::prime( [ $row ] );
        $counts = self::$counts[ $id ] ?? self::emptyCounts();

        $team     = (int) ( $row->team_id ?? 0 );
        $expected = $counts['planned'] > 0 ? $counts['planned'] : ( self::$rosters[ $team ] ?? 0 );
        // Nothing to divide by — a club-wide activity with no team and no
        // captured plan. Printing `3/0` would be worse than printing
        // nothing, so the card renders as it did before.
        if ( $expected <= 0 ) return null;

        $recorded = min( $counts['recorded'], $expected );

        $minutes = null;
        if ( ActivityTypeKey::isMatchLike( $type ) && $counts['min_expected'] > 0 ) {
            $minutes = [
                'recorded' => $counts['min_recorded'],
                'expected' => $counts['min_expected'],
                'state'    => self::rate( $counts['min_recorded'], $counts['min_expected'] ),
            ];
        }

        return [
            'attendance' => [
                'recorded' => $recorded,
                'expected' => $expected,
                'state'    => self::rate( $recorded, $expected ),
            ],
            'minutes' => $minutes,
        ];
    }

    /** Test seam — the memo outlives a single fixture otherwise. */
    public static function forget( int $activity_id = 0 ): void {
        if ( $activity_id > 0 ) {
            unset( self::$memo[ $activity_id ], self::$counts[ $activity_id ] );
            return;
        }
        self::$memo    = [];
        self::$counts  = [];
        self::$rosters = [];
    }

    private static function resolve( int $activity_id ): string {
        global $wpdb;
        $p = $wpdb->prefix;

        // A missing activity and a club-wide one both land on team 0, and
        // both want the same answer: there is no roster here whose
        // participation could go missing.
        if ( self::teamId( $activity_id ) <= 0 ) return self::NOT_APPLICABLE;

        $type = strtolower( trim( (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_type_key FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) ) ) );
        if ( in_array( $type, self::NO_REGISTER_TYPES, true ) ) return self::NOT_APPLICABLE;

        $expected = self::expectedCount( $activity_id );
        if ( $expected <= 0 ) return self::NOT_APPLICABLE;

        return self::rate( self::recordedCount( $activity_id ), $expected );
    }

    /**
     * The verdict itself, over two numbers. Both entry points come through
     * here so the completion guard and the list readout cannot grade the
     * same register differently.
     */
    private static function rate( int $recorded, int $expected ): string {
        if ( $recorded <= 0 ) return self::NONE;
        return $recorded >= $expected ? self::COMPLETE : self::PARTIAL;
    }

    private static function teamId( int $activity_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
    }

    /** @return array{recorded:int, planned:int, min_expected:int, min_recorded:int} */
    private static function emptyCounts(): array {
        return [ 'recorded' => 0, 'planned' => 0, 'min_expected' => 0, 'min_recorded' => 0 ];
    }

    /**
     * #3447 — one pass over the page's ids, producing exactly the numbers
     * `recordedCount()` and `expectedCount()` produce one at a time, plus
     * the two minutes counts the list needs and the single-activity
     * verdict does not.
     *
     * Minutes are counted only on the Present / Late rows they are owed
     * for, on both sides, so `recorded` can never exceed `expected`.
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
            self::$counts[ $id ] = $found[ $id ] ?? self::emptyCounts();
        }
    }

    /**
     * #3447 — the fallback denominator for a whole page, one query. Same
     * predicate as `expectedCount()`'s own roster read, so the batched
     * and unbatched paths cannot disagree about who is on a squad.
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
              WHERE club_id = %d AND team_id IN ({$placeholders}) AND archived_at IS NULL
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
