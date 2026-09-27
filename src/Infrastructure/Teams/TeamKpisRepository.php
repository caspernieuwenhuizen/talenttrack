<?php
namespace TT\Infrastructure\Teams;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\AttendanceFlagService;

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
                AND " . ArchiveRepository::filterClause( 'active' ) . "
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
        // #4041 — attended is the one rule (present + late), from the service.
        $attended  = AttendanceFlagService::attendedSumSql( 'att.status' );
        $row  = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                {$attended} AS present_n,
                COUNT(*) AS total_n
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE a.team_id = %d
                AND att.is_guest = 0
                AND att.record_type = 'actual'
                AND " . ArchiveRepository::filterClause( 'active', 'a' ) . "
                AND {$completed}
                AND a.session_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
            $team_id, $days
        ) );
        if ( ! $row || (int) $row->total_n <= 0 ) return null;
        return (int) round( ( (int) $row->present_n / (int) $row->total_n ) * 100 );
    }

    /**
     * #3458 — `avgSquadRating()` restricted to evaluations dated inside a
     * window, for the team monthly report's squad-rating KPI. The squad is the
     * team's live roster, exactly as above. Null when nobody was rated in the
     * window.
     *
     * #4134 — `$type_ids` narrows it to those evaluation types, for the
     * monthly report's Evaluations section, whose squad average is this
     * method's answer so the section and the tile cannot disagree. Empty
     * means every type.
     *
     * @param list<int> $type_ids
     */
    public function avgSquadRatingBetween( int $team_id, string $from, string $to, array $type_ids = [] ): ?float {
        if ( $team_id <= 0 ) return null;
        global $wpdb;
        $p           = $wpdb->prefix;
        $eval_live   = ArchiveRepository::filterClause( 'active', 'e' );
        $player_live = ArchiveRepository::filterClause( 'active', 'pl' );
        $type_ids    = array_values( array_filter( array_map( 'intval', $type_ids ), static fn( int $id ): bool => $id > 0 ) );
        $types       = $type_ids !== [] ? ' AND e.eval_type_id IN (' . implode( ',', array_fill( 0, count( $type_ids ), '%d' ) ) . ')' : '';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT AVG(r.rating) AS avg_r, COUNT(*) AS n
               FROM {$p}tt_eval_ratings r
               JOIN {$p}tt_evaluations e ON e.id = r.evaluation_id
               JOIN {$p}tt_players pl ON pl.id = e.player_id
              WHERE pl.team_id = %d
                AND {$player_live}
                AND pl.club_id = %d
                AND {$eval_live}
                AND e.eval_date BETWEEN %s AND %s{$types}",
            ...array_merge( [ $team_id, CurrentClub::id(), $from, $to ], $type_ids )
        ) );
        if ( ! $row || (int) $row->n <= 0 ) return null;
        return (float) $row->avg_r;
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
        // #2906 — the player side needed the same treatment. #2865 routed the
        // evaluations through filterClause but left `pl.archived_at IS NULL`
        // raw, so a trashed player's ratings still counted toward the squad
        // average while their row was gone from every list. Same bug as #2865,
        // one JOIN along.
        $player_live = ArchiveRepository::filterClause( 'active', 'pl' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT AVG(r.rating) AS avg_r, COUNT(*) AS n
               FROM {$p}tt_eval_ratings r
               JOIN {$p}tt_evaluations e ON e.id = r.evaluation_id
               JOIN {$p}tt_players pl ON pl.id = e.player_id
              WHERE pl.team_id = %d
                AND {$player_live}
                AND pl.club_id = %d
                AND {$eval_live}",
            $team_id, CurrentClub::id()
        ) );
        if ( ! $row || (int) $row->n <= 0 ) return null;
        return (float) $row->avg_r;
    }
}
