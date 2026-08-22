<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BlockRenderer — one interactive element inside a lesson.
 *
 * A lesson is markdown; a block is a fenced section whose info string
 * names a renderer:
 *
 *     ```tt-zeropoint method="extensive_endurance"
 *     ```
 *
 * Markdown is the storage format, not the render. Keeping the corpus in
 * markdown is what makes a course reviewable in a pull request and
 * translatable like any other text; this interface is what stops that
 * choice from capping the course at prose.
 *
 * Implementations are static because a block has no state — it renders
 * from its attributes and its body, and anything it remembers about a
 * reader lives in `tt_course_progress` (#2644), not in the object.
 */
interface BlockRenderer {

    /**
     * The info string this renderer claims, e.g. `tt-zeropoint`.
     * Must be unique across the registry.
     */
    public static function name(): string;

    /**
     * Render to HTML.
     *
     * Output is trusted only insofar as the corpus is plugin-shipped;
     * implementations still escape everything they interpolate, because a
     * translated course is edited by people who are not reviewing PHP.
     *
     * @param array<string, string> $attrs Parsed from the info string.
     * @param string                $body  Raw text between the fences.
     */
    public static function render( array $attrs, string $body ): string;

    /**
     * Whether this block needs the block script.
     *
     * A lesson made only of callouts and tables should not load the
     * interactive bundle. Returning false here is what keeps a reading-only
     * lesson at zero JavaScript.
     */
    public static function isInteractive(): bool;
}
