<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\SearchViewer;
use TT\Modules\Knowledge\CourseAccessResolver;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * Courses (#4942): the courses in `CourseRegistry`, found by the course's
 * title and summary and by its lessons' titles.
 *
 * The courses are the ones `CourseAccessResolver::listableCourses()` lists
 * for the viewer, the list the courses page shows: an unavailable or a
 * denied course never comes back. A course locked behind a prerequisite or
 * the plan is listed, as on the courses page, and marked `locked`; its
 * lessons are not matched, because its page does not list them.
 *
 * The viewer needs the courses page's gate (`tt_view_knowledge`). With the
 * Knowledge module off the kind returns nothing.
 */
final class CourseSearchProvider extends MemorySearchProvider {

    public function kind(): string { return 'course'; }

    public function minChars(): int { return 2; }

    protected function entries( int $user_id ): array {
        if ( ! self::serves( $user_id ) ) return [];

        $person   = KnowledgePerson::forUser( $user_id );
        $resolver = new CourseAccessResolver();
        $kind     = _x( 'Course', 'search result kind', 'talenttrack' );
        // The course page's own address. Not `KnowledgeLinks::course()`,
        // which builds on the page being rendered: search answers over REST
        // too, where there is none.
        $dashboard = RecordLink::dashboardUrl();
        $out       = [];

        foreach ( $resolver->listableCourses( $person, $user_id ) as $slug => $manifest ) {
            $slug   = (string) $slug;
            $locked = $resolver->forCourse( $slug, $person, $user_id )->isLocked();
            $text   = [ $manifest->summary() ];
            if ( ! $locked ) {
                foreach ( CourseRegistry::lessons( $slug ) as $lesson ) $text[] = $lesson->title();
            }
            $out[] = [
                'key'      => 'course-' . $slug,
                'title'    => $manifest->title(),
                'text'     => implode( ' ', array_filter( $text ) ),
                'label'    => $manifest->title(),
                'sublabel' => $kind,
                'url'      => (string) add_query_arg( [ 'tt_view' => 'course', 'slug' => $slug ], $dashboard ),
                'extra'    => $locked ? [ 'locked' => true ] : [],
            ];
        }
        return $out;
    }

    public function confirm( int $user_id, array $hits ): array {
        return self::serves( $user_id ) ? $hits : [];
    }

    /** Staff with the courses read, with the module on. */
    private static function serves( int $user_id ): bool {
        if ( $user_id <= 0 || SearchViewer::isFamily( $user_id ) ) return false;
        if ( ! class_exists( '\\TT\\Modules\\Knowledge\\KnowledgeModule' ) ) return false;
        if ( ! \TT\Core\ModuleRegistry::isEnabled( \TT\Modules\Knowledge\KnowledgeModule::class ) ) return false;
        return user_can( $user_id, 'tt_view_knowledge' );
    }
}
