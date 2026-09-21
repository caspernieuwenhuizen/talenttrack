<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RatingsBlockOptions (#3989) — how much of the player report's evaluations
 * section to show.
 *
 * One option, `detail`:
 *
 * - `main` — the per-category table lists the main categories only. The
 *   default, and what the report always did.
 * - `sub` — each main category carries the subcategories rated under it,
 *   indented beneath it on screen and on paper.
 *
 * The default is recorded as absence, as on the team report's blocks (#3515):
 * a bag holding only the default would make two compositions that render the
 * same report differ, and a saved view would stop reporting itself active.
 *
 * `sub` is a request, not a promise. When nothing in the window was rated at
 * subcategory level the report renders main categories, and the panel does
 * not offer the choice.
 */
final class RatingsBlockOptions implements BlockOptionsInterface {

    public const DETAIL = 'detail';

    public const MAIN = 'main';
    public const SUB  = 'sub';

    /** @var list<string> */
    public const DETAILS = [ self::MAIN, self::SUB ];

    /**
     * Option key => what it means when nothing is recorded.
     *
     * @var array<string,string>
     */
    public const DEFAULTS = [
        self::DETAIL => self::MAIN,
    ];

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise( array $raw ): array {
        $detail = $raw[ self::DETAIL ] ?? null;
        if ( ! is_string( $detail ) ) return [];

        $detail = strtolower( trim( $detail ) );
        if ( ! in_array( $detail, self::DETAILS, true ) || $detail === self::DEFAULTS[ self::DETAIL ] ) return [];

        return [ self::DETAIL => $detail ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    public static function unknownKeys( array $raw ): array {
        return array_values( array_filter(
            array_map( 'strval', array_keys( $raw ) ),
            static fn( string $key ): bool => ! array_key_exists( $key, self::DEFAULTS )
        ) );
    }

    /**
     * The detail asked for, with anything unusable read as the default.
     *
     * @param array<string,mixed> $options
     */
    public static function detail( array $options ): string {
        return self::normalise( $options )[ self::DETAIL ] ?? self::MAIN;
    }

    /**
     * The panel's two choices, in the order it offers them.
     *
     * @return array<string,string>
     */
    public static function labels(): array {
        return [
            self::MAIN => _x( 'Main categories', 'player report evaluations option', 'talenttrack' ),
            self::SUB  => _x( 'With subcategories', 'player report evaluations option', 'talenttrack' ),
        ];
    }
}
