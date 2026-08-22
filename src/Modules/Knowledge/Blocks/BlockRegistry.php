<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BlockRegistry — maps a fence info string to the renderer that claims it.
 *
 * The catalogue is a static list rather than a discovery scan: there are a
 * dozen blocks, they ship with the plugin, and a filter is a better
 * extension point than a directory walk. Third parties add one through
 * `tt_knowledge_blocks`.
 *
 * An unclaimed info string is not an error. A course written against a
 * newer release, opened on an older one, renders the unknown block as a
 * code fence and keeps going — the reader loses one widget instead of the
 * whole lesson.
 */
final class BlockRegistry {

    /** @var array<string, class-string<BlockRenderer>>|null */
    private static $memo = null;

    /**
     * Every registered renderer, keyed by info string.
     *
     * @return array<string, class-string<BlockRenderer>>
     */
    public static function all(): array {
        if ( self::$memo !== null ) {
            return self::$memo;
        }

        $classes = [
            CalloutBlock::class,
            RevealBlock::class,
            ActionLineBlock::class,
            ModelBlock::class,
            PitchSizeBlock::class,
            ZeroPointBlock::class,
            WeekPlannerBlock::class,
            LoadMatrixBlock::class,
            QuizBlock::class,
            AssignmentBlock::class,
        ];

        /**
         * Filter the lesson block renderers.
         *
         * @param list<class-string<BlockRenderer>> $classes
         */
        $classes = apply_filters( 'tt_knowledge_blocks', $classes );

        $map = [];
        foreach ( $classes as $class ) {
            if ( is_string( $class ) && is_subclass_of( $class, BlockRenderer::class ) ) {
                $map[ $class::name() ] = $class;
            }
        }

        self::$memo = $map;

        return $map;
    }

    /** The renderer for an info string, or null when nothing claims it. */
    public static function resolve( string $name ): ?string {
        return self::all()[ $name ] ?? null;
    }

    /** Whether any registered block claims this info string. */
    public static function has( string $name ): bool {
        return isset( self::all()[ $name ] );
    }

    /** Drop the memo. Tests call it after filtering the catalogue. */
    public static function flush(): void {
        self::$memo = null;
    }

    /**
     * Parse the attributes off a fence info string.
     *
     * `tt-quiz pass="4" mode='strict'` yields
     * `[ 'pass' => '4', 'mode' => 'strict' ]`. Bare words after the block
     * name are ignored rather than guessed at — a positional argument in a
     * corpus that people translate is a bug waiting to be introduced.
     *
     * @return array<string, string>
     */
    public static function parseAttributes( string $info ): array {
        $attrs = [];

        // The quoted section is captured whole, quotes included, and
        // stripped afterwards. Capturing the two quote styles as separate
        // alternatives means an empty value and a non-participating group
        // both arrive as '', which is a distinction the caller would then
        // have to make on the caller's behalf.
        if ( preg_match_all( '/([a-z_][a-z0-9_-]*)\s*=\s*("[^"]*"|\'[^\']*\')/i', $info, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $attrs[ strtolower( $match[1] ) ] = substr( $match[2], 1, -1 );
            }
        }

        return $attrs;
    }

    /**
     * The block name from a fence info string — the first token.
     * Empty when the fence carries no info string at all.
     */
    public static function parseName( string $info ): string {
        $info = trim( $info );
        if ( $info === '' ) {
            return '';
        }

        $parts = preg_split( '/\s+/', $info );

        return is_array( $parts ) ? (string) $parts[0] : '';
    }
}
