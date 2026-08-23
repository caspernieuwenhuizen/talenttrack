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
     * Who is assigned to this team, and whether they finished the course.
     *
     * One query. The alternative — list the staff, then ask per person — is
     * how a squad overview becomes slow exactly on the biggest squads.
     *
     * @return list<array{person_id: int, name: string, status: string, completed_at: ?string}>
     */
    public static function forTeam( int $team_id, string $course_slug ): array {
        if ( $team_id <= 0 || $course_slug === '' ) return [];

        global $wpdb;
        $p     = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );

        // LEFT JOIN, not INNER: a coach who never started the course is the
        // answer to this question, not a row to leave out.
        $sql = $wpdb->prepare(
            "SELECT pe.id AS person_id, pe.first_name, pe.last_name,
                    e.status, e.completed_at
               FROM {$p}tt_user_role_scopes s
         INNER JOIN {$p}tt_people pe
                 ON pe.id = s.person_id AND pe.club_id = %d
          LEFT JOIN {$p}tt_course_enrolments e
                 ON e.person_id = pe.id
                AND e.course_slug = %s
                AND e.club_id = %d
              WHERE s.scope_type = 'team'
                AND s.scope_id = %d
                AND ( s.start_date IS NULL OR s.start_date <= %s )
                AND ( s.end_date   IS NULL OR s.end_date   >= %s )
                AND pe.archived_at IS NULL
                AND pe.trashed_at IS NULL
           GROUP BY pe.id, pe.first_name, pe.last_name, e.status, e.completed_at
              ORDER BY pe.last_name ASC, pe.first_name ASC",
            CurrentClub::id(),
            $course_slug,
            CurrentClub::id(),
            $team_id,
            $today,
            $today
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );

            $out[] = [
                'person_id'    => (int) $row->person_id,
                'name'         => $name !== '' ? $name : __( 'A staff member', 'talenttrack' ),
                // A person with no enrolment row has not started, which is a
                // different answer from "in progress" and worth saying so.
                'status'       => (string) ( $row->status ?? EnrolmentRepository::STATUS_NOT_STARTED ),
                'completed_at' => $row->completed_at ?? null,
            ];
        }

        return $out;
    }

    /**
     * The one-line summary: how many of this team's staff have finished.
     *
     * @return array{done: int, total: int}
     */
    public static function summaryFor( int $team_id, string $course_slug ): array {
        $rows = self::forTeam( $team_id, $course_slug );

        $done = 0;
        foreach ( $rows as $row ) {
            if ( $row['status'] === EnrolmentRepository::STATUS_COMPLETED ) $done++;
        }

        return [ 'done' => $done, 'total' => count( $rows ) ];
    }
}
