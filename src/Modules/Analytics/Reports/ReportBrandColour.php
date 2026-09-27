<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\ThemePreference;

/**
 * ReportBrandColour (#4096, epic #4094) — the club colour a printed report's
 * section headers carry, and the tint behind them.
 *
 * On screen the headers read `--tt-primary`, which `BrandStyles` emits from
 * the club's Branding colour, or which a theme sheet declares instead when a
 * theme is on. A PDF reads no stylesheet, so this resolves the same value in
 * PHP from the same two sources: the theme's primary when a theme is active,
 * the configured `primary_color` otherwise, the shipped green when neither is
 * a usable colour.
 *
 * The number in the header is drawn in the colour only when it stays legible
 * on the tint (WCAG 4.5:1); a pale club colour gets ink instead.
 */
final class ReportBrandColour {

    /** The shipped primary, and the fallback. */
    public const FALLBACK = '#0b3d2e';

    /** Body ink, for a number the club colour would leave unreadable. */
    public const INK = '#0e1a14';

    /** Share of the club colour in the header band's tint. */
    private const TINT_SHARE = 0.09;

    /**
     * Each theme sheet's `--tt-primary`. Mirrors `assets/css/theme-*.css`:
     * a theme owns its palette (#2515), so the configured colour does not
     * apply while one is on.
     */
    private const THEME_PRIMARY = [
        ThemePreference::FEDERATION  => '#1b3660',
        ThemePreference::LEON_HUTTEN => '#0262a1',
    ];

    /** The club colour for whoever the report is rendered for. */
    public static function primary( int $user_id = 0 ): string {
        $theme = ThemePreference::resolve( $user_id );
        $hex   = self::THEME_PRIMARY[ $theme ] ?? (string) QueryHelpers::get_config( 'primary_color', self::FALLBACK );
        return self::normalise( $hex ) ?? self::FALLBACK;
    }

    /** The header band's background: the club colour, heavily lightened. */
    public static function tint( string $primary ): string {
        $rgb = self::rgb( $primary );
        $mix = array_map( static fn( int $c ): int => (int) round( 255 - ( 255 - $c ) * self::TINT_SHARE ), $rgb );
        return sprintf( '#%02x%02x%02x', $mix[0], $mix[1], $mix[2] );
    }

    /** Is the club colour legible as text on its own tint? */
    public static function readableOnTint( string $primary ): bool {
        return self::contrast( $primary, self::tint( $primary ) ) >= 4.5;
    }

    /** The colour the header's number is drawn in. */
    public static function numberColour( string $primary ): string {
        return self::readableOnTint( $primary ) ? $primary : self::INK;
    }

    /** WCAG contrast ratio between two colours. */
    public static function contrast( string $a, string $b ): float {
        $la = self::luminance( $a );
        $lb = self::luminance( $b );
        return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
    }

    /** `#abc` or `#aabbcc`, lower-cased to six digits; null for anything else. */
    private static function normalise( string $hex ): ?string {
        $hex = strtolower( trim( $hex ) );
        if ( preg_match( '/^#([0-9a-f]{3})$/', $hex, $m ) ) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        return preg_match( '/^#[0-9a-f]{6}$/', $hex ) ? $hex : null;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb( string $hex ): array {
        $hex = self::normalise( $hex ) ?? self::FALLBACK;
        return [ (int) hexdec( substr( $hex, 1, 2 ) ), (int) hexdec( substr( $hex, 3, 2 ) ), (int) hexdec( substr( $hex, 5, 2 ) ) ];
    }

    private static function luminance( string $hex ): float {
        $out = 0.0;
        foreach ( self::rgb( $hex ) as $i => $c ) {
            $s   = $c / 255;
            $lin = $s <= 0.03928 ? $s / 12.92 : ( ( $s + 0.055 ) / 1.055 ) ** 2.4;
            $out += [ 0.2126, 0.7152, 0.0722 ][ $i ] * $lin;
        }
        return $out;
    }
}
