<?php
namespace TT\Modules\Measurements\Units;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UnitRow (#3273) — one entry in the unit registry.
 *
 * A typed value object rather than the `stdClass` a `$wpdb` row arrives as.
 * The registry is read on nearly every measurement surface, and a factor
 * silently arriving as a string, or a dimension misspelled at a call site, is
 * the class of mistake that produced this issue in the first place — so the
 * conversion from row to object happens once, here, where it can be checked.
 */
final class UnitRow {

    public function __construct(
        public readonly int $id,
        public readonly string $symbol,
        public readonly string $dimension,
        public readonly float $factor_to_base,
        public readonly int $display_precision
    ) {}

    /**
     * Build from a database row, normalising as it goes: an unknown dimension
     * becomes `dimensionless` and a non-positive factor becomes 1, because a
     * zero or negative factor would divide a real measurement into nonsense.
     */
    public static function fromRow( object $row ): self {
        $factor = (float) ( $row->factor_to_base ?? 1 );

        return new self(
            (int) ( $row->id ?? 0 ),
            (string) ( $row->symbol ?? '' ),
            Dimensions::safe( (string) ( $row->dimension ?? Dimensions::DIMENSIONLESS ) ),
            $factor > 0 ? $factor : 1.0,
            (int) ( $row->display_precision ?? 2 )
        );
    }
}
