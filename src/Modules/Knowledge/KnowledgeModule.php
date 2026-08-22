<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Knowledge\Rest\KnowledgeRestController;

/**
 * KnowledgeModule (#2642, epic #2641) — the knowledge library.
 *
 * Courses for coach development: a curriculum shipped with the plugin,
 * gated, tracked, and rolled up so a head of development can answer
 * whether the staff around a squad is trained in the method that squad is
 * coached with.
 *
 * This ship is the content spine only — the corpus format, the parsers and
 * the registry. There is no schema, no gate and no view yet; those land in
 * #2644, #2645 and #2646. What exists after this ship is a codebase that
 * knows what a course is, and a CI gate that keeps the corpus honest.
 *
 * #2644 adds the enrolment schema, the repositories and the REST surface,
 * and with them the three capabilities that gate it.
 */
class KnowledgeModule implements ModuleInterface {

    public function getName(): string { return 'knowledge'; }

    public function register( Container $container ): void {}

    /**
     * Courses live on disk, so the registry's cache has to be dropped when
     * the files underneath it can have changed. The transient is keyed by
     * version and locale, which covers an update and a multilingual
     * install on its own; this covers the case the key cannot see — the
     * plugin being re-installed at the same version.
     */
    public function boot( Container $container ): void {
        add_action( 'upgrader_process_complete', [ CourseRegistry::class, 'flushCache' ] );

        // Registered, not enqueued: a lesson pulls the stylesheet in when
        // it renders, and the script only when a block on it needs one.
        add_action( 'wp_enqueue_scripts', [ LessonRenderer::class, 'registerAssets' ] );

        add_action( 'init', [ self::class, 'ensureCapabilities' ] );

        KnowledgeRestController::init();
    }

    /**
     * Idempotent capability assignment.
     *
     *   tt_view_knowledge             — see the library and work through a
     *                                   course. Own record only.
     *   tt_view_knowledge_statistics  — see everyone's progress: the
     *                                   per-course, per-person and per-team
     *                                   roll-up.
     *   tt_manage_knowledge           — assign courses, set due dates,
     *                                   withdraw enrolments, review
     *                                   submitted assignments.
     *
     * Three levels rather than the usual view/manage pair, because a coach
     * must be able to see their own progress without seeing their
     * colleagues'. Folding the roll-up into `tt_view_knowledge` would make
     * hiding a column the only thing between a coach and their peers'
     * completion rates.
     */
    public static function ensureCapabilities(): void {
        $grants = [
            'tt_view_knowledge'            => [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_coach', 'tt_scout', 'tt_staff' ],
            'tt_view_knowledge_statistics' => [ 'administrator', 'tt_head_dev', 'tt_club_admin' ],
            'tt_manage_knowledge'          => [ 'administrator', 'tt_head_dev', 'tt_club_admin' ],
        ];

        foreach ( $grants as $cap => $roles ) {
            foreach ( $roles as $role_name ) {
                $role = get_role( $role_name );
                if ( $role && ! $role->has_cap( $cap ) ) {
                    $role->add_cap( $cap );
                }
            }
        }
    }
}
