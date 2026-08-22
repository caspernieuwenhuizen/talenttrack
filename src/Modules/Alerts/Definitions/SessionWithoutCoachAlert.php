<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * SessionWithoutCoachAlert (#2631, epic #2629).
 *
 * A session in the next week with nobody assigned to run it. The only one
 * of the three wave 1 definitions that looks forwards — it is a chance to
 * prevent a problem rather than a report of one that already happened.
 *
 * Which player question does this answer? *Is this player's next session
 * actually going to happen?* An unstaffed session is one that gets
 * cancelled at short notice, and cancelled sessions are missing development
 * time for every player in the squad.
 *
 * Audience note: `coach_id` is empty by definition here, so the recipient
 * is always the team's head coach, via `AbstractActivityAlert`. A session
 * with neither an assigned coach nor a team head coach produces no
 * occurrence — there is genuinely nobody to tell, and inventing a
 * recipient would mean routing a squad's problem to an administrator who
 * cannot fix it. Surfacing those is the roll-up's job in #2633.
 */
final class SessionWithoutCoachAlert extends AbstractActivityAlert {

    private const LOOKAHEAD_DAYS = 7;
    private const URGENT_WITHIN_DAYS = 2;

    public function key(): string {
        return 'activities.session_without_coach';
    }

    public function label(): string {
        return __( 'Upcoming session has no coach', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A session in the next week has nobody assigned to run it. Unstaffed sessions tend to be cancelled late, which costs every player in the squad a training slot.', 'talenttrack' );
    }

    /** Urgent inside 48 hours — past that there is no time to arrange cover. */
    protected function severityFor( object $row ): string {
        $days = $this->daysUntil( (string) ( $row->session_date ?? '' ) );
        return $days <= self::URGENT_WITHIN_DAYS ? Severity::URGENT : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $name = trim( (string) ( $row->title ?? '' ) );
        if ( $name === '' ) $name = __( 'Untitled activity', 'talenttrack' );
        $days = $this->daysUntil( (string) ( $row->session_date ?? '' ) );

        if ( $days <= 0 ) {
            return sprintf(
                /* translators: %s: activity name */
                __( '%s is today and has no coach assigned.', 'talenttrack' ),
                $name
            );
        }

        return sprintf(
            /* translators: 1: activity name, 2: number of days until it takes place */
            _n(
                '%1$s is in %2$d day and has no coach assigned.',
                '%1$s is in %2$d days and has no coach assigned.',
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

        $sql = $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.team_id, a.coach_id
               FROM {$p}tt_activities a
              WHERE " . $this->baseWhere( 'a' ) . "
                AND a.session_date >= CURDATE()
                AND a.session_date <= DATE_ADD( CURDATE(), INTERVAL %d DAY )
                AND a.plan_state IN ( 'planned', 'scheduled' )
                AND ( a.coach_id IS NULL OR a.coach_id = 0 )"
            . $context->applyScope( self::SUBJECT_TYPE, 'a.id' ) . "
              ORDER BY a.session_date ASC, a.id ASC",
            self::LOOKAHEAD_DAYS
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }

    /** Days from today until a stored date, never negative. */
    private function daysUntil( string $date ): int {
        $ts = strtotime( $date );
        if ( $ts === false ) return 0;
        $diff = (int) ceil( ( $ts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
        return max( 0, $diff );
    }
}
