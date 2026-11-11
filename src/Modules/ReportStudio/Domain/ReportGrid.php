<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Util\GridReadingOrder;

/**
 * ReportGrid (#4962, epic #4564) — where a block sits in a report laid out as
 * a grid: `x` and `w` on 12 columns, `y` and `h` in rows, whole cells only.
 *
 * The canvas avoids every placement these rules refuse, and a REST client
 * gets the same refusal on save: a block lies inside the grid, no two blocks
 * share a cell. The slot model is the persona dashboard's (`WidgetSlot`:
 * column 0-11, row 0 and down, at most four rows tall); a phone stacks the
 * blocks in reading order (`GridReadingOrder`), the same rule.
 *
 * A grid is for staff on screen. It is never printed, mailed or shared with
 * a family: those use Rows (`printable()`).
 *
 * @phpstan-type Place array{x:int, y:int, w:int, h:int}
 */
final class ReportGrid {

    public const COLUMNS = 12;

    /** The tallest block, in rows. */
    public const MAX_HEIGHT = 4;

    /** The rows a grid has: room for every block a dashboard holds at the tallest. */
    public const ROWS = ReportDefinition::MAX_DASHBOARD_BLOCKS * self::MAX_HEIGHT;

    /** The size a block gets when it is placed without one. */
    public const DEFAULT_WIDTH  = 6;
    public const DEFAULT_HEIGHT = 2;

    public const FIELDS = [ 'x', 'y', 'w', 'h' ];

    /**
     * One block's place as sent, or why it cannot be stored.
     *
     * @return Place|string the place, or the reason
     */
    public static function place( mixed $raw ): array|string {
        if ( ! is_array( $raw ) ) return __( 'A block\'s place in the grid is an object with x, y, w and h.', 'talenttrack' );
        $extra = array_values( array_diff( array_map( 'strval', array_keys( $raw ) ), self::FIELDS ) );
        if ( $extra !== [] ) {
            /* translators: %s: comma-separated field names */
            return sprintf( __( 'A place in the grid does not accept: %s.', 'talenttrack' ), implode( ', ', $extra ) );
        }
        $out = [];
        foreach ( self::FIELDS as $field ) {
            $value = $raw[ $field ] ?? null;
            // Whole cells only: the grid snaps, so 2.5 columns is not a place.
            if ( ! is_int( $value ) ) return __( 'A place in the grid is whole cells: x, y, w and h are whole numbers.', 'talenttrack' );
            $out[ $field ] = $value;
        }
        if ( $out['x'] < 0 || $out['w'] < 1 || $out['x'] + $out['w'] > self::COLUMNS ) {
            /* translators: %d: number of columns */
            return sprintf( __( 'A block lies inside the grid\'s %d columns.', 'talenttrack' ), self::COLUMNS );
        }
        if ( $out['h'] < 1 || $out['h'] > self::MAX_HEIGHT ) {
            /* translators: %d: the most rows a block may span */
            return sprintf( __( 'A block is one to %d rows tall.', 'talenttrack' ), self::MAX_HEIGHT );
        }
        if ( $out['y'] < 0 || $out['y'] + $out['h'] > self::ROWS ) {
            /* translators: %d: number of rows */
            return sprintf( __( 'A block lies inside the grid\'s %d rows.', 'talenttrack' ), self::ROWS );
        }
        return [ 'x' => $out['x'], 'y' => $out['y'], 'w' => $out['w'], 'h' => $out['h'] ];
    }

    /**
     * Do two places share a cell.
     *
     * @param Place $a
     * @param Place $b
     */
    public static function overlaps( array $a, array $b ): bool {
        return $a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w']
            && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];
    }

    /**
     * The first block that shares a cell with an earlier one, or null.
     *
     * @param list<Place> $places
     */
    public static function firstOverlap( array $places ): ?int {
        foreach ( $places as $i => $place ) {
            for ( $j = 0; $j < $i; $j++ ) {
                if ( self::overlaps( $places[ $j ], $place ) ) return $i;
            }
        }
        return null;
    }

    /**
     * The first free place of this size, top left first, or null when the
     * grid has no room. A stored block without a place (saved before it was
     * a grid, or written by hand) is read at the first free place.
     *
     * @param list<Place> $taken
     * @return Place|null
     */
    public static function freePlace( array $taken, int $w = self::DEFAULT_WIDTH, int $h = self::DEFAULT_HEIGHT ): ?array {
        $w = max( 1, min( self::COLUMNS, $w ) );
        $h = max( 1, min( self::MAX_HEIGHT, $h ) );
        for ( $y = 0; $y + $h <= self::ROWS; $y++ ) {
            for ( $x = 0; $x + $w <= self::COLUMNS; $x++ ) {
                $candidate = [ 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h ];
                $free      = true;
                foreach ( $taken as $other ) {
                    if ( self::overlaps( $other, $candidate ) ) { $free = false; break; }
                }
                if ( $free ) return $candidate;
            }
        }
        return null;
    }

    /**
     * Placed blocks in the order a phone stacks them: by row, then by column.
     *
     * @template B
     * @param list<array{place:Place, block:B}> $placed
     * @return list<array{place:Place, block:B}>
     */
    public static function readingOrder( array $placed ): array {
        return GridReadingOrder::sort(
            $placed,
            static fn( array $e ): int => $e['place']['x'],
            static fn( array $e ): int => $e['place']['y']
        );
    }

    /** May a report in this layout be printed, mailed or frozen. */
    public static function printable( string $layout ): bool {
        return $layout !== ReportStartRules::LAYOUT_GRID;
    }

    /** Why a grid is not printed, in the words every refusal uses. */
    public static function notPrintedReason(): string {
        return __( 'A grid is laid out for staff screens and is not printed. Build the report in rows to print it, mail it or freeze it.', 'talenttrack' );
    }
}
