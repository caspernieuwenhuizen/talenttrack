<?php
namespace TT\Modules\Knowledge\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EnrolmentRepository — one person's relationship to one course.
 *
 * `course_slug` is a string with no table behind it: courses live in the
 * repository, not the database (#2642). A row whose slug no longer resolves
 * is a course that was withdrawn in a later release; it is kept and shown as
 * retired, because a coach's completion history has to outlive the course.
 *
 * Every query is club-scoped, and every person reference is `person_id` →
 * `tt_people.id`. Both are no-ops on a single-tenant install and both are
 * what stop a SaaS migration from becoming a rewrite (CLAUDE.md §4).
 */
class EnrolmentRepository {

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';

    /** @return list<string> */
    public static function statuses(): array {
        return [ self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED ];
    }

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_course_enrolments';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) {
            return null;
        }

        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id,
            CurrentClub::id()
        ) ) ?: null;
    }

    /**
     * By uuid — the identity the REST surface addresses. A sequential id
     * in a URL invites walking the range; the uuid does not.
     */
    public function findByUuid( string $uuid ): ?object {
        if ( $uuid === '' ) {
            return null;
        }

        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE uuid = %s AND club_id = %d",
            $uuid,
            CurrentClub::id()
        ) ) ?: null;
    }

    public function findFor( int $person_id, string $course_slug ): ?object {
        if ( $person_id <= 0 || $course_slug === '' ) {
            return null;
        }

        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE person_id = %d AND course_slug = %s AND club_id = %d",
            $person_id,
            $course_slug,
            CurrentClub::id()
        ) ) ?: null;
    }

    /** @return object[] */
    public function listForPerson( int $person_id ): array {
        if ( $person_id <= 0 ) {
            return [];
        }

        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE person_id = %d AND club_id = %d
              ORDER BY (due_at IS NULL), due_at ASC, id DESC",
            $person_id,
            CurrentClub::id()
        ) ) ?: [];
    }

    /** @return object[] */
    public function listForCourse( string $course_slug ): array {
        if ( $course_slug === '' ) {
            return [];
        }

        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE course_slug = %s AND club_id = %d
              ORDER BY id DESC",
            $course_slug,
            CurrentClub::id()
        ) ) ?: [];
    }

    /**
     * Enrolments past their due date and not finished.
     *
     * Read straight off the stored `status` and `due_at` rather than derived
     * from progress rows — this backs the roll-up report (#2650), which has
     * to stay fast as the academy grows.
     *
     * @return object[]
     */
    public function listOverdue(): array {
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE club_id = %d
                AND due_at IS NOT NULL AND due_at < %s
                AND status <> %s
              ORDER BY due_at ASC",
            CurrentClub::id(),
            current_time( 'mysql' ),
            self::STATUS_COMPLETED
        ) ) ?: [];
    }

    /**
     * Enrol, or return the existing enrolment untouched.
     *
     * Idempotent because both entry points need it to be: a coach clicking
     * "start" twice, and an assignment wizard re-run over a group where some
     * are already enrolled. Re-assigning must not reset someone's progress,
     * so an existing row is returned as-is rather than updated.
     */
    public function enrol( int $person_id, string $course_slug, array $data = [] ): int {
        if ( $person_id <= 0 || $course_slug === '' ) {
            return 0;
        }

        $existing = $this->findFor( $person_id, $course_slug );
        if ( $existing !== null ) {
            return (int) $existing->id;
        }

        $assigned_by = isset( $data['assigned_by'] ) ? (int) $data['assigned_by'] : 0;

        $this->wpdb->insert( $this->table, [
            'uuid'        => wp_generate_uuid4(),
            'club_id'     => CurrentClub::id(),
            'course_slug' => $course_slug,
            'person_id'   => $person_id,
            'status'      => self::STATUS_NOT_STARTED,
            'assigned_by' => $assigned_by > 0 ? $assigned_by : null,
            'assigned_at' => $assigned_by > 0 ? current_time( 'mysql' ) : null,
            'due_at'      => $this->normaliseDate( $data['due_at'] ?? null ),
        ] );

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Mark the enrolment started, once.
     *
     * Called when the first lesson is opened. Guarded on the current status
     * so reopening lesson 1 in week six does not rewrite `started_at` and
     * distort the time-to-complete figure the report shows.
     */
    public function markStarted( int $id ): void {
        $enrolment = $this->find( $id );
        if ( $enrolment === null || $enrolment->status !== self::STATUS_NOT_STARTED ) {
            return;
        }

        $this->wpdb->update(
            $this->table,
            [ 'status' => self::STATUS_IN_PROGRESS, 'started_at' => current_time( 'mysql' ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Mark complete. Idempotent: `completed_at` is the moment the last
     * requirement was met, and a later recount must not move it.
     */
    public function markCompleted( int $id ): void {
        $enrolment = $this->find( $id );
        if ( $enrolment === null || $enrolment->status === self::STATUS_COMPLETED ) {
            return;
        }

        $this->wpdb->update(
            $this->table,
            [ 'status' => self::STATUS_COMPLETED, 'completed_at' => current_time( 'mysql' ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Drop back to in-progress.
     *
     * Needed because completion is not permanent: a reviewer can reopen an
     * approved assignment, and a course revision can add a lesson to a
     * course people have already finished. Clears `completed_at` so the
     * certification bridge in #2649 does not see a completed row with no
     * completion.
     */
    public function reopen( int $id ): void {
        $enrolment = $this->find( $id );
        if ( $enrolment === null || $enrolment->status !== self::STATUS_COMPLETED ) {
            return;
        }

        $this->wpdb->update(
            $this->table,
            [ 'status' => self::STATUS_IN_PROGRESS, 'completed_at' => null ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function setDueDate( int $id, ?string $due_at ): void {
        if ( $id <= 0 ) {
            return;
        }

        $this->wpdb->update(
            $this->table,
            [ 'due_at' => $this->normaliseDate( $due_at ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Withdraw an enrolment and everything hanging off it.
     *
     * A hard delete, not an archive: withdrawing means the person should
     * never have been on the course, and leaving their half-finished
     * progress in the completion statistics would misreport the cohort.
     * Completion history is preserved by the certification the completion
     * wrote (#2649), which lives on the staff record and is not touched here.
     */
    public function withdraw( int $id ): bool {
        $enrolment = $this->find( $id );
        if ( $enrolment === null ) {
            return false;
        }

        $club = CurrentClub::id();
        foreach ( [ 'tt_course_progress', 'tt_course_quiz_attempts', 'tt_course_submissions' ] as $child ) {
            $this->wpdb->delete( $this->wpdb->prefix . $child, [ 'enrolment_id' => $id, 'club_id' => $club ] );
        }

        return (bool) $this->wpdb->delete( $this->table, [ 'id' => $id, 'club_id' => $club ] );
    }

    /**
     * Counts per status for one course, for the roll-up.
     *
     * @return array<string, int> keyed by status, every status present
     */
    public function countsByStatus( string $course_slug ): array {
        $counts = array_fill_keys( self::statuses(), 0 );

        if ( $course_slug === '' ) {
            return $counts;
        }

        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM {$this->table}
              WHERE course_slug = %s AND club_id = %d
              GROUP BY status",
            $course_slug,
            CurrentClub::id()
        ) ) ?: [];

        foreach ( $rows as $row ) {
            $counts[ (string) $row->status ] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * Accept a date in either the API's ISO shape or MySQL's, and reject
     * anything else rather than storing a zero date.
     */
    private function normaliseDate( ?string $value ): ?string {
        if ( $value === null || trim( $value ) === '' ) {
            return null;
        }

        $timestamp = strtotime( $value );

        return $timestamp === false ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
    }
}
