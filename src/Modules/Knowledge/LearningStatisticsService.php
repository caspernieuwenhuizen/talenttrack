<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\SubmissionRepository;

/**
 * LearningStatisticsService (#2650, epic #2641) — the roll-up a head of
 * development opens the module for.
 *
 * All aggregation lives here rather than in the report view, so the REST
 * endpoints and the rendered tables cannot disagree about what "complete"
 * means (CLAUDE.md §4). Delete every view file and the numbers still answer.
 *
 * ## One query per question, never one per row
 *
 * Each method below is a single grouped statement. The tempting shape — list
 * the courses, then loop asking for each one's counts — is how a page that is
 * fine with one course becomes unusable with twelve, and this is a report:
 * the whole point is that it covers everything at once.
 *
 * ## Where learners stall is the interesting number
 *
 * `dropOffFor()` finds the lesson with the largest fall in readers compared to
 * the one before it. That is a fact about the *course*, not about the coaches:
 * a lesson that half the cohort stops at is usually badly written, badly
 * placed, or asking for something the reader cannot do yet. Completion
 * percentages alone never surface it, which is why it is here and on the
 * report rather than left for somebody to notice.
 */
final class LearningStatisticsService {

    /**
     * Enrolment counts and timings for one course.
     *
     * @return array{
     *   course_slug: string, enrolled: int, not_started: int, in_progress: int,
     *   completed: int, overdue: int, median_days_to_complete: ?int
     * }
     */
    public function forCourse( string $course_slug ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS enrolled,
                SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS not_started,
                SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS in_progress,
                SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS completed,
                SUM(
                    CASE WHEN status <> %s
                          AND due_at IS NOT NULL
                          AND due_at < NOW()
                         THEN 1 ELSE 0 END
                ) AS overdue
               FROM {$p}tt_course_enrolments
              WHERE club_id = %d AND course_slug = %s",
            EnrolmentRepository::STATUS_NOT_STARTED,
            EnrolmentRepository::STATUS_IN_PROGRESS,
            EnrolmentRepository::STATUS_COMPLETED,
            EnrolmentRepository::STATUS_COMPLETED,
            CurrentClub::id(),
            $course_slug
        ) );

        return [
            'course_slug'             => $course_slug,
            'enrolled'                => (int) ( $row->enrolled ?? 0 ),
            'not_started'             => (int) ( $row->not_started ?? 0 ),
            'in_progress'             => (int) ( $row->in_progress ?? 0 ),
            'completed'               => (int) ( $row->completed ?? 0 ),
            'overdue'                 => (int) ( $row->overdue ?? 0 ),
            'median_days_to_complete' => $this->medianDaysFor( $course_slug ),
        ];
    }

    /**
     * Every course this install ships, with its counts.
     *
     * @return list<array<string, mixed>>
     */
    public function forAllCourses(): array {
        $out = [];

        foreach ( CourseRegistry::all() as $slug => $manifest ) {
            $stats          = $this->forCourse( (string) $slug );
            $stats['title'] = $manifest->title();
            $out[]          = $stats;
        }

        return $out;
    }

    /**
     * Median days from start to completion.
     *
     * Median, not mean: one coach who finished a twelve-week course in three
     * days because they had already done it elsewhere would drag an average
     * somewhere useless. Null when nobody has finished — which is a different
     * statement from zero and the report says so.
     */
    private function medianDaysFor( string $course_slug ): ?int {
        global $wpdb;
        $p = $wpdb->prefix;

        $days = $wpdb->get_col( $wpdb->prepare(
            "SELECT DATEDIFF( completed_at, COALESCE( started_at, created_at ) )
               FROM {$p}tt_course_enrolments
              WHERE club_id = %d
                AND course_slug = %s
                AND status = %s
                AND completed_at IS NOT NULL
              ORDER BY 1 ASC",
            CurrentClub::id(),
            $course_slug,
            EnrolmentRepository::STATUS_COMPLETED
        ) );

        $days = array_values( array_map( 'intval', array_filter(
            is_array( $days ) ? $days : [],
            static function ( $d ): bool { return $d !== null && $d !== ''; }
        ) ) );

        $count = count( $days );
        if ( $count === 0 ) return null;

        $middle = (int) floor( ( $count - 1 ) / 2 );

        return $count % 2 === 1
            ? $days[ $middle ]
            : (int) round( ( $days[ $middle ] + $days[ $middle + 1 ] ) / 2 );
    }

    /**
     * How many readers reached each lesson, and where the biggest fall is.
     *
     * @return array{
     *   lessons: list<array{slug: string, title: string, readers: int, drop: int}>,
     *   stalls_at: ?array{slug: string, title: string, drop: int}
     * }
     */
    public function dropOffFor( string $course_slug ): array {
        $manifest = CourseRegistry::get( $course_slug );
        if ( $manifest === null ) {
            return [ 'lessons' => [], 'stalls_at' => null ];
        }

        global $wpdb;
        $p = $wpdb->prefix;

        // One grouped query for the whole course, then matched to the
        // manifest order in PHP — the manifest decides the sequence, and a
        // lesson nobody has opened yet has no row to sort by.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pr.lesson_slug, COUNT(*) AS readers
               FROM {$p}tt_course_progress pr
         INNER JOIN {$p}tt_course_enrolments e
                 ON e.id = pr.enrolment_id AND e.club_id = pr.club_id
              WHERE pr.club_id = %d
                AND e.course_slug = %s
                AND pr.read_at IS NOT NULL
           GROUP BY pr.lesson_slug",
            CurrentClub::id(),
            $course_slug
        ) );

        $readers = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $readers[ (string) $row->lesson_slug ] = (int) $row->readers;
        }

        $lessons   = [];
        $previous  = null;
        $worst     = null;

        foreach ( $manifest->lessonSlugs() as $slug ) {
            $lesson = CourseRegistry::lesson( $course_slug, $slug );
            $count  = $readers[ $slug ] ?? 0;

            // The first lesson has nothing to fall from.
            $drop = $previous === null ? 0 : max( 0, $previous - $count );

            $entry = [
                'slug'    => $slug,
                'title'   => $lesson !== null ? $lesson->title() : $slug,
                'readers' => $count,
                'drop'    => $drop,
            ];

            $lessons[] = $entry;

            if ( $drop > 0 && ( $worst === null || $drop > $worst['drop'] ) ) {
                $worst = [ 'slug' => $slug, 'title' => $entry['title'], 'drop' => $drop ];
            }

            $previous = $count;
        }

        return [ 'lessons' => $lessons, 'stalls_at' => $worst ];
    }

    /**
     * One person's learning record.
     *
     * @return array{
     *   person_id: int, assigned: int, completed: int, overdue: int,
     *   percent: int, last_activity: ?string, awaiting_review: int
     * }
     */
    public function forPerson( int $person_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS assigned,
                SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS completed,
                SUM(
                    CASE WHEN status <> %s AND due_at IS NOT NULL AND due_at < NOW()
                         THEN 1 ELSE 0 END
                ) AS overdue
               FROM {$p}tt_course_enrolments
              WHERE club_id = %d AND person_id = %d",
            EnrolmentRepository::STATUS_COMPLETED,
            EnrolmentRepository::STATUS_COMPLETED,
            CurrentClub::id(),
            $person_id
        ) );

        $assigned  = (int) ( $row->assigned ?? 0 );
        $completed = (int) ( $row->completed ?? 0 );

        return [
            'person_id'       => $person_id,
            'assigned'        => $assigned,
            'completed'       => $completed,
            'overdue'         => (int) ( $row->overdue ?? 0 ),
            'percent'         => $assigned > 0 ? (int) round( $completed / $assigned * 100 ) : 0,
            'last_activity'   => $this->lastActivityFor( $person_id ),
            'awaiting_review' => $this->awaitingReviewFor( $person_id ),
        ];
    }

    /**
     * Everyone with a learning record, for the per-person table.
     *
     * @return list<array<string, mixed>>
     */
    public function forEveryone(): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT e.person_id, pe.first_name, pe.last_name
               FROM {$p}tt_course_enrolments e
         INNER JOIN {$p}tt_people pe ON pe.id = e.person_id AND pe.club_id = e.club_id
              WHERE e.club_id = %d
                AND pe.archived_at IS NULL
           ORDER BY pe.last_name ASC, pe.first_name ASC",
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $stats = $this->forPerson( (int) $row->person_id );
            $name  = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );

            $stats['name'] = $name !== '' ? $name : __( 'A staff member', 'talenttrack' );
            $out[]         = $stats;
        }

        return $out;
    }

    /** The most recent thing this person did on any course. */
    private function lastActivityFor( int $person_id ): ?string {
        global $wpdb;
        $p = $wpdb->prefix;

        $at = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX( pr.updated_at )
               FROM {$p}tt_course_progress pr
         INNER JOIN {$p}tt_course_enrolments e
                 ON e.id = pr.enrolment_id AND e.club_id = pr.club_id
              WHERE pr.club_id = %d AND e.person_id = %d",
            CurrentClub::id(),
            $person_id
        ) );

        return $at !== null && $at !== '' ? (string) $at : null;
    }

    /** Their submissions still sitting with a reviewer. */
    private function awaitingReviewFor( int $person_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$p}tt_course_submissions s
         INNER JOIN {$p}tt_course_enrolments e
                 ON e.id = s.enrolment_id AND e.club_id = s.club_id
              WHERE s.club_id = %d
                AND e.person_id = %d
                AND s.outcome = %s
                AND s.submitted_at IS NOT NULL",
            CurrentClub::id(),
            $person_id,
            SubmissionRepository::OUTCOME_PENDING
        ) );
    }

    /**
     * Per-team coverage for one course, for every team that has staff.
     *
     * Built on `TeamCourseCoverage`, which owns the staff-to-team join, so
     * the report and any other consumer agree about who counts as "the staff
     * around this squad".
     *
     * @return list<array{team_id: int, team_name: string, done: int, total: int}>
     */
    public function forTeams( string $course_slug ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_teams
              WHERE club_id = %d AND archived_at IS NULL
           ORDER BY name ASC",
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( is_array( $teams ) ? $teams : [] as $team ) {
            $summary = TeamCourseCoverage::summaryFor( (int) $team->id, $course_slug );

            // A team with no staff assigned answers nothing; listing it as
            // "0 of 0" reads as a failure when it is an absence of data.
            if ( $summary['total'] === 0 ) continue;

            $out[] = [
                'team_id'   => (int) $team->id,
                'team_name' => (string) $team->name,
                'done'      => $summary['done'],
                'total'     => $summary['total'],
            ];
        }

        return $out;
    }
}
