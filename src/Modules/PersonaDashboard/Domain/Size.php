<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Size — t-shirt sizing for the 12-column bento grid.
 *
 *   S  = 3 cols  × 1 row   (action card, small KPI, navigation tile)
 *   M  = 6 cols  × 1 row   (info card, mini list, KPI card)
 *   L  = 9 cols  × 2 rows  (task list panel, evaluations rail)
 *   XL = 12 cols × 1 row   (heroes, KPI strips, data tables)
 */
final class Size {

    public const S  = 'S';
    public const M  = 'M';
    public const L  = 'L';
    public const XL = 'XL';

    private const COLS = [ self::S => 3, self::M => 6, self::L => 9, self::XL => 12 ];
    private const ROWS = [ self::S => 1, self::M => 1, self::L => 2, self::XL => 1 ];

    public static function isValid( string $size ): bool {
        return isset( self::COLS[ $size ] );
    }

    public static function cols( string $size ): int {
        return self::COLS[ $size ] ?? 0;
    }

    public static function defaultRows( string $size ): int {
        return self::ROWS[ $size ] ?? 1;
    }

    /** @return list<string> */
    public static function all(): array {
        return [ self::S, self::M, self::L, self::XL ];
    }
}
