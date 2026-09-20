<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;

/**
 * TeamCourseCoverage (#2649, epic #2641) — has the staff around this squad
 * done the course?
 *
 * The player-facing half of the knowledge library. A course completion on one
 * coach's record is a fact about that coach; the question a head of
 * development actually asks is about a group of players: *are the people
 * running training for this age group trained in what we expect them to
 * know?*
 *
 * ## Why this joins on team assignment and not on methodology
 *
 * The epic proposed binding a course to `methodology_principles` so a team's
 * methodology surface could make this join. Building it showed why that does
 * not work. `tt_principles` holds tactical game principles keyed `AO-01`, and
 * `tt_methodologies` holds *playing* methodologies — formations, principles,
 * set pieces. The shipped course teaches physical periodisation, which is a
 * training methodology and belongs to neither. Binding it to `jo14-1-hedel`
 * would assert a relationship that is not there.
 *
 * What is actually needed is simpler and already in the schema: staff are
 * assigned to teams through `tt_user_role_scopes`, and completions are on
 * `tt_course_enrolments`. Joining those two answers the question directly,
 * for any course, without a vocabulary that has to be kept in step.
 *
 * The manifest's `methodology_principles` stay where they are — a description
 * of what the course covers, read from the corpus when something wants to
 * show it. They are not copied into the database, because the corpus is
 * versioned with the plugin and a copy would only go stale.
 */
final class TeamCourseCoverage {

    /**
     * This team's staff who are on the course, and how they are getting on.
     *
     * One query. The alternative — list the staff, then ask per person — is
     * how a squad overview becomes slow exactly on the biggest squads.
     *
     * ## Enrolled staff only (#3769)
     *
     * This used to LEFT JOIN the enrolments and report anybody without a
     * row as `not_started`, on the reasoning that a coach who never started
     * is part of the answer. It is — but it is a *different* answer, and
     * saying it in the enrolment vocabulary made the two indistinguishable:
     * "nobody ever asked this coach to do the course" read exactly like "we
     * asked and they have not begun", and an admin could only tell them
     * apart by trying to enrol the person and watching for a new row.
     *
     * So the list is enrolment-backed, and the question it answers is "how
     * is this team getting on with the course". *Who still needs assigning*
     * is the assignment wizard's question, because the wizard is the
     * surface that can act on the answer. `summaryFor()` keeps an
     * `assigned` count purely so a caller can tell an empty list apart from
     * a team with no staff at all.
     *
     * @return list<array{person_id: int, name: string, status: string, completed_at: ?string, due_at: ?string, is_overdue: bool}>
     */
    public static function forTeam( int $team_id, string $course_slug ): array {
        if ( $team_id <= 0 || $course_slug === '' ) return [];

        global $wpdb;
        $p     = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );

        // The scope predicate below is the same population
        // `LearningStatisticsService::countsFor()` counts when it is given a
        // team. Change one and change the other, or the list and its own
        // summary start disagreeing.
        $sql = $wpdb->prepare(
            "SELECT pe.id AS person_id, pe.first_name, pe.last_name,
                    e.status, e.completed_at, e.due_at
               FROM {$p}tt_course_enrolments e
         INNER JOIN {$p}tt_people pe
                 ON pe.id = e.person_id AND pe.club_id = e.club_id
         INNER JOIN {$p}tt_user_role_scopes s
                 ON s.person_id = pe.id
              WHERE e.club_id = %d
                AND e.course_slug = %s
                AND s.scope_type = 'team'
                AND s.scope_id = %d
                AND ( s.start_date IS NULL OR s.start_date <= %s )
                AND ( s.end_date   IS NULL OR s.end_date   >= %s )
                AND pe.archived_at IS NULL
                AND pe.trashed_at IS NULL
           GROUP BY pe.id, pe.first_name, pe.last_name, e.status, e.completed_at, e.due_at
              ORDER BY pe.last_name ASC, pe.first_name ASC",
            CurrentClub::id(),
            $course_slug,
            $team_id,
            $today,
            $today
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $name   = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
            $status = (string) $row->status;
            $due_at = $row->due_at !== null ? (string) $row->due_at : null;

            $out[] = [
                'person_id'    => (int) $row->person_id,
                'name'         => $name !== '' ? $name : __( 'A staff member', 'talenttrack' ),
                'status'       => $status,
                'completed_at' => $row->completed_at ?? null,
                'due_at'       => $due_at,
                // Derived here rather than in a view, so the REST consumer
                // and the rendered table agree (CLAUDE.md §4), and from the
                // same rule the roll-up's overdue count uses.
                'is_overdue'   => EnrolmentRepository::isOverdue( $due_at, $status ),
            ];
        }

        return $out;
    }

    /**
     * The one-line summary: how many of this team's staff have finished.
     *
     * `done` and `total` come from `LearningStatisticsService::countsFor()`,
     * the same query the course roll-up reads (#3769). Before that the two
     * counted different populations — enrolment rows club-wide against
     * team-assigned people — and both appeared in one response, where they
     * read as a bug.
     *
     * `assigned` is the third number, and the only one not about
     * enrolments: how many active staff the team has. It exists so a caller
     * can tell "this team has nobody on the course yet" from "this team has
     * no staff", which are the same empty list and very different problems.
     *
     * @return array{done: int, total: int, assigned: int}
     */
    public static function summaryFor( int $team_id, string $course_slug ): array {
        if ( $team_id <= 0 || $course_slug === '' ) {
            return [ 'done' => 0, 'total' => 0, 'assigned' => 0 ];
        }

        $counts = ( new LearningStatisticsService() )->countsFor( $course_slug, $team_id );

        return [
            'done'     => $counts['completed'],
            'total'    => $counts['enrolled'],
            'assigned' => self::assignedCount( $team_id ),
        ];
    }

    /** Active staff scoped to this team, however many courses they are on. */
    public static function assignedCount( int $team_id ): int {
        if ( $team_id <= 0 ) return 0;

        global $wpdb;
        $p     = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT( DISTINCT pe.id )
               FROM {$p}tt_user_role_scopes s
         INNER JOIN {$p}tt_people pe
                 ON pe.id = s.person_id AND pe.club_id = %d
              WHERE s.scope_type = 'team'
                AND s.scope_id = %d
                AND ( s.start_date IS NULL OR s.start_date <= %s )
                AND ( s.end_date   IS NULL OR s.end_date   >= %s )
                AND pe.archived_at IS NULL
                AND pe.trashed_at IS NULL",
            CurrentClub::id(),
            $team_id,
            $today,
            $today
        ) );
    }
}
