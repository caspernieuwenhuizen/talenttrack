<?php
namespace TT\Modules\Knowledge\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\BackLink;

/**
 * KnowledgeLinks — URLs between the knowledge surfaces.
 *
 * Four views link to each other in eight places. Building the query args
 * inline at each of them is how one of the eight ends up without a
 * `tt_back` hint, and the destination then renders no back-pill for a
 * reader who arrived from somewhere unexpected (docs/back-navigation.md).
 *
 * This builds the href and nothing else. Whether a reader *sees* the link
 * is a separate question, answered by `CrossViewLink::render()` at every
 * call site so the affordance disappears for anyone who could not open
 * the target (CLAUDE.md §7 / #2304). Centralising the URL does not
 * centralise the gate, and it is not meant to.
 */
final class KnowledgeLinks {

    /** The library index. */
    public static function library(): string {
        return self::to( [ 'tt_view' => 'knowledge' ] );
    }

    /** One course. */
    public static function course( string $course_slug ): string {
        return self::to( [ 'tt_view' => 'course', 'slug' => $course_slug ] );
    }

    /** One lesson within a course. */
    public static function lesson( string $course_slug, string $lesson_slug ): string {
        return self::to( [
            'tt_view' => 'lesson',
            'slug'    => $course_slug,
            'lesson'  => $lesson_slug,
        ] );
    }

    /** A coach's own learning record. */
    public static function myLearning(): string {
        return self::to( [ 'tt_view' => 'my-learning' ] );
    }

    /**
     * The review queue.
     *
     * Built without a `tt_back` hint, unlike the others: this is Cancel's
     * target on the verdict form, and a Cancel that carried the reviewer's
     * own arrival hint forward would send them out of the queue instead of
     * back to the top of it.
     */
    public static function submissionReview(): string {
        return self::plain( [ 'tt_view' => FrontendSubmissionReviewView::SLUG ] );
    }

    /**
     * @param array<string, string> $args
     */
    private static function to( array $args ): string {
        return BackLink::appendTo( self::plain( $args ) );
    }

    /**
     * The URL without a `tt_back` hint attached.
     *
     * @param array<string, string> $args
     */
    private static function plain( array $args ): string {
        return (string) add_query_arg( $args, get_permalink() );
    }
}
