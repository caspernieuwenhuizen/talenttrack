<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * LookupColourChip (#3559) — a chip painted in a lookup row's own colour,
 * with an ink the viewer can actually read.
 *
 * Several vocabularies carry `meta.color` so an operator can decide what a
 * value looks like — opponent levels are amber for "stronger", red for
 * "much stronger", and so on. A surface that paints with that colour and
 * then assumes white text is making a decision the operator did not: the
 * seeded amber `#f59e0b` against white is 1.8:1, nowhere near the 4.5:1
 * body-text floor, and a coach glancing at a fixture list reads nothing.
 *
 * So the ink is derived from the background rather than fixed. Both
 * candidates are measured by WCAG relative luminance and the better one
 * wins, which holds for any colour an operator picks later — including the
 * ones nobody thought to check.
 *
 * The colour itself is passed to CSS as custom properties rather than as
 * declarations, so the sheet keeps the shape, the spacing and the fallback
 * palette and this only supplies the two values that cannot be known until
 * the row is read.
 */
final class LookupColourChip {

    /** Ink for a light background. */
    public const INK_DARK = '#111827';

    /** Ink for a dark one. */
    public const INK_LIGHT = '#ffffff';

    /**
     * `[ stored_name => #rrggbb ]` for one vocabulary.
     *
     * A row with no colour, or with something that is not a hex colour, is
     * absent — so a caller writes `$colours[ $key ] ?? ''` and falls back
     * to the sheet's own palette.
     *
     * @return array<string,string>
     */
    public static function colours( string $lookup_type ): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( $lookup_type ) as $row ) {
            $name = (string) ( $row->name ?? '' );
            if ( $name === '' ) continue;

            $meta  = QueryHelpers::lookup_meta( $row );
            $hex   = self::normaliseHex( (string) ( $meta['color'] ?? '' ) );
            if ( $hex !== '' ) $out[ $name ] = $hex;
        }
        return $out;
    }

    /**
     * The inline custom properties for one chip, or '' when there is no
     * colour to apply and the sheet's own palette should stand.
     */
    public static function styleFor( string $hex ): string {
        $hex = self::normaliseHex( $hex );
        if ( $hex === '' ) return '';

        return '--tt-chip-bg:' . $hex . ';--tt-chip-ink:' . self::ink( $hex ) . ';';
    }

    /**
     * Whichever of the two inks reads better on this background.
     *
     * Ties go to the dark ink: on a mid-tone it is the one that still
     * works when a browser or a user stylesheet lightens the background.
     */
    public static function ink( string $hex ): string {
        $hex = self::normaliseHex( $hex );
        if ( $hex === '' ) return self::INK_DARK;

        return self::contrast( $hex, self::INK_LIGHT ) > self::contrast( $hex, self::INK_DARK )
            ? self::INK_LIGHT
            : self::INK_DARK;
    }

    /** WCAG 2.1 contrast ratio between two hex colours, 1.0 … 21.0. */
    public static function contrast( string $a, string $b ): float {
        $la = self::luminance( $a );
        $lb = self::luminance( $b );
        $hi = max( $la, $lb );
        $lo = min( $la, $lb );

        return ( $hi + 0.05 ) / ( $lo + 0.05 );
    }

    // Internals

    /** WCAG 2.1 relative luminance. */
    private static function luminance( string $hex ): float {
        $hex = self::normaliseHex( $hex );
        if ( $hex === '' ) return 0.0;

        $channels = [
            hexdec( substr( $hex, 1, 2 ) ),
            hexdec( substr( $hex, 3, 2 ) ),
            hexdec( substr( $hex, 5, 2 ) ),
        ];

        $linear = [];
        foreach ( $channels as $value ) {
            $c = ( (float) $value ) / 255.0;
            $linear[] = $c <= 0.03928
                ? $c / 12.92
                : pow( ( $c + 0.055 ) / 1.055, 2.4 );
        }

        return ( 0.2126 * $linear[0] ) + ( 0.7152 * $linear[1] ) + ( 0.0722 * $linear[2] );
    }

    /** `#abc` and `abcdef` both come back as `#aabbcc` / `#abcdef`; junk as ''. */
    private static function normaliseHex( string $raw ): string {
        $raw = strtolower( trim( $raw ) );
        $raw = ltrim( $raw, '#' );

        if ( preg_match( '/^[0-9a-f]{3}$/', $raw ) === 1 ) {
            $raw = $raw[0] . $raw[0] . $raw[1] . $raw[1] . $raw[2] . $raw[2];
        }
        if ( preg_match( '/^[0-9a-f]{6}$/', $raw ) !== 1 ) return '';

        return '#' . $raw;
    }
}
