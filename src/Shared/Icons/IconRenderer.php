<?php
namespace TT\Shared\Icons;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * IconRenderer — inline-SVG helper for the plugin's icon set.
 *
 * Icons live as individual SVGs in `assets/icons/<name>.svg`, hand-authored
 * as solid silhouettes with `fill="currentColor"` so wrapper CSS drives
 * their color. Callers reference an icon by name (`teams`, `players`, etc.);
 * this class loads the file once per request and inlines it into HTML.
 *
 * Why inline SVG and not <img>:
 *   - <img src="..."> drops `currentColor`; we'd lose tile re-coloring.
 *   - SVG sprite needs a build step we don't have.
 *
 * See spec #0034 for the shaped scope.
 */
class IconRenderer {

    /** @var array<string,string|null> filename => svg markup (null = miss) */
    private static $cache = [];

    /** @var array<string,bool> filename => whether we already error_log'd a miss this request */
    private static $logged_misses = [];

    public static function dir(): string {
        return TT_PLUGIN_DIR . 'assets/icons/';
    }

    public static function exists( string $name ): bool {
        if ( ! self::isValidName( $name ) ) {
            return false;
        }
        return is_file( self::dir() . $name . '.svg' );
    }

    /**
     * Inline the named SVG with optional attributes merged onto the root <svg>.
     *
     * Default attributes: class="tt-icon", width=24, height=24, aria-hidden=true.
     * Returns the empty string when the icon is missing (callers gracefully
     * render no icon); first miss per name per request is logged via
     * error_log() so the bug is loud and findable.
     *
     * @param string $name  icon basename (no extension), must match /^[a-z0-9-]+$/
     * @param array<string,string|int|bool> $attrs attributes merged onto the <svg> root
     */
    public static function render( string $name, array $attrs = [] ): string {
        $svg = self::load( $name );
        if ( $svg === null ) {
            return '';
        }

        $defaults = [
            'class'       => 'tt-icon',
            'width'       => 24,
            'height'      => 24,
            'aria-hidden' => 'true',
            'focusable'   => 'false',
        ];
        $merged = array_merge( $defaults, $attrs );

        $attr_str = '';
        foreach ( $merged as $k => $v ) {
            if ( $v === false || $v === null ) continue;
            if ( $v === true ) $v = 'true';
            $attr_str .= ' ' . $k . '="' . esc_attr( (string) $v ) . '"';
        }

        // Inject the merged attributes into the opening <svg> tag.
        // Source SVGs have a single <svg ...> opening with viewBox+fill etc.
        // We strip any existing class/width/height/aria-hidden/focusable
        // before injecting so caller overrides win.
        $svg = preg_replace_callback(
            '/<svg\b([^>]*)>/i',
            function ( $m ) use ( $attr_str, $merged ) {
                $existing = $m[1];
                foreach ( array_keys( $merged ) as $k ) {
                    $existing = preg_replace( '/\s+' . preg_quote( $k, '/' ) . '="[^"]*"/i', '', $existing );
                }
                return '<svg' . $existing . $attr_str . '>';
            },
            $svg,
            1
        );

        return $svg;
    }

    private static function load( string $name ): ?string {
        if ( ! self::isValidName( $name ) ) {
            self::logMissOnce( $name );
            return null;
        }
        if ( array_key_exists( $name, self::$cache ) ) {
            return self::$cache[ $name ];
        }
        $path = self::dir() . $name . '.svg';
        if ( ! is_file( $path ) ) {
            self::$cache[ $name ] = null;
            self::logMissOnce( $name );
            return null;
        }
        $contents = file_get_contents( $path );
        self::$cache[ $name ] = ( $contents === false ) ? null : trim( $contents );
        if ( self::$cache[ $name ] === null ) {
            self::logMissOnce( $name );
        }
        return self::$cache[ $name ];
    }

    private static function isValidName( string $name ): bool {
        return $name !== '' && (bool) preg_match( '/^[a-z0-9-]+$/', $name );
    }

    private static function logMissOnce( string $name ): void {
        if ( isset( self::$logged_misses[ $name ] ) ) return;
        self::$logged_misses[ $name ] = true;
        error_log( '[TalentTrack] IconRenderer: icon not found: ' . $name );
    }
}
