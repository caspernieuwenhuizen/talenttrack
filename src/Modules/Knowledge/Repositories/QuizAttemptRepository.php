<?php
namespace TT\Modules\Knowledge\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * QuizAttemptRepository — every attempt, not just the last.
 *
 * Kept apart from `tt_course_progress` on purpose. Progress answers "did
 * they pass", which is what the sequential gate needs. The attempt log
 * answers "how many tries and which questions", which is what a head of
 * development reading a coach's development actually wants — and which a
 * single latest-attempt column would silently discard.
 *
 * Scoring is not here: it lands with the quiz renderer in #2647 and runs
 * server-side, because the answer key lives in the payload and must never
 * reach the browser. This repository stores what that scorer decided.
 */
class QuizAttemptRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_course_quiz_attempts';
    }

    /**
     * Record one attempt.
     *
     * `$answers` is the submitted payload, stored unmarked. Re-marking an
     * old attempt after a quiz is corrected has to be possible; keeping only
     * the score would make that unauditable.
     *
     * @param array<string, mixed> $answers
     */
    public function record( int $enrolment_id, string $lesson_slug, array $answers, int $score, int $max_score, bool $passed ): int {
        if ( $enrolment_id <= 0 || $lesson_slug === '' ) {
            return 0;
        }

        $this->wpdb->insert( $this->table, [
            'club_id'      => CurrentClub::id(),
            'enrolment_id' => $enrolment_id,
            'lesson_slug'  => $lesson_slug,
            'answers'      => wp_json_encode( $answers ),
            'score'        => max( 0, $score ),
            'max_score'    => max( 0, $max_score ),
            'passed'       => $passed ? 1 : 0,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    /**
     * A learner's attempts at one lesson, newest first.
     *
     * @return object[]
     */
    public function listFor( int $enrolment_id, string $lesson_slug ): array {
        if ( $enrolment_id <= 0 || $lesson_slug === '' ) {
            return [];
        }

        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE enrolment_id = %d AND lesson_slug = %s AND club_id = %d
              ORDER BY created_at DESC, id DESC",
            $enrolment_id,
            $lesson_slug,
            CurrentClub::id()
        ) ) ?: [];
    }

    /** How many times this lesson's quiz has been attempted. */
    public function countFor( int $enrolment_id, string $lesson_slug ): int {
        if ( $enrolment_id <= 0 || $lesson_slug === '' ) {
            return 0;
        }

        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE enrolment_id = %d AND lesson_slug = %s AND club_id = %d",
            $enrolment_id,
            $lesson_slug,
            CurrentClub::id()
        ) );
    }

    public function hasPassed( int $enrolment_id, string $lesson_slug ): bool {
        if ( $enrolment_id <= 0 || $lesson_slug === '' ) {
            return false;
        }

        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE enrolment_id = %d AND lesson_slug = %s AND club_id = %d AND passed = 1",
            $enrolment_id,
            $lesson_slug,
            CurrentClub::id()
        ) ) > 0;
    }
}
