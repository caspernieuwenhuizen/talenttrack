<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BlockOptionsInterface (#3514, epic #3513) — the shape of one block's options.
 *
 * A block that can be told *what* to show implements this. The composition
 * carries the bag; it never knows what is in it. The alternative — one central
 * switch listing every block's options — is where a growing report rots, and
 * the block vocabulary already made that mistake impossible for blocks
 * themselves.
 *
 * Two methods because the callers want opposite things from the same input:
 * the screen is forgiving (a saved view from last season must still open a
 * report) while the REST validator is strict (a typed filter that silently
 * does nothing renders a document nobody asked for). So `normalise()` drops
 * what it cannot use and `unknownKeys()` reports it.
 */
interface BlockOptionsInterface {

    /**
     * The options this block accepts, with everything else dropped and every
     * missing or unusable value replaced by its default.
     *
     * Must be total: any input, however malformed, returns a usable bag. An
     * empty result means "render as you always did", which is what a block
     * with no options recorded gets.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise( array $raw ): array;

    /**
     * Option keys this block does not recognise, so a strict caller can refuse
     * the request rather than render a report quietly missing what was asked
     * for.
     *
     * Keys only. An unknown *value* is not reported here: a test definition
     * deleted since the view was saved is expected, and falls back to the
     * default rather than failing the request.
     *
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    public static function unknownKeys( array $raw ): array;
}
