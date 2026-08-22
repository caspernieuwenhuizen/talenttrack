<?php
namespace TT\Modules\Knowledge\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ProgressRepository — one row per lesson per enrolment.
 *
 * Three timestamps and a JSON blob. The timestamps are the three things a
 * lesson can require — read it, pass its quiz, get its assignment approved
 * — and `CourseCompletionService` reads all three against what the lesson's
 * front matter declares. A lesson that requires nothing but reading is
 * complete on `read_at` alone.
 *
 * `tool_state` is where the stateful blocks from #2643 persist. A coach who
 * measured their squad's zero point in lesson 4 finds it still there in
 * lesson 11, where the final assignment asks for it.
 */
class ProgressRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_course_progress';
    }

    /**
     * Every progress row for an enrolment, keyed by lesson slug.
     *
     * Keyed rather than a list because every caller wants a lookup: the
     * reader draws a lesson list, the gate asks about one lesson, the
     * completion service walks the manifest. One query, one shape.
     *
     * @return array<string, object>
     */
    public function forEnrolment( int $enrolment_id ): array {
        if ( $enrolment_id <= 0 ) {
            return [];
        }

        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE enrolment_id = %d AND club_id = %d",
            $enrolment_id,
            CurrentClub::id()
        ) ) ?: [];

        $keyed = [];
        foreach ( $rows as $row ) {
            $keyed[ (string) $row->lesson_slug ] = $row;
        }

        return $keyed;
    }

    public function find( int $enrolment_id, string $lesson_slug ): ?object {
        if ( $enrolment_id <= 0 || $lesson_slug === '' ) {
            return null;
        }

        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE enrolment_id = %d AND lesson_slug = %s AND club_id = %d",
            $enrolment_id,
            $lesson_slug,
            CurrentClub::id()
        ) ) ?: null;
    }

    /**
     * Mark a lesson read. Idempotent — `read_at` is when they first said
     * they had read it, and clicking again does not move it.
     */
    public function markRead( int $enrolment_id, string $lesson_slug ): void {
        $existing = $this->ensureRow( $enrolment_id, $lesson_slug );
        if ( $existing === null || $existing->read_at !== null ) {
            return;
        }

        $this->wpdb->update(
            $this->table,
            [ 'read_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ]
        );
    }

    /** Record a passed quiz. First pass wins, for the same reason. */
    public function markQuizPassed( int $enrolment_id, string $lesson_slug ): void {
        $existing = $this->ensureRow( $enrolment_id, $lesson_slug );
        if ( $existing === null || $existing->quiz_passed_at !== null ) {
            return;
        }

        $this->wpdb->update(
            $this->table,
            [ 'quiz_passed_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Record an approved assignment, or clear it.
     *
     * Clearing is the case that matters: a reviewer who reopens a previously
     * approved submission has to be able to take the approval back, or the
     * lesson stays complete on the strength of a verdict that no longer
     * stands.
     */
    public function setAssignmentApproved( int $enrolment_id, string $lesson_slug, bool $approved ): void {
        $existing = $this->ensureRow( $enrolment_id, $lesson_slug );
        if ( $existing === null ) {
            return;
        }

        $this->wpdb->update(
            $this->table,
            [ 'assignment_approved_at' => $approved ? current_time( 'mysql' ) : null ],
            [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Persist a lesson's interactive-block state.
     *
     * Merged rather than replaced: a lesson can hold more than one stateful
     * block, and each writes independently as the reader touches it.
     *
     * @param array<string, mixed> $state
     */
    public function saveToolState( int $enrolment_id, string $lesson_slug, array $state ): void {
        $existing = $this->ensureRow( $enrolment_id, $lesson_slug );
        if ( $existing === null ) {
            return;
        }

        $merged = array_merge( $this->toolState( $existing ), $state );

        $this->wpdb->update(
            $this->table,
            [ 'tool_state' => wp_json_encode( $merged ) ],
            [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * A row's decoded tool state, or an empty array.
     *
     * @return array<string, mixed>
     */
    public function toolState( ?object $row ): array {
        if ( $row === null || ! isset( $row->tool_state ) || $row->tool_state === null || $row->tool_state === '' ) {
            return [];
        }

        $decoded = json_decode( (string) $row->tool_state, true );

        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * The row for this lesson, created empty if it does not exist yet.
     *
     * Progress rows are made on first touch rather than seeded for every
     * lesson at enrolment: a course can gain a lesson after someone enrols,
     * and seeding would leave the new lesson missing for exactly the people
     * already on the course.
     */
    private function ensureRow( int $enrolment_id, string $lesson_slug ): ?object {
        if ( $enrolment_id <= 0 || $lesson_slug === '' ) {
            return null;
        }

        $existing = $this->find( $enrolment_id, $lesson_slug );
        if ( $existing !== null ) {
            return $existing;
        }

        $this->wpdb->insert( $this->table, [
            'club_id'      => CurrentClub::id(),
            'enrolment_id' => $enrolment_id,
            'lesson_slug'  => $lesson_slug,
        ] );

        return $this->find( $enrolment_id, $lesson_slug );
    }
}
