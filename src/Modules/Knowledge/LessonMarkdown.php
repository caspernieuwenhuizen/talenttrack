<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Blocks\BlockRegistry;
use TT\Shared\Content\MarkdownProfile;
use TT\Shared\Content\MarkdownRenderer;

/**
 * LessonMarkdown — the course reader's view of the shared markdown renderer.
 *
 * #2663 folded the parsing into `Shared\Content\MarkdownRenderer`, which the
 * help topics now share. What is left here is the lesson's own profile: the
 * `tt-lesson-*` class prefix, and fence delegation to `BlockRegistry`, which
 * is the mechanism the interactive blocks hang off.
 *
 * Kept as a named class rather than folded into its callers because the
 * blocks call back into it (`renderProse()` for a callout's body) and a
 * course-shaped entry point is easier to reason about than passing a profile
 * around.
 */
final class LessonMarkdown {

    /**
     * Render a full lesson body: prose, tables and blocks.
     *
     * @return array{html: string, interactive: bool} `interactive` is true
     *         when at least one rendered block needs the block script.
     */
    public static function render( string $source ): array {
        return self::renderer()->render( $source );
    }

    /**
     * Render a fragment that is prose only — a callout's body, an
     * assignment's text. Blocks are not nested, so anything fenced inside
     * one renders as a code block.
     */
    public static function renderProse( string $source ): string {
        return self::renderer()->renderProse( $source );
    }

    private static function renderer(): MarkdownRenderer {
        return new MarkdownRenderer( new MarkdownProfile(
            'tt-lesson',
            4,
            static function ( string $info, string $body ): ?array {
                $name = BlockRegistry::parseName( $info );
                if ( $name === '' ) {
                    return null;
                }
                $class = BlockRegistry::resolve( $name );
                if ( $class === null ) {
                    return null;
                }

                return [
                    'html'        => $class::render( BlockRegistry::parseAttributes( $info ), $body ),
                    'interactive' => $class::isInteractive(),
                ];
            }
        ) );
    }
}
