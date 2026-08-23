<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Knowledge\Blocks\BlockRegistry;

/**
 * SubmissionQueue (#2648, epic #2641) — what the review queue needs to know
 * about the rows it is showing.
 *
 * Sits outside the view because it is the queue's data, not its markup: who
 * wrote each submission, which course it belongs to, and what the
 * assignment actually asked (CLAUDE.md §4 — delete the view and the
 * questions still have answers).
 *
 * ## One query for the whole page
 *
 * A queue is a list, and resolving the author per row is how a page gets
 * slow exactly when it is longest — the Monday-morning backlog is the case
 * that matters. `contextFor()` takes the whole result set and issues one
 * statement.
 */
final class SubmissionQueue {

    /**
     * Author name and course slug for every enrolment behind these rows.
     *
     * @param  object[] $submissions
     * @return array<int, array{author: string, course_slug: string, person_id: int}>
     *         keyed by enrolment id
     */
    public static function contextFor( array $submissions ): array {
        $ids = [];
        foreach ( $submissions as $submission ) {
            $id = (int) ( $submission->enrolment_id ?? 0 );
            if ( $id > 0 ) $ids[ $id ] = true;
        }

        if ( $ids === [] ) return [];

        global $wpdb;
        $p           = $wpdb->prefix;
        $ids         = array_keys( $ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $sql = $wpdb->prepare(
            "SELECT e.id, e.course_slug, e.person_id, pe.first_name, pe.last_name
               FROM {$p}tt_course_enrolments e
          LEFT JOIN {$p}tt_people pe ON pe.id = e.person_id AND pe.club_id = e.club_id
              WHERE e.id IN ( {$placeholders} ) AND e.club_id = %d",
            array_merge( $ids, [ CurrentClub::id() ] )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );

            $out[ (int) $row->id ] = [
                // A submission whose author's person row has since been
                // removed still has to be reviewable — the work was done.
                'author'      => $name !== '' ? $name : __( 'A coach', 'talenttrack' ),
                'course_slug' => (string) $row->course_slug,
                'person_id'   => (int) $row->person_id,
            ];
        }

        return $out;
    }

    /**
     * The assignment text from a lesson, rendered on its own.
     *
     * The reviewer needs the question beside the answer, but not the rest
     * of the lesson and emphatically not the hand-in form — running the
     * whole body through `LessonRenderer` would put a second submission
     * control on the reviewer's page, pointed at their own enrolment.
     * So the `tt-assignment` fence is lifted out and only its prose is
     * rendered.
     *
     * Returns an empty string when the lesson declares an assignment whose
     * block cannot be found; `course-lint` is what stops that reaching a
     * release, and a reviewer seeing nothing is better than seeing a
     * rendering of the wrong thing.
     */
    public static function assignmentHtml( CourseLesson $lesson ): string {
        $body = self::assignmentBody( $lesson->body() );
        if ( $body === '' ) return '';

        return '<div class="tt-review-card__brief-body">'
            . LessonMarkdown::renderProse( $body )
            . '</div>';
    }

    /**
     * The contents of the first `tt-assignment` fence in a lesson body.
     *
     * Fence scanning rather than a regex over the whole body: an assignment
     * can contain a markdown table or an indented block, and matching to
     * the next closing fence is the only way to end in the right place.
     */
    private static function assignmentBody( string $markdown ): string {
        $lines   = preg_split( '/\R/', $markdown ) ?: [];
        $count   = count( $lines );
        $collect = null;

        for ( $i = 0; $i < $count; $i++ ) {
            $line = $lines[ $i ];

            if ( $collect === null ) {
                if ( ! preg_match( '/^```(.*)$/', $line, $match ) ) continue;
                if ( BlockRegistry::parseName( $match[1] ) !== 'tt-assignment' ) continue;
                $collect = [];
                continue;
            }

            if ( preg_match( '/^```\s*$/', $line ) ) break;

            $collect[] = $line;
        }

        return $collect === null ? '' : trim( implode( "\n", $collect ) );
    }
}
