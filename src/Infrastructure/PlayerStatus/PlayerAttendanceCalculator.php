<?php
namespace TT\Infrastructure\PlayerStatus;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerAttendanceCalculator (#0057 Sprint 1) — derives an attendance
 * score from `tt_attendance` rows in a date window.
 *
 * No new schema; pure read-time aggregation.
 *
 * Score is `present_count / sessions_in_window * 100`. Excused absences
 * are excluded from BOTH numerator and denominator (they don't penalise
 * attendance and don't inflate the ratio either). Sparse-data signal
 * (< 3 sessions) is surfaced via `low_confidence` so the calculator
 * can downgrade weight.
 */
final class PlayerAttendanceCalculator {

    /**
     * @return array{sessions:int,present:int,absent:int,excused:int,score:?float,low_confidence:bool}
     */
    public function scoreFor( int $player_id, string $from, string $to ): array {
        global $wpdb;
        // #788 ship 1 — player status derives from real history, never
        // from planned-attendance entries. Filter to actuals on
        // completed activities so ship 2's expected rows can't shift
        // the calculator.
        //
        // #996 — `tt_attendance.status` stores the canonical lowercase
        // values per the contract in AttendanceStatus. The pre-fix
        // TitleCase literals here silently missed every row.
        //
        // #1382 — PLAYER-level load includes guest appearances. A player
        // guesting for another team has an attendance row on an activity
        // owned by that other team, recorded either as `player_id =
        // <id>` (is_guest = 1) or `guest_player_id = <id>` (player_id
        // NULL). Match both forms and drop the `is_guest = 0` filter so a
        // played-up player's load reflects everything they actually did.
        // Team-level statistics keep their `is_guest = 0` filter elsewhere
        // (TeamKpisRepository, AttendanceRankingQuery) — this inclusivity
        // is player-scoped only.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS sessions,
                SUM(CASE WHEN att.status = %s THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN att.status = %s THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN att.status = %s THEN 1 ELSE 0 END) AS excused
              FROM {$wpdb->prefix}tt_attendance att
              JOIN {$wpdb->prefix}tt_activities act
                ON act.id = att.activity_id AND act.club_id = att.club_id
             WHERE ( att.player_id = %d OR att.guest_player_id = %d )
               AND att.club_id = %d
               AND att.record_type = 'actual'
               AND act.plan_state = 'completed'
               AND act.session_date >= %s
               AND act.session_date <= %s",
            AttendanceStatus::PRESENT,
            AttendanceStatus::ABSENT,
            AttendanceStatus::EXCUSED,
            $player_id, $player_id, CurrentClub::id(), $from, $to
        ), ARRAY_A );

        $sessions = (int) ( $row['sessions'] ?? 0 );
        $present  = (int) ( $row['present']  ?? 0 );
        $absent   = (int) ( $row['absent']   ?? 0 );
        $excused  = (int) ( $row['excused']  ?? 0 );

        $countable = $sessions - $excused;
        $score     = $countable > 0 ? round( ( $present / $countable ) * 100, 1 ) : null;

        return [
            'sessions'       => $sessions,
            'present'        => $present,
            'absent'         => $absent,
            'excused'        => $excused,
            'score'          => $score,
            'low_confidence' => $countable < 3,
        ];
    }
}
