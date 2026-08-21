<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

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
 * Capabilities are deliberately not installed here. Nothing is reachable
 * until #2646 adds a surface, and a capability that gates nothing is a
 * capability nobody can reason about. The manifest key `capability:` is
 * parsed and carried through now so the corpus can declare its intent
 * before the gate exists to enforce it.
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
    }
}
