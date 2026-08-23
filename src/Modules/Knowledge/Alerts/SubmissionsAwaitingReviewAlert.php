<?php
namespace TT\Modules\Knowledge\Alerts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Knowledge\Frontend\KnowledgeLinks;
use TT\Modules\Knowledge\Repositories\SubmissionRepository;

/**
 * SubmissionsAwaitingReviewAlert (#2648, epic #2641) — coursework is sitting
 * in somebody's review queue.
 *
 * Which player question does this answer? *What does this player need
 * next?* — from the staff side, like the certificate alert. A coach's
 * twelve-week periodisation plan sitting unread for three weeks is a
 * development conversation that is not happening, and the squad that coach
 * runs is the thing waiting on it.
 *
 * ## An alert rather than an email, deliberately
 *
 * The queue is a *state*, not an event. Sending "a submission arrived" mail
 * gives a reviewer one notification per submission and no way to tell what
 * is still outstanding; a state-derived alert says what is waiting right
 * now and resolves itself the moment the queue empties. That is exactly the
 * shape the Alerts foundation exists for, so nothing here sends mail —
 * `evaluate()` reports and the evaluator reconciles.
 *
 * ## One occurrence per submission, not per reviewer
 *
 * Subject is the submission, so a queue of five reads as five things to do
 * and clearing one resolves one. Rolling them into a single per-reviewer
 * occurrence would make a five-deep queue and a one-deep queue look
 * identical, and it would only resolve when the last one was cleared.
 *
 * ## Ageing
 *
 * A submission is `info` on the day it lands and `attention` after a week.
 * Coursework is not urgent — nothing here outranks an expiring safeguarding
 * certificate — but a coach who handed work in a fortnight ago and heard
 * nothing has been let down, and that is worth saying more loudly than on
 * day one.
 */
final class SubmissionsAwaitingReviewAlert implements AlertInterface {

    public const SUBJECT_TYPE = 'course_submission';

    /** Days after which a waiting submission ages up. */
    private const STALE_AFTER_DAYS = 7;

    /** Most people one unrouted submission fans out to. */
    private const MAX_REVIEWERS = 20;

    /** Accounts scanned looking for those people. See `managerUserIds()`. */
    private const SCAN_CEILING = 1000;

    public function key(): string {
        return 'knowledge.submission_awaiting_review';
    }

    public function module(): string {
        return 'knowledge';
    }

    public function label(): string {
        return __( 'Assignment waiting for review', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A coach has handed in a practical assignment and is waiting on your decision. Their course cannot finish until you make one.', 'talenttrack' );
    }

    public function defaultSeverity(): string {
        return Severity::INFO;
    }

    /**
     * The coarse capability, not the management one.
     *
     * Work is routed to mentors, who hold `tt_view_knowledge` and nothing
     * more. Declaring `tt_manage_knowledge` here would have the evaluator
     * strip the occurrence from exactly the person the submission was
     * routed to. The audience is narrowed by `evaluate()` naming its
     * recipients — this rung only has to not exclude them.
     */
    public function capRequired(): string {
        return 'tt_view_knowledge';
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ 'badge', 'banner' ];
    }

    /**
     * Not operational. A club that wants review turnaround enforced can
     * raise it in the policy layer (#2632); a definition should not make
     * itself unmutable on its own authority.
     */
    public function isOperational(): bool {
        return false;
    }

    /** @return list<AlertOccurrence> */
    public function evaluate( AlertContext $context ): array {
        $out = [];

        foreach ( $this->rows( $context ) as $row ) {
            $submission_id = (int) ( $row->subject_id ?? 0 );
            $user_id       = (int) ( $row->wp_user_id ?? 0 );
            if ( $submission_id <= 0 || $user_id <= 0 ) continue;

            $out[] = new AlertOccurrence(
                $this->key(),
                $user_id,
                self::SUBJECT_TYPE,
                $submission_id,
                $this->severityFor( (string) ( $row->submitted_at ?? '' ) ),
                [
                    'title' => $this->titleFor( $row ),
                    'url'   => KnowledgeLinks::submissionReview(),
                ]
            );
        }

        return $out;
    }

    /**
     * Every pending submission joined to the person who should read it.
     *
     * One statement for the whole club, per the interface's set-based rule.
     * The routing is a `UNION` of two audiences rather than a single join,
     * because they are genuinely different questions:
     *
     *   - a **routed** submission goes to its named reviewer, and to nobody
     *     else — a mentor's queue should not also land on every
     *     administrator's bell.
     *   - an **unrouted** submission has no mentor to send it to, so it
     *     goes to every holder of the management capability. Those are
     *     resolved from role capabilities rather than a table, hence the
     *     second arm being driven by a PHP-side user list.
     *
     * @return list<object>
     */
    private function rows( AlertContext $context ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $sql = $wpdb->prepare(
            "SELECT s.id AS subject_id, s.submitted_at, s.reviewer_person_id,
                    pe.wp_user_id,
                    author.first_name, author.last_name
               FROM {$p}tt_course_submissions s
         INNER JOIN {$p}tt_people pe
                 ON pe.id = s.reviewer_person_id AND pe.club_id = s.club_id
          LEFT JOIN {$p}tt_course_enrolments e
                 ON e.id = s.enrolment_id AND e.club_id = s.club_id
          LEFT JOIN {$p}tt_people author
                 ON author.id = e.person_id AND author.club_id = s.club_id
              WHERE " . QueryHelpers::clubScopeWhere( 's' ) . "
                AND s.outcome = %s
                AND s.submitted_at IS NOT NULL
                AND s.reviewer_person_id IS NOT NULL
                AND pe.archived_at IS NULL
                AND pe.wp_user_id != 0"
            . $context->applyScope( self::SUBJECT_TYPE, 's.id' ) . "
              ORDER BY s.submitted_at ASC, s.id ASC",
            SubmissionRepository::OUTCOME_PENDING
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        $rows = is_array( $rows ) ? $rows : [];

        return array_merge( $rows, $this->unroutedRows( $context ) );
    }

    /**
     * Submissions with no mentor behind them, fanned out to the people who
     * can clear them.
     *
     * @return list<object>
     */
    private function unroutedRows( AlertContext $context ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $sql = $wpdb->prepare(
            "SELECT s.id AS subject_id, s.submitted_at,
                    author.first_name, author.last_name
               FROM {$p}tt_course_submissions s
          LEFT JOIN {$p}tt_course_enrolments e
                 ON e.id = s.enrolment_id AND e.club_id = s.club_id
          LEFT JOIN {$p}tt_people author
                 ON author.id = e.person_id AND author.club_id = s.club_id
              WHERE " . QueryHelpers::clubScopeWhere( 's' ) . "
                AND s.outcome = %s
                AND s.submitted_at IS NOT NULL
                AND s.reviewer_person_id IS NULL"
            . $context->applyScope( self::SUBJECT_TYPE, 's.id' ) . "
              ORDER BY s.submitted_at ASC, s.id ASC",
            SubmissionRepository::OUTCOME_PENDING
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $pending = $wpdb->get_results( $sql );
        if ( ! is_array( $pending ) || $pending === [] ) return [];

        $managers = $this->managerUserIds();
        if ( $managers === [] ) return [];

        $out = [];
        foreach ( $pending as $row ) {
            foreach ( $managers as $user_id ) {
                $copy             = clone $row;
                $copy->wp_user_id = $user_id;
                $out[]            = $copy;
            }
        }

        return $out;
    }

    /**
     * Users who can clear an unrouted submission.
     *
     * Enumerate-then-ask, not `get_users( [ 'capability' => … ] )`. That
     * query reads the capabilities stored on a user's roles, and most
     * TalentTrack caps are not stored there — they are matrix-derived and
     * bridged at runtime through the `user_has_cap` filter, which a meta
     * query never runs. `AbstractDataQualityAlert::custodians()` learned
     * this the hard way and found nobody; the shape here is deliberately
     * the same as its fix, including both bounds, so one definition cannot
     * turn the hourly sweep into an unbounded scan.
     *
     * @return list<int>
     */
    private function managerUserIds(): array {
        if ( ! function_exists( 'get_users' ) ) return [];

        $ids = get_users( [
            'fields'  => 'ID',
            'number'  => self::SCAN_CEILING,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ] );

        $out = [];
        foreach ( is_array( $ids ) ? $ids : [] as $id ) {
            $id = (int) $id;
            if ( $id <= 0 || ! user_can( $id, 'tt_manage_knowledge' ) ) continue;

            $out[] = $id;
            if ( count( $out ) >= self::MAX_REVIEWERS ) break;
        }

        return $out;
    }

    private function severityFor( string $submitted_at ): string {
        if ( $submitted_at === '' ) return Severity::INFO;

        $ts = strtotime( $submitted_at );
        if ( $ts === false ) return Severity::INFO;

        $days = (int) floor( ( current_time( 'timestamp' ) - $ts ) / DAY_IN_SECONDS );

        return $days >= self::STALE_AFTER_DAYS ? Severity::ATTENTION : Severity::INFO;
    }

    private function titleFor( object $row ): string {
        $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
        if ( $name === '' ) $name = __( 'A coach', 'talenttrack' );

        return sprintf(
            /* translators: %s: the coach who handed the assignment in */
            __( '%s is waiting on your review of their assignment.', 'talenttrack' ),
            $name
        );
    }
}
