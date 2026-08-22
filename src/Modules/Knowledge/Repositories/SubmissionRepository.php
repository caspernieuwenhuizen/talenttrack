<?php
namespace TT\Modules\Knowledge\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SubmissionRepository — a practical assignment and the verdict on it.
 *
 * The assignments are why the course has a review queue at all. A quiz can
 * establish that a coach knows 4v4 needs seventy-two hours; only a mentor
 * reading a submitted twelve-week plan can establish that they built one.
 *
 * Attachments are not stored here. They ride `tt_media_links` with
 * `entity_type = 'course_submission'` (#2589), so a photo of a whiteboard
 * goes through the same private store, visibility rules and lifecycle as
 * every other file in the system.
 *
 * The review UI is #2648; this is the storage and the state machine.
 */
class SubmissionRepository {

    /** Awaiting review — the empty outcome, which the queue selects on. */
    public const OUTCOME_PENDING  = '';
    public const OUTCOME_APPROVED = 'approved';
    public const OUTCOME_CHANGES  = 'changes_requested';
    public const OUTCOME_REJECTED = 'rejected';

    /** The entity type this module's attachments use in `tt_media_links`. */
    public const MEDIA_ENTITY_TYPE = 'course_submission';

    /** @return list<string> */
    public static function outcomes(): array {
        return [ self::OUTCOME_APPROVED, self::OUTCOME_CHANGES, self::OUTCOME_REJECTED ];
    }

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_course_submissions';
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

    /**
     * The latest submission for one lesson.
     *
     * A lesson can be submitted more than once — that is what
     * changes-requested means — so "the submission" is always the most
     * recent one.
     */
    public function latestFor( int $enrolment_id, string $lesson_slug ): ?object {
        if ( $enrolment_id <= 0 || $lesson_slug === '' ) {
            return null;
        }

        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE enrolment_id = %d AND lesson_slug = %s AND club_id = %d
              ORDER BY id DESC LIMIT 1",
            $enrolment_id,
            $lesson_slug,
            CurrentClub::id()
        ) ) ?: null;
    }

    /** @return object[] */
    public function listForEnrolment( int $enrolment_id ): array {
        if ( $enrolment_id <= 0 ) {
            return [];
        }

        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE enrolment_id = %d AND club_id = %d
              ORDER BY id DESC",
            $enrolment_id,
            CurrentClub::id()
        ) ) ?: [];
    }

    /**
     * The review queue: submitted, not yet judged, oldest first.
     *
     * Oldest first because a queue that shows the newest first quietly
     * starves whoever submitted on a busy week.
     *
     * `$reviewer_person_id` narrows to one reviewer's own queue; zero
     * returns everything awaiting anyone. An unrouted submission has no
     * reviewer, and is deliberately visible to every holder of the
     * capability rather than invisible to all of them.
     *
     * @return object[]
     */
    public function listPending( int $reviewer_person_id = 0 ): array {
        $club = CurrentClub::id();

        if ( $reviewer_person_id > 0 ) {
            return $this->wpdb->get_results( $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                  WHERE club_id = %d AND outcome = %s AND submitted_at IS NOT NULL
                    AND ( reviewer_person_id = %d OR reviewer_person_id IS NULL )
                  ORDER BY submitted_at ASC",
                $club,
                self::OUTCOME_PENDING,
                $reviewer_person_id
            ) ) ?: [];
        }

        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE club_id = %d AND outcome = %s AND submitted_at IS NOT NULL
              ORDER BY submitted_at ASC",
            $club,
            self::OUTCOME_PENDING
        ) ) ?: [];
    }

    /**
     * Submit an assignment.
     *
     * Always a new row, never an update of the previous one. A resubmission
     * after changes-requested has to leave the earlier attempt and its
     * feedback intact — that history is the record of the coaching, and
     * overwriting it would erase the reviewer's side of the conversation.
     */
    public function submit( int $enrolment_id, string $lesson_slug, string $assignment_key, string $body ): int {
        if ( $enrolment_id <= 0 || $lesson_slug === '' ) {
            return 0;
        }

        $this->wpdb->insert( $this->table, [
            'uuid'           => wp_generate_uuid4(),
            'club_id'        => CurrentClub::id(),
            'enrolment_id'   => $enrolment_id,
            'lesson_slug'    => $lesson_slug,
            'assignment_key' => $assignment_key,
            'body'           => $body,
            'submitted_at'   => current_time( 'mysql' ),
            'outcome'        => self::OUTCOME_PENDING,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    /** Route a pending submission to a named reviewer. */
    public function assignReviewer( int $id, int $reviewer_person_id ): void {
        if ( $id <= 0 ) {
            return;
        }

        $this->wpdb->update(
            $this->table,
            [ 'reviewer_person_id' => $reviewer_person_id > 0 ? $reviewer_person_id : null ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Record a verdict.
     *
     * Feedback is mandatory on anything other than approval — an outcome
     * without a reason is not review, and the caller is rejected rather
     * than silently storing an empty verdict.
     */
    public function review( int $id, string $outcome, string $feedback, int $reviewer_person_id ): bool {
        if ( $id <= 0 || ! in_array( $outcome, self::outcomes(), true ) ) {
            return false;
        }

        if ( $outcome !== self::OUTCOME_APPROVED && trim( $feedback ) === '' ) {
            return false;
        }

        $updated = $this->wpdb->update(
            $this->table,
            [
                'outcome'            => $outcome,
                'feedback'           => $feedback,
                'reviewed_at'        => current_time( 'mysql' ),
                'reviewer_person_id' => $reviewer_person_id > 0 ? $reviewer_person_id : null,
            ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );

        return $updated !== false && $updated > 0;
    }

    /** How many submissions are waiting, for the roll-up and the bell. */
    public function countPending(): int {
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE club_id = %d AND outcome = %s AND submitted_at IS NOT NULL",
            CurrentClub::id(),
            self::OUTCOME_PENDING
        ) );
    }
}
