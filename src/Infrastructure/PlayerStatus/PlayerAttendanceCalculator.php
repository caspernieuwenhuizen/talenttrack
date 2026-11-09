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
        return $this->scoresFor( [ $player_id ], $from, $to )[ $player_id ] ?? self::score( new \stdClass() );
    }

    /**
     * #4945 — `scoreFor()` for many players, the counts read in one
     * statement grouped by player. Every requested player is present; one
     * without rows scores as no data.
     *
     * @param list<int> $player_ids
     * @return array<int, array{sessions:int,present:int,late:int,absent:int,excused:int,injured:int,suspended:int,attended:int,score:?float,low_confidence:bool}>
     */
    public function scoresFor( array $player_ids, string $from, string $to ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) return [];

        global $wpdb;
        // #2521 — "completed" is the coach-set status, not `plan_state`
        // (which defaults to 'completed' on every non-planner row).
        $completed = ActivityLifecycle::completedClause( 'act' );
        $counts    = AttendanceFlagService::statusCountsSql( 'att.status' );
        $in        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // #788 ship 1 — player status derives from real history, never
        // from planned-attendance entries: actuals on completed activities
        // only, so expected rows cannot shift the calculator.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                att.player_id AS tt_player_id,
                COUNT(*) AS sessions,
                {$counts}
              FROM {$wpdb->prefix}tt_attendance att
              JOIN {$wpdb->prefix}tt_activities act
                ON act.id = att.activity_id AND act.club_id = att.club_id
             WHERE att.player_id IN ({$in})
               AND att.is_guest = 0
               AND att.club_id = %d
               AND att.record_type = 'actual'
               AND {$completed}
               AND act.session_date >= %s
               AND act.session_date <= %s
             GROUP BY att.player_id",
            ...array_merge( $ids, [ CurrentClub::id(), $from, $to ] )
        ) );

        $by_player = [];
        foreach ( is_array( $rows ) ? $rows : [] as $r ) {
            if ( is_object( $r ) ) $by_player[ (int) ( $r->tt_player_id ?? 0 ) ] = $r;
        }
        $out = [];
        foreach ( $ids as $id ) $out[ $id ] = self::score( $by_player[ $id ] ?? new \stdClass() );
        return $out;
    }

    /**
     * The score from one player's counts.
     *
     * @return array{sessions:int,present:int,late:int,absent:int,excused:int,injured:int,suspended:int,attended:int,score:?float,low_confidence:bool}
     */
    private static function score( object $row ): array {
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
