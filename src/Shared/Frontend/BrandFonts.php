<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BrandFonts — curated Google Fonts list for the Branding tab.
 *
 * #0023 Sprint 1. Carved out of `BrandStyles` so the font catalogue
 * has its own home as it grows. Two separate sets:
 *
 *   - Display fonts: condensed / sporty families that work on
 *     headings, tile titles, player card numbers.
 *   - Body fonts: clean sans-serifs (plus a couple of serifs) for
 *     paragraph text, tables, form labels.
 *
 * Plus two non-Google sentinel values that surface at the top of
 * each dropdown:
 *
 *   - `system`   — empty value, no Google Fonts request, falls
 *                  through to TalentTrack's default font stack.
 *   - `inherit`  — only meaningful when the inherit-theme toggle is
 *                  ON; otherwise behaves like `system`.
 */
class BrandFonts {

    public const SYSTEM_DEFAULT  = '';
    public const INHERIT         = '__inherit__';

    /** @var string[] CSS family names ordered as the dropdown displays them. */
    private const DISPLAY = [
        'Barlow Condensed',
        'Oswald',
        'Bebas Neue',
        'Anton',
        'Saira Condensed',
        'Fjalla One',
        'Archivo Black',
        'Teko',
        'Big Shoulders Display',
        'Russo One',
    ];

    /** @var string[] */
    private const BODY = [
        'Inter',
        'Manrope',
        'Plus Jakarta Sans',
        'DM Sans',
        'Work Sans',
        'IBM Plex Sans',
        'Source Sans 3',
        'Nunito Sans',
        'Outfit',
        'Sora',
        'Merriweather',
        'Source Serif 4',
    ];

    /**
     * Weights to request from Google Fonts. Match the existing player
     * card weight set so we don't duplicate.
     *
     * @var string[]
     */
    private const DISPLAY_WEIGHTS = [ '600', '700', '800' ];
    private const BODY_WEIGHTS    = [ '400', '600', '700' ];

    /**
     * @return array<string, string> value => label (translated).
     */
    public static function displayOptions(): array {
        return self::buildOptions( self::DISPLAY );
    }

    /**
     * @return array<string, string>
     */
    public static function bodyOptions(): array {
        return self::buildOptions( self::BODY );
    }

    /**
     * Build a Google Fonts request URL covering both chosen families.
     * Returns an empty string when neither family needs a Google
     * Fonts request (both system / inherit / unset).
     */
    public static function googleFontsUrl( string $display, string $body ): string {
        $families = [];
        if ( self::isGoogleFamily( $display, self::DISPLAY ) ) {
            $families[] = rawurlencode( $display ) . ':wght@' . implode( ';', self::DISPLAY_WEIGHTS );
        }
        if ( self::isGoogleFamily( $body, self::BODY ) ) {
            $families[] = rawurlencode( $body ) . ':wght@' . implode( ';', self::BODY_WEIGHTS );
        }
        if ( ! $families ) return '';

        $query = '?family=' . implode( '&family=', $families ) . '&display=swap';
        return 'https://fonts.googleapis.com/css2' . $query;
    }

    /**
     * Resolve a stored font key to the CSS `font-family` value to
     * emit. `system` returns empty (so the token isn't emitted at all
     * and the default stack wins). `inherit` always returns empty —
     * the body class is what actually triggers inheritance behavior.
     */
    public static function resolveFamily( string $value, array $catalogue ): string {
        if ( $value === self::SYSTEM_DEFAULT || $value === self::INHERIT ) return '';
        if ( ! in_array( $value, $catalogue, true ) ) return '';
        // Quote names with spaces — required for CSS font-family.
        return strpos( $value, ' ' ) !== false ? "'{$value}'" : $value;
    }

    /**
     * @return string[]
     */
    public static function displayCatalogue(): array { return self::DISPLAY; }

    /**
     * @return string[]
     */
    public static function bodyCatalogue(): array { return self::BODY; }

    private static function buildOptions( array $catalogue ): array {
        $opts = [
            self::SYSTEM_DEFAULT => __( '(System default)', 'talenttrack' ),
            self::INHERIT        => __( '(Inherit from theme)', 'talenttrack' ),
        ];
        foreach ( $catalogue as $name ) {
            $opts[ $name ] = $name;
        }
        return $opts;
    }

    private static function isGoogleFamily( string $value, array $catalogue ): bool {
        return $value !== self::SYSTEM_DEFAULT
            && $value !== self::INHERIT
            && in_array( $value, $catalogue, true );
    }
}
