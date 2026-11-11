<?php
namespace TT\Shared\Util;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GridReadingOrder (#4962) — the order a phone stacks a grid in: top row
 * first, left to right within a row. One rule for every grid that collapses
 * to one column, so the persona dashboard and a Report Studio grid stack the
 * same way. The sort is stable: two items in the same cell keep their order.
 */
final class GridReadingOrder {

    /**
     * @template T
     * @param list<T>             $items
     * @param callable(T): int    $x the item's column start
     * @param callable(T): int    $y the item's row start
     * @return list<T>
     */
    public static function sort( array $items, callable $x, callable $y ): array {
        $keyed = [];
        foreach ( $items as $i => $item ) {
            $keyed[] = [ $y( $item ), $x( $item ), $i, $item ];
        }
        usort( $keyed, static function ( array $a, array $b ): int {
            return [ $a[0], $a[1], $a[2] ] <=> [ $b[0], $b[1], $b[2] ];
        } );
        $out = [];
        foreach ( $keyed as $row ) $out[] = $row[3];
        return $out;
    }
}
