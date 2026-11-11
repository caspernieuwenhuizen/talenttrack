<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;

/**
 * BrandStyles — the academy's Branding colours and fonts, attached to
 * `tokens.css` on `.tt-root` (#4797) on the default design.
 *
 * Layer order: `tokens.css` declares every default; a theme sheet then
 * owns the whole design and Branding is not output (#2515); without a
 * theme, the Branding rule printed right after `tokens.css` overrides the
 * colours and fonts the academy actually chose.
 *
 * Always-on shared frontend concern, initialised by the Kernel.
 *
 * #0023 Sprint 1: extended to also emit semantic-color + font tokens
 * for the new Branding-tab fields and register a Google Fonts request
 * when the operator picked a curated family. (Theme inheritance was
 * removed in #1728 — canvas mode now gives total visual isolation, so
 * deferring styling to the host theme no longer makes sense.)
 */
class BrandStyles {

    /**
     * The values each Branding colour field shows when nothing was chosen
     * (`FrontendConfigurationView` and `ConfigurationPage`). The form
     * submits every colour on every save, so a stored value equal to its
     * form default says nothing about what the academy wanted.
     *
     * Only colours with a default of their own are listed. The focus ring
     * follows the primary colour when nothing was chosen (#4804), so its
     * form default (`#1e88e5`) is unset as well. The accent has no default
     * apart from the form's, so its `#1e88e5` is what a saved install
     * renders; treating it as unset would change every such install.
     */
    private const FORM_DEFAULTS = [
        'primary_color'   => '#0b3d2e',
        'secondary_color' => '#e8b624',
        'color_danger'    => '#b32d2e',
        'color_warning'   => '#dba617',
        'color_success'   => '#00a32a',
        'color_info'      => '#2271b1',
        'color_focus'     => '#1e88e5',
    ];

    /** The status colours, which some installs hold as a stored `#000000`. */
    private const STATUS_KEYS = [ 'color_danger', 'color_warning', 'color_success', 'color_info' ];

    /**
     * Each status's `-subtle` fill in tokens.css, which its text sits on
     * (#5108). Branding sets the status colour, not the fill, so its text
     * shade has to read on this one. Pinned to tokens.css by a test.
     */
    private const STATUS_SUBTLE = [
        'color_danger'  => '#fde2e2',
        'color_warning' => '#fdf3d8',
        'color_success' => '#dff5e1',
        'color_info'    => '#e6f0fa',
    ];

    /** Config key → token it sets on `.tt-root`. */
    private const COLOR_TOKENS = [
        'primary_color'   => '--tt-primary',
        'secondary_color' => '--tt-secondary',
        'color_accent'    => '--tt-accent',
        'color_danger'    => '--tt-danger',
        'color_warning'   => '--tt-warning',
        'color_success'   => '--tt-success',
        'color_info'      => '--tt-info',
        'color_focus'     => '--tt-focus-ring',
    ];

    public static function init( Container $container ): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'attachVars' ], 5 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueueFonts' ] );
    }

    /**
     * Whether a stored Branding colour counts as chosen (#4797). Empty, the
     * form's own default, or `#000000` for a status colour all count as
     * unset, so that colour falls back to `tokens.css` instead of turning a
     * site's alerts black.
     */
    public static function isChosen( string $key, string $value ): bool {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) return false;
        if ( isset( self::FORM_DEFAULTS[ $key ] ) && $value === self::FORM_DEFAULTS[ $key ] ) return false;
        if ( in_array( $key, self::STATUS_KEYS, true ) && $value === '#000000' ) return false;
        return true;
    }

    /** Is this config key one of the Branding colours? */
    public static function isColorKey( string $key ): bool {
        return isset( self::COLOR_TOKENS[ $key ] );
    }

    /**
     * #4803 — store a Branding colour only when the academy chose it.
     *
     * The form submits every colour on every save. A value that is not a
     * choice (empty, the form's default, `#000000` for a status colour)
     * removes the stored row instead, so the colour stays unset and falls
     * back to `tokens.css`, and TalentTrack can tell a chosen colour from a
     * default. Both save paths (the Configuration REST route and the
     * wp-admin form) write through here.
     */
    public static function saveColor( string $key, string $value ): void {
        if ( self::isChosen( $key, $value ) ) {
            \TT\Infrastructure\Query\QueryHelpers::set_config( $key, $value );
            return;
        }
        \TT\Infrastructure\Query\QueryHelpers::delete_config( $key );
    }

    /**
     * The Branding rule for `.tt-root`, or '' when the academy chose
     * nothing. Only the values it chose are written, so every other token
     * keeps its `tokens.css` default.
     */
    public static function css(): string {
        /** @var \TT\Infrastructure\Config\ConfigService $cfg */
        $cfg = \TT\Core\Kernel::instance()->container()->get( 'config' );

        // #4829 — a focus colour the academy set colours the focus ring, but
        // only when it reaches 3:1 against the page. Otherwise the ring
        // keeps following the primary (#4804), so keyboard focus never
        // becomes invisible; the form warns when such a colour is saved.
        $focus_set = (string) $cfg->get( 'color_focus', '' );
        $focus_own = self::isChosen( 'color_focus', $focus_set ) && self::isVisibleFocusColor( $focus_set );

        $decls = [];
        foreach ( self::COLOR_TOKENS as $key => $token ) {
            $value = (string) $cfg->get( $key, '' );
            if ( ! self::isChosen( $key, $value ) ) continue;
            // A focus colour too faint to see writes no token at all:
            // `--tt-focus-ring` is drawn from directly (frontend-eval-form.css).
            if ( $key === 'color_focus' && ! $focus_own ) continue;
            $decls[] = $token . ':' . esc_attr( $value );
            if ( in_array( $key, self::STATUS_KEYS, true ) ) {
                // #5108 — the status keeps every fill; printed as text it
                // takes a shade that reads on paper and on its subtle fill.
                $status_text = self::statusTextColor( $key, $value );
                if ( $status_text !== '' ) $decls[] = $token . '-text-color:' . esc_attr( $status_text );
            }
            if ( $key === 'primary_color' ) {
                $decls[] = '--tt-primary-rgb:' . esc_attr( self::hexToRgb( $value ) );
                // #4804 — the focus ring is drawn in the primary colour; a
                // primary too light to see on the page gets a darker shade.
                $focus = self::focusColor( $value );
                if ( $focus !== '' && ! $focus_own ) $decls[] = '--tt-focus-color:' . esc_attr( $focus );
            }
            if ( $key === 'secondary_color' ) {
                $decls[] = '--tt-secondary-rgb:' . esc_attr( self::hexToRgb( $value ) );
                // #5105 — the secondary keeps every fill; where it is printed
                // as text it takes a shade that reads on the page. Written as
                // the input tokens.css reads into `--tt-secondary-text`, as
                // `--tt-focus-color` is for the ring.
                $accent_text = self::accentTextColor( $value );
                if ( $accent_text !== '' ) $decls[] = '--tt-secondary-text-color:' . esc_attr( $accent_text );
            }
        }
        if ( $focus_own ) $decls[] = '--tt-focus-color:' . esc_attr( $focus_set );

        $resolved_display = BrandFonts::resolveFamily( (string) $cfg->get( 'font_display', '' ), BrandFonts::displayCatalogue() );
        $resolved_body    = BrandFonts::resolveFamily( (string) $cfg->get( 'font_body',    '' ), BrandFonts::bodyCatalogue() );
        if ( $resolved_display !== '' ) $decls[] = '--tt-font-display:' . esc_attr( $resolved_display );
        if ( $resolved_body    !== '' ) $decls[] = '--tt-font-body:' . esc_attr( $resolved_body );

        return $decls ? '.tt-root{' . implode( ';', $decls ) . '}' : '';
    }

    /**
     * #4797 — Branding is attached to `tokens.css` itself, on `.tt-root`,
     * so it prints right after the defaults it overrides and wins by
     * order at the same specificity. It used to be a `:root` block, which
     * the `.tt-root` defaults (a closer ancestor) beat for every token
     * `tokens.css` declares: on the default design only the accent and the
     * focus ring ever followed Branding.
     *
     * Registering the handle here is what lets the rule attach before the
     * shortcode enqueues the sheet; every entry point enqueues the same
     * handle with the same source.
     */
    public static function attachVars(): void {
        // #2515 — a theme owns the complete visual design. The club keeps its
        // identity (logo, name), but not the palette: emitting club colours
        // under a theme put a club-coloured brand header above a themed rail,
        // which is the exact clash the theme exists to prevent.
        if ( ThemePreference::hasThemeSheet() ) {
            return;
        }

        $css = self::css();
        if ( $css === '' ) {
            return;
        }

        if ( ! wp_style_is( 'tt-tokens', 'registered' ) ) {
            wp_register_style( 'tt-tokens', TT_PLUGIN_URL . 'assets/css/tokens.css', [], TT_VERSION );
        }
        wp_add_inline_style( 'tt-tokens', $css );
    }

    /**
     * Register Google Fonts when the operator picked curated families.
     * Skips the request entirely when both fields are System default
     * or Inherit-from-theme.
     */
    public static function enqueueFonts(): void {
        // #2515 — a theme owns the type as well as the colour, so the club's
        // font choice does not apply. Skipping the request outright also
        // spares the page a third-party fetch whose result nothing would use.
        if ( ThemePreference::hasThemeSheet() ) {
            return;
        }

        /** @var \TT\Infrastructure\Config\ConfigService $cfg */
        $cfg = \TT\Core\Kernel::instance()->container()->get( 'config' );
        $url = BrandFonts::googleFontsUrl(
            (string) $cfg->get( 'font_display', '' ),
            (string) $cfg->get( 'font_body',    '' )
        );
        if ( $url === '' ) return;
        wp_enqueue_style( 'tt-brand-fonts', $url, [], null );
    }

    /**
     * The darkest light background a focus ring is drawn on: the canvas
     * page background `#f4f5f7`. A ring that reaches 3:1 here also reaches
     * it on white and on the training scene's pitch (`#f3f7f3`).
     */
    private const FOCUS_BACKGROUND = [ 244, 245, 247 ];

    /** WCAG 2.2 non-text contrast for a focus indicator. */
    private const FOCUS_MIN_CONTRAST = 3.0;

    /**
     * The focus ring colour for a Branding primary (#4804): '' when the
     * primary itself reaches 3:1 against the page background, otherwise the
     * same hue with its lightness lowered until it does.
     */
    public static function focusColor( string $hex ): string {
        $rgb = self::parseHex( $hex );
        if ( $rgb === null ) return '';
        if ( self::contrast( $rgb, self::FOCUS_BACKGROUND ) >= self::FOCUS_MIN_CONTRAST ) return '';
        return self::darkenUntil( $rgb, self::FOCUS_MIN_CONTRAST );
    }

    /** WCAG 2.2 body-text contrast, for the accent printed as text (#5105). */
    private const TEXT_MIN_CONTRAST = 4.5;

    /**
     * The text-safe shade of a Branding secondary (#5105): the colour itself
     * when it reaches 4.5:1 against the page background, otherwise the same
     * hue darkened until it does. '' for a value that is not a colour.
     */
    public static function accentTextColor( string $hex ): string {
        $rgb = self::parseHex( $hex );
        if ( $rgb === null ) return '';
        if ( self::contrast( $rgb, self::FOCUS_BACKGROUND ) >= self::TEXT_MIN_CONTRAST ) {
            return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
        }
        return self::darkenUntil( $rgb, self::TEXT_MIN_CONTRAST );
    }

    /**
     * The text-safe shade of a Branding status colour (#5108): the colour
     * itself when it reaches 4.5:1 on the page background and on that
     * status's subtle fill, otherwise the same hue darkened until it does.
     * '' for a value that is not a colour or a key that is not a status.
     */
    public static function statusTextColor( string $key, string $hex ): string {
        $rgb = self::parseHex( $hex );
        if ( $rgb === null || ! isset( self::STATUS_SUBTLE[ $key ] ) ) return '';
        $subtle = self::parseHex( self::STATUS_SUBTLE[ $key ] );
        $extra  = $subtle === null ? [] : [ $subtle ];
        if ( self::readsOn( $rgb, self::TEXT_MIN_CONTRAST, $extra ) ) {
            return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
        }
        return self::darkenUntil( $rgb, self::TEXT_MIN_CONTRAST, $extra );
    }

    /**
     * Whether a colour reaches `$min` on the page background and on every
     * extra background.
     *
     * @param array{0:int,1:int,2:int} $rgb
     * @param list<array{0:int,1:int,2:int}> $extra
     */
    private static function readsOn( array $rgb, float $min, array $extra = [] ): bool {
        foreach ( array_merge( [ self::FOCUS_BACKGROUND ], $extra ) as $bg ) {
            if ( self::contrast( $rgb, $bg ) < $min ) return false;
        }
        return true;
    }

    /**
     * Lower the lightness in 2% steps until the colour reaches `$min`
     * against the page background and any extra background.
     *
     * @param array{0:int,1:int,2:int} $rgb
     * @param list<array{0:int,1:int,2:int}> $extra
     */
    private static function darkenUntil( array $rgb, float $min, array $extra = [] ): string {
        [ $h, $s, $l ] = self::rgbToHsl( $rgb );
        while ( $l > 0 ) {
            $l   = max( 0.0, $l - 0.02 );
            $rgb = self::hslToRgb( $h, $s, $l );
            if ( self::readsOn( $rgb, $min, $extra ) ) break;
        }
        return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
    }

    /**
     * Whether a colour reaches 3:1 against the page background, so it can
     * serve as the focus ring (#4829). An unreadable value cannot.
     */
    public static function isVisibleFocusColor( string $hex ): bool {
        $rgb = self::parseHex( $hex );
        return $rgb !== null && self::contrast( $rgb, self::FOCUS_BACKGROUND ) >= self::FOCUS_MIN_CONTRAST;
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private static function parseHex( string $hex ): ?array {
        $hex = ltrim( trim( $hex ), '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) return null;
        return [ (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) ];
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function luminance( array $rgb ): float {
        $lin = array_map( static function ( int $c ): float {
            $c = $c / 255;
            return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
        }, $rgb );
        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     */
    public static function contrast( array $a, array $b ): float {
        $la = self::luminance( $a );
        $lb = self::luminance( $b );
        return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     * @return array{0:float,1:float,2:float}
     */
    private static function rgbToHsl( array $rgb ): array {
        [ $r, $g, $b ] = [ $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255 ];
        $max = max( $r, $g, $b );
        $min = min( $r, $g, $b );
        $l   = ( $max + $min ) / 2;
        if ( $max === $min ) return [ 0.0, 0.0, $l ];
        $d = $max - $min;
        $s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
        if ( $max === $r )      $h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
        elseif ( $max === $g )  $h = ( $b - $r ) / $d + 2;
        else                    $h = ( $r - $g ) / $d + 4;
        return [ $h / 6, $s, $l ];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function hslToRgb( float $h, float $s, float $l ): array {
        if ( $s == 0.0 ) {
            $v = (int) round( $l * 255 );
            return [ $v, $v, $v ];
        }
        $q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = static function ( float $t ) use ( $p, $q ): float {
            if ( $t < 0 ) $t += 1;
            if ( $t > 1 ) $t -= 1;
            if ( $t < 1 / 6 ) return $p + ( $q - $p ) * 6 * $t;
            if ( $t < 1 / 2 ) return $q;
            if ( $t < 2 / 3 ) return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
            return $p;
        };
        return [ (int) round( $f( $h + 1 / 3 ) * 255 ), (int) round( $f( $h ) * 255 ), (int) round( $f( $h - 1 / 3 ) * 255 ) ];
    }

    private static function hexToRgb( string $hex ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return '11,61,46';
        }
        return hexdec( substr( $hex, 0, 2 ) )
            . ',' . hexdec( substr( $hex, 2, 2 ) )
            . ',' . hexdec( substr( $hex, 4, 2 ) );
    }
}
