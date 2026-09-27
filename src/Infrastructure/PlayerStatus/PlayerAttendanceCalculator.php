<?php
namespace TT\Infrastructure\PlayerStatus;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\AttendanceFlagService;

/**
 * PlayerAttendanceCalculator (#0057 Sprint 1) — derives an attendance
 * score from `tt_attendance` rows in a date window, for the player status
 * traffic light and the cohort board.
 *
 * No new schema; pure read-time aggregation.
 *
 * #4041 — the score is AttendanceFlagService's one named exception,
 * {@see AttendanceFlagService::presentPctForStatus()}: attended (present +
 * late) over the activities that remain once excused, injured and suspended ones are
 * set aside, so a player is not marked down for being injured or for an
 * absence the club excused. Every other surface divides by the full total.
 *
 * Guest appearances are left out (`is_guest = 0`), as on every attendance
 * surface. They used to count here (#1382), which let a guest training with
 * another team raise a player's own status; they stay visible on the
 * activity and in the player's journey.
 *
 * Sparse-data signal (< 3 countable activities) is surfaced via
 * `low_confidence` so the status calculator can downgrade weight.
 */
final class PlayerAttendanceCalculator {

    /**
     * @return array{sessions:int,present:int,late:int,absent:int,excused:int,injured:int,suspended:int,attended:int,score:?float,low_confidence:bool}
     */
    public function scoreFor( int $player_id, string $from, string $to ): array {
        global $wpdb;
        // #2521 — "completed" is the coach-set status, not `plan_state`
        // (which defaults to 'completed' on every non-planner row).
        $completed = ActivityLifecycle::completedClause( 'act' );
        $counts    = AttendanceFlagService::statusCountsSql( 'att.status' );
        // #788 ship 1 — player status derives from real history, never
        // from planned-attendance entries: actuals on completed activities
        // only, so expected rows cannot shift the calculator.
        $raw = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS sessions,
                {$counts}
              FROM {$wpdb->prefix}tt_attendance att
              JOIN {$wpdb->prefix}tt_activities act
                ON act.id = att.activity_id AND act.club_id = att.club_id
             WHERE att.player_id = %d
               AND att.is_guest = 0
               AND att.club_id = %d
               AND att.record_type = 'actual'
               AND {$completed}
               AND act.session_date >= %s
               AND act.session_date <= %s",
            $player_id, CurrentClub::id(), $from, $to
        ) );

        $row      = is_object( $raw ) ? $raw : new \stdClass();
        $sessions = (int) ( $row->sessions ?? 0 );
        $excused  = (int) ( $row->excused ?? 0 );
        $injured   = (int) ( $row->injured ?? 0 );
        $suspended = (int) ( $row->suspended ?? 0 );
        $attended  = AttendanceFlagService::attended( $row );

        $countable = max( 0, $sessions - $excused - $injured - $suspended );
        $score     = AttendanceFlagService::presentPctForStatus( $attended, $sessions, $excused, $injured, $suspended );

        return [
            'sessions'       => $sessions,
            'present'        => (int) ( $row->present ?? 0 ),
            'late'           => (int) ( $row->late ?? 0 ),
            'absent'         => (int) ( $row->absent ?? 0 ),
            'excused'        => $excused,
            'injured'        => $injured,
            'suspended'      => $suspended,
            'attended'       => $attended,
            'score'          => $score,
            'low_confidence' => $countable < 3,
        ];
    }
}
