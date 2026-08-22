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

    /**
     * @param array<string, string> $args
     */
    private static function to( array $args ): string {
        return BackLink::appendTo( add_query_arg( $args, get_permalink() ) );
    }
}
