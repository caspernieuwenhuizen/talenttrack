<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * StackTable (#4810) — the markup half of the stacked-label table primitive
 * in `assets/css/components/stack-table.css`.
 *
 * On a phone the primitive changes `display` on the table elements, which
 * in some engines (WebKit / VoiceOver on iOS) drops their implicit table
 * roles. Every renderer whose table uses the primitive writes its roles and
 * labels through `attrs()`, so they live in one place:
 *
 *     echo '<table class="tt-pd-table ' . StackTable::classes( StackTable::TO_480 ) . '"' . StackTable::attrs( 'table' ) . '>';
 *     echo '<th' . StackTable::attrs( 'columnheader' ) . '>' . esc_html( $label ) . '</th>';
 *     echo '<tr' . StackTable::attrs( 'row' ) . '>';
 *     echo '<td' . StackTable::attrs( 'cell', $label ) . '>' . $value . '</td>';
 */
final class StackTable {

    /** A table from 480px up. */
    public const TO_480 = 480;

    /** A table from 768px up. */
    public const TO_768 = 768;

    /** No breakpoint: stacked at every width. */
    public const ALWAYS = 0;

    private const PARTS = [ 'table', 'rowgroup', 'row', 'columnheader', 'cell' ];

    /**
     * The table's classes: the primitive and its breakpoint modifier.
     */
    public static function classes( int $to = self::TO_480 ): string {
        if ( $to === self::TO_480 || $to === self::TO_768 ) {
            return 'tt-stack-table tt-stack-table--to-' . $to;
        }
        return 'tt-stack-table';
    }

    /**
     * The escaped attributes for one part, with a leading space: the role,
     * and for a cell its column label as `data-label` (an empty label means
     * the cell shows its value alone). An unknown part yields ''.
     */
    public static function attrs( string $part, string $label = '' ): string {
        if ( ! in_array( $part, self::PARTS, true ) ) return '';
        $out = ' role="' . esc_attr( $part ) . '"';
        if ( $part === 'cell' ) {
            $out .= ' data-label="' . esc_attr( $label ) . '"';
        }
        return $out;
    }
}
