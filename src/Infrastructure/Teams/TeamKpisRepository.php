<?php
namespace TT\Infrastructure\Teams;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TeamKpisRepository — the at-a-glance signals on the team detail page
 * (#1613): upcoming activity count, average attendance, average squad
 * rating.
 *
 * KPI computation lives here, not in the view (CLAUDE.md §4): the
 * frontend renderer and a future SaaS REST consumer both call into the
 * same queries and get the same answers. Every query is scoped to the
 * team (which is already club-scoped) so a second tenant on the install
 * never sees another club's numbers.
 */
class TeamKpisRepository {

    /**
     * Count of planned activities for the team in the next $days days
     * (today inclusive), excluding completed / cancelled. Same
     * source-of-truth field (`activity_status_key`) the team planner and
     * the upcoming-activities table use.
     */
    public function upcomingCount( int $team_id, int $days = 14 ): int {
        if ( $team_id <= 0 ) return 0;
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = max( 1, $days );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$p}tt_activities
              WHERE team_id = %d
                AND ( archived_at IS NULL OR archived_at = '' )
                AND session_date >= CURDATE()
                AND session_date <= DATE_ADD(CURDATE(), INTERVAL %d DAY)
                AND activity_status_key NOT IN ('completed', 'cancelled')",
            $team_id, $days
        ) );
    }

    /**
     * Average attendance percentage across the team's completed
     * activities in the last $days days. Mirrors
     * ActivitiesRepository::attendanceRateForPlayer but aggregates over
     * every actual, non-guest attendance row on the team's activities.
     * Returns null when there is nothing to measure.
     */
    public function avgAttendance( int $team_id, int $days = 30 ): ?int {
        if ( $team_id <= 0 ) return null;
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = max( 1, $days );
        // #2521 — count only sessions the coach marked completed.
        $completed = \TT\Infrastructure\Query\ActivityLifecycle::completedClause( 'a' );
        $row  = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN att.status = 'present' THEN 1 ELSE 0 END) AS present_n,
                COUNT(*) AS total_n
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE a.team_id = %d
                AND att.is_guest = 0
                AND att.record_type = 'actual'
                AND a.archived_at IS NULL
                AND {$completed}
                AND a.session_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
            $team_id, $days
        ) );
        if ( ! $row || (int) $row->total_n <= 0 ) return null;
        return (int) round( ( (int) $row->present_n / (int) $row->total_n ) * 100 );
    }

    /**
     * Average rating across every rating row on live evaluations of the
     * team's active roster players. Returns null when no team player has a
     * rating yet.
     *
     * #2865 — this used to hand-roll `e.archived_at IS NULL`, which predates
     * the recycle bin (#2018). `tt_evaluations` is a registered recycle-bin
     * entity, so `trashed_at` is real and populated, and a trashed
     * evaluation was invisible in every list a coach could open while still
     * feeding this KPI. A pilot team's profile read "Selectiebeoordeling 8,3"
     * beside an evaluations list that was empty in every state, including
     * archived — because `ArchiveRepository::filterClause` hides trashed rows
     * in *every* view and this query hid them in none.
     *
     * Reading through the shared clause is the point: a number on a team
     * profile and the list a coach opens to explain it must not disagree
     * about what counts as deleted.
     */
    public function avgSquadRating( int $team_id ): ?float {
        if ( $team_id <= 0 ) return null;
        global $wpdb;
        $p          = $wpdb->prefix;
        $eval_live  = ArchiveRepository::filterClause( 'active', 'e' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT AVG(r.rating) AS avg_r, COUNT(*) AS n
               FROM {$p}tt_eval_ratings r
               JOIN {$p}tt_evaluations e ON e.id = r.evaluation_id
               JOIN {$p}tt_players pl ON pl.id = e.player_id
              WHERE pl.team_id = %d
                AND pl.archived_at IS NULL
                AND pl.club_id = %d
                AND {$eval_live}",
            $team_id, CurrentClub::id()
        ) );
        if ( ! $row || (int) $row->n <= 0 ) return null;
        return (float) $row->avg_r;
    }
}
