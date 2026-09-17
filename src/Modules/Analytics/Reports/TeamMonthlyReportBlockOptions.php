<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TeamMonthlyReportBlockOptions (#3514, epic #3513) — which block owns which
 * options.
 *
 * A lookup, not a switch: one line per block naming the class that owns its
 * options, and no knowledge here of what any of those options are. Adding
 * options to a block is a new class plus a line; it never edits shared logic.
 *
 * `tests` is the first entry (#3515). Every other block answers "no options",
 * which is exactly how the report behaved before this existed.
 */
final class TeamMonthlyReportBlockOptions {

    /**
     * Block key => class implementing BlockOptionsInterface.
     *
     * @return array<string, class-string<BlockOptionsInterface>>
     */
    private static function map(): array {
        return [
            TeamMonthlyReportBlock::TESTS   => TestsBlockOptions::class,
            TeamMonthlyReportBlock::MATCHES => MatchesBlockOptions::class,
        ];
    }

    /** Can this block be told anything beyond whether to appear? */
    public static function accepts( string $block ): bool {
        return array_key_exists( $block, self::map() );
    }

    /**
     * One block's options, normalised by whoever owns them. A block with no
     * options, or one given nothing usable, answers `[]` — "render as you
     * always did".
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise( string $block, array $raw ): array {
        $owner = self::map()[ $block ] ?? null;
        if ( $owner === null ) return [];

        return $owner::normalise( $raw );
    }

    /**
     * Option keys the block does not recognise, prefixed with the block so a
     * caller can say *where* the mistake is: `tests.definitons`.
     *
     * Options given for a block that accepts none are all unknown — that is a
     * request built against a different version of this report, and worth
     * refusing rather than ignoring.
     *
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    public static function unknownKeys( string $block, array $raw ): array {
        $owner = self::map()[ $block ] ?? null;

        if ( $owner === null ) {
            return array_map(
                static fn( $key ): string => $block . '.' . (string) $key,
                array_keys( $raw )
            );
        }

        return array_map(
            static fn( string $key ): string => $block . '.' . $key,
            $owner::unknownKeys( $raw )
        );
    }
}
