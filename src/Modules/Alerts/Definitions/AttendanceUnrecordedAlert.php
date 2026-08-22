<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

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
 * they train?* A completed session with no attendance silently drops every
 * player's participation for that date.
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
        return __( 'An activity is marked completed but nobody recorded who was there. The session counts as finished while every player\'s attendance for that date is missing.', 'talenttrack' );
    }

    /**
     * Ages up after a fortnight — past that, nobody reliably remembers who
     * was at a training session, and the record is effectively unrecoverable.
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

        // NOT EXISTS rather than a LEFT JOIN + HAVING COUNT(*) = 0: it stops
        // at the first attendance row instead of aggregating all of them,
        // which matters on a table with one row per player per session.
        //
        // The status check is deliberate. A row with an empty status is a
        // roster placeholder, not a recorded observation, so a session full
        // of blank rows still counts as unrecorded.
        $sql = $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.team_id, a.coach_id
               FROM {$p}tt_activities a
              WHERE " . $this->baseWhere( 'a' ) . "
                AND a.plan_state = 'completed'
                AND a.session_date < DATE_SUB( NOW(), INTERVAL %d HOUR )
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_attendance att
                     WHERE att.activity_id = a.id
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
