<?php
namespace TT\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SquadSizeEstimator (#2497) — how many players to plan for.
 *
 * The generator has to size its exercises: a 7v5 needs twelve outfield
 * players, and proposing it to a squad of eight wastes the coach's
 * evening. So it needs a number before it picks anything.
 *
 * Epic decision D14: **derive it from recent attendance, and let the
 * coach confirm it.** The roster count is consistently too high — a
 * sixteen-player squad rarely puts sixteen on the pitch — and a bare
 * average is silently wrong the week half the squad is on a school trip.
 * The coach always knows something the data does not, so the wizard
 * prefills this and lets them change it.
 *
 * Nothing here writes; it is a read used to prefill one field and to
 * pick the roster the coverage scoring runs against.
 */
final class SquadSizeEstimator {

    /**
     * Attendance statuses that mean the player was actually there.
     *
     * Uses the typed vocabulary rather than literals (#988). Stored values
     * are mixed-case on real installs ("Present"), and the column's
     * collation is case-insensitive, so these match either way.
     */
    private const PRESENT_LIKE = [ AttendanceStatus::PRESENT, AttendanceStatus::LATE ];

    /**
     * Average turnout at this team's recent completed trainings, rounded
     * to the nearest whole player.
     *
     * Returns null when there is nothing to average — a new team, or one
     * whose trainings have never had attendance recorded. The caller
     * falls back to the roster count and the wizard says which it used,
     * rather than presenting a guess as data.
     */
    public function averageTurnout( int $team_id, int $sessions = 6 ): ?int {
        if ( $team_id <= 0 ) return null;

        global $wpdb;
        $sessions = max( 1, min( 20, $sessions ) );

        $placeholders = implode( ',', array_fill( 0, count( self::PRESENT_LIKE ), '%s' ) );

        // Per-activity present counts over the trailing N completed
        // trainings, then averaged. Done in one statement so a team with
        // a long history does not pull rows into PHP to count them.
        $sql = "SELECT AVG(present_count) FROM (
                    SELECT COUNT(*) AS present_count
                      FROM {$wpdb->prefix}tt_attendance att
                      JOIN {$wpdb->prefix}tt_activities a
                        ON a.id = att.activity_id
                     WHERE a.team_id = %d
                       AND a.club_id = %d
                       AND a.activity_type_key = %s
                       AND a.activity_status_key = 'completed'
                       AND a.archived_at IS NULL
                       AND att.status IN ({$placeholders})
                  GROUP BY att.activity_id
                  ORDER BY a.session_date DESC
                     LIMIT %d
                ) recent";

        $params = array_merge(
            [ $team_id, CurrentClub::id(), ActivityTypeKey::TRAINING ],
            self::PRESENT_LIKE,
            [ $sessions ]
        );

        $average = $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ( $average === null || $average === '' ) return null;

        $rounded = (int) round( (float) $average );
        return $rounded > 0 ? $rounded : null;
    }

    /**
     * The team's active roster — the fallback when there is no attendance
     * history, and the player set coverage scoring runs against when the
     * caller does not supply one.
     *
     * @return list<int>
     */
    public function rosterFor( int $team_id ): array {
        if ( $team_id <= 0 ) return [];

        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
              WHERE team_id = %d
                AND ( club_id = %d OR club_id IS NULL )
                AND ( status IS NULL OR status = 'active' )
                AND archived_at IS NULL
              ORDER BY id ASC",
            $team_id,
            CurrentClub::id()
        ) );

        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }

    /**
     * The number the wizard prefills, with where it came from so the
     * field can say so rather than presenting a guess as a measurement.
     *
     * @return array{value:int, source:string}
     */
    public function suggest( int $team_id ): array {
        $average = $this->averageTurnout( $team_id );
        if ( $average !== null ) {
            return [ 'value' => $average, 'source' => 'attendance' ];
        }

        $roster = count( $this->rosterFor( $team_id ) );
        return [ 'value' => $roster, 'source' => $roster > 0 ? 'roster' : 'none' ];
    }
}
