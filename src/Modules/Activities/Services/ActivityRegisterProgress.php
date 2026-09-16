<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ActivityRegisterProgress (#3446, epic #3442) — how much of one
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

        $planned = (int) $wpdb->get_var( $wpdb->prepare(
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

    /** Test seam — the memo outlives a single fixture otherwise. */
    public static function forget( int $activity_id = 0 ): void {
        if ( $activity_id > 0 ) {
            unset( self::$memo[ $activity_id ] );
            return;
        }
        self::$memo = [];
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

        $recorded = self::recordedCount( $activity_id );
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
}
