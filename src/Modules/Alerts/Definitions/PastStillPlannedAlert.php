<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * PastStillPlannedAlert (#2631, epic #2629) — the driver case.
 *
 * An activity whose date has passed but which is still sitting at
 * `planned` / `scheduled`. Nobody marked it completed or cancelled, so as
 * far as every report is concerned it never happened: attendance was never
 * recorded, minutes were never logged, and the attendance reports are
 * quietly wrong (the class of problem #2521 fixed on the reporting side —
 * this is the same gap seen from the data-entry side).
 *
 * Which player question does this answer? *What is missing from this
 * player's record right now?* An unmarked session is a hole in every
 * player who attended it, and nobody currently gets told about it.
 *
 * Self-resolving: the coach opens the activity, marks it completed, and the
 * next sweep stops seeing it. There is nothing for them to dismiss.
 */
final class PastStillPlannedAlert extends AbstractActivityAlert {

    /** Days after which an unmarked session stops being a nudge. */
    private const URGENT_AFTER_DAYS = 7;

    public function key(): string {
        return 'activities.past_still_planned';
    }

    public function label(): string {
        return __( 'Past activity still planned', 'talenttrack' );
    }

    public function description(): string {
        return __( 'An activity whose date has passed is still marked as planned. Until it is completed or cancelled, its attendance and minutes are missing from every report.', 'talenttrack' );
    }

    /**
     * Ages up after a week. A session unmarked overnight is an oversight; one
     * unmarked for a fortnight is a hole in the record that is only getting
     * harder to fill from memory.
     */
    protected function severityFor( object $row ): string {
        $days = $this->daysSince( (string) ( $row->session_date ?? '' ) );
        return $days >= self::URGENT_AFTER_DAYS ? Severity::URGENT : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $days = $this->daysSince( (string) ( $row->session_date ?? '' ) );
        $name = trim( (string) ( $row->title ?? '' ) );
        if ( $name === '' ) $name = __( 'Untitled activity', 'talenttrack' );

        return sprintf(
            /* translators: 1: activity name, 2: number of days it has been unmarked */
            _n(
                '%1$s is still marked as planned, %2$d day after it took place.',
                '%1$s is still marked as planned, %2$d days after it took place.',
                $days,
                'talenttrack'
            ),
            $name,
            $days
        );
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // `session_date < CURDATE()` rather than `<=`: an activity happening
        // today has not finished yet, and telling a coach at 09:00 that
        // tonight's session is unmarked would train them to ignore this.
        $sql = "SELECT a.id, a.title, a.session_date, a.team_id, a.coach_id
                  FROM {$p}tt_activities a
                 WHERE " . $this->baseWhere( 'a' ) . "
                   AND a.session_date < CURDATE()
                   AND a.plan_state IN ( 'planned', 'scheduled' )"
             . $context->applyScope( self::SUBJECT_TYPE, 'a.id' ) . "
                 ORDER BY a.session_date ASC, a.id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
