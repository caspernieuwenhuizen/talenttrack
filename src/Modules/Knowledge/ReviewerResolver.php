<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ReviewerResolver (#2648, epic #2641) — who reads a coach's assignment.
 *
 * Mentorship first, capability second. A coach who already has a mentor is
 * in a development conversation with a named person, and routing their
 * coursework past that person to a general queue would put the club's most
 * useful reviewer last in line. Where there is no mentorship, the
 * submission belongs to whoever holds `tt_manage_knowledge`.
 *
 * ## Unrouted is a state, not a failure
 *
 * `forSubmitter()` returns 0 when there is no mentor, and that zero is
 * stored as a null `reviewer_person_id`. `SubmissionRepository::listPending()`
 * shows a null-reviewer row to every capability holder, so an unrouted
 * submission is visible to all of them rather than invisible to each. The
 * alternative — picking an arbitrary capability holder at submit time —
 * would look tidier in the column and would silently make one person
 * responsible for a queue nobody told them about.
 *
 * ## Resolution happens once, at submit
 *
 * The mentor is resolved when the assignment is handed in and written onto
 * the row, not recomputed when the queue is read. A mentorship that ends
 * mid-review must not make a submission vanish from the queue of the person
 * already reading it, and a coach must be able to see, later, who actually
 * signed their work off. Re-resolving on read would give both of those the
 * wrong answer.
 */
final class ReviewerResolver {

    /** The capability that makes someone a reviewer of last resort. */
    public const REVIEW_CAP = 'tt_manage_knowledge';

    /**
     * The person a submission from this author should go to, or 0 when it
     * belongs to the shared queue.
     *
     * Where a coach has more than one active mentor — the schema permits it
     * — the longest-standing is chosen. Any rule would be arbitrary; this
     * one at least is stable across calls, which matters because the answer
     * is persisted.
     */
    public static function forSubmitter( int $author_person_id ): int {
        if ( $author_person_id <= 0 ) return 0;

        global $wpdb;

        $mentor_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT mentor_person_id
               FROM {$wpdb->prefix}tt_staff_mentorships
              WHERE mentee_person_id = %d
                AND club_id = %d
                AND ended_on IS NULL
              ORDER BY started_on ASC, id ASC
              LIMIT 1",
            $author_person_id,
            CurrentClub::id()
        ) );

        // A mentor who is also the author would be reviewing their own work.
        // The schema forbids creating such a pairing, so this is defence
        // against data that predates that rule rather than an expected case.
        return $mentor_id === $author_person_id ? 0 : $mentor_id;
    }

    /**
     * May this person act on this submission as its reviewer?
     *
     * True for the person it is routed to, and for any capability holder —
     * including on a routed submission. A head of development must be able
     * to clear a queue when a mentor is on holiday, and the audit trail
     * records who actually decided it, so the wider grant costs nothing and
     * prevents work stalling behind one absent person.
     */
    public static function canReview( int $user_id, int $person_id, ?int $reviewer_person_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( user_can( $user_id, self::REVIEW_CAP ) ) return true;

        return $person_id > 0
            && $reviewer_person_id !== null
            && (int) $reviewer_person_id === $person_id;
    }

    /**
     * Is this person a reviewer at all — mentor of somebody, or a holder of
     * the capability?
     *
     * What the review-queue view asks before rendering, and what decides
     * whether the queue tile is offered. A mentor holds no special
     * capability, so a capability check alone would hide the queue from
     * exactly the people the mentorship-first rule routes work to.
     */
    public static function isReviewer( int $user_id, int $person_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( user_can( $user_id, self::REVIEW_CAP ) ) return true;
        if ( $person_id <= 0 ) return false;

        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$wpdb->prefix}tt_staff_mentorships
              WHERE mentor_person_id = %d AND club_id = %d AND ended_on IS NULL",
            $person_id,
            CurrentClub::id()
        ) ) > 0;
    }
}
