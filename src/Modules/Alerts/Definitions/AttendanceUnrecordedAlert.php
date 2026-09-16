<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * AttendanceUnrecordedAlert (#2631, epic #2629).
 *
 * The activity was marked completed — so the coach did come back to it —
 * but no attendance was ever recorded against it. That is the more
 * insidious version of the past-still-planned gap: the activity looks
 * finished on every screen, and only the reports know it is empty.
 *
 * Which player question does this answer? *Where was this player, and did
 * they train?* A completed activity with no attendance silently drops every
 * player's participation for that date.
 *
 * "Recorded" means the register, not the plan. A roster ticked when the
 * activity was created lives in the same table as the register that was
 * taken afterwards, and the planned rows carry real statuses — so for its
 * first two years this alert read a plan as a register and stayed silent on
 * the most common version of the failure it exists for (#3444).
 *
 * 48 hours of grace before the alert appears: recording attendance the next
 * morning is normal practice, and an alert that fires the same evening
 * would be wrong more often than right.
 */
final class AttendanceUnrecordedAlert extends AbstractActivityAlert {

    private const GRACE_HOURS      = 48;
    private const URGENT_AFTER_DAYS = 14;

    public function key(): string {
        return 'activities.attendance_unrecorded';
    }

    public function label(): string {
        return __( 'Attendance not recorded', 'talenttrack' );
    }

    public function description(): string {
        return __( 'An activity is marked completed but nobody recorded who was there. It counts as finished while every player\'s attendance for that date is missing.', 'talenttrack' );
    }

    /**
     * Ages up after a fortnight — past that, nobody reliably remembers who
     * was at a training, and the record is effectively unrecoverable.
     */
    protected function severityFor( object $row ): string {
        $days = $this->daysSince( (string) ( $row->session_date ?? '' ) );
        return $days >= self::URGENT_AFTER_DAYS ? Severity::URGENT : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $name = trim( (string) ( $row->title ?? '' ) );
        if ( $name === '' ) $name = __( 'Untitled activity', 'talenttrack' );

        return sprintf(
            /* translators: %s: activity name */
            __( 'No attendance was recorded for %s.', 'talenttrack' ),
            $name
        );
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // #3444 — the lifecycle gate reads `activity_status_key`, through the
        // shared predicate #2521 introduced for exactly this question.
        // `plan_state` was added `DEFAULT 'completed'` and only the team
        // planner ever sets it, so gating on it fired this alert on
        // activities nobody ever completed — which is `PastStillPlannedAlert`'s
        // subject, not this one's.
        $completed = ActivityLifecycle::completedClause( 'a' );

        // NOT EXISTS rather than a LEFT JOIN + HAVING COUNT(*) = 0: it stops
        // at the first attendance row instead of aggregating all of them,
        // which matters on a table with one row per player per activity.
        //
        // What counts as "recorded" is three predicates, not one, and each
        // excludes a different kind of row that is not an observation:
        //
        //  - `record_type = 'actual'` (#3444). `tt_attendance` holds the
        //    planned roster too (migration 0121), and planned rows carry real
        //    statuses — Expected is stored as Present. Without this, an
        //    activity whose roster was ticked at creation and whose register
        //    was never taken looked fully recorded, and the alert built for
        //    that exact failure stayed silent. `PlayerAttendanceCalculator`
        //    and `TeamKpisRepository` already scope this way.
        //  - `is_guest = 0` (#3444). A guest row is somebody else's player
        //    turning out; it says nothing about whether this squad's register
        //    was taken.
        //  - a non-empty status. A row with an empty status is a roster
        //    placeholder, not a recorded observation, so an activity full of
        //    blank rows still counts as unrecorded.
        $sql = $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.team_id, a.coach_id
               FROM {$p}tt_activities a
              WHERE " . $this->baseWhere( 'a' ) . "
                AND {$completed}
                AND a.session_date < DATE_SUB( NOW(), INTERVAL %d HOUR )
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_attendance att
                     WHERE att.activity_id = a.id
                       AND att.record_type = 'actual'
                       AND att.is_guest = 0
                       AND att.status IS NOT NULL
                       AND att.status <> ''
                )"
            . $context->applyScope( self::SUBJECT_TYPE, 'a.id' ) . "
              ORDER BY a.session_date ASC, a.id ASC",
            self::GRACE_HOURS
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
