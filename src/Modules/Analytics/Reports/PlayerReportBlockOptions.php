<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerReportBlockOptions (#3989) — which player report block owns which
 * options. The player report's twin of `TeamMonthlyReportBlockOptions`: a
 * lookup, one line per block naming the class that owns its options, and no
 * knowledge here of what those options are.
 *
 * `ratings` is the first entry. Every other block answers "no options", which
 * is how the report behaved before this existed.
 */
final class PlayerReportBlockOptions {

    /**
     * Block key => class implementing BlockOptionsInterface.
     *
     * @return array<string, class-string<BlockOptionsInterface>>
     */
    private static function map(): array {
        return [
            PlayerReportBlock::RATINGS => RatingsBlockOptions::class,
        ];
    }

    /** Can this block be told anything beyond whether to appear? */
    public static function accepts( string $block ): bool {
        return array_key_exists( $block, self::map() );
    }

    /**
     * One block's options, normalised by whoever owns them. A block with no
     * options, or one given nothing usable, answers `[]`.
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
     * Option keys the block does not recognise, prefixed with the block:
     * `ratings.detial`. Options for a block that accepts none are all unknown.
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
