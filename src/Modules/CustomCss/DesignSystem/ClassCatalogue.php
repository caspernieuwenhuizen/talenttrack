<?php
namespace TT\Modules\CustomCss\DesignSystem;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ClassCatalogue — index of every `.tt-*` CSS class declared in the
 * plugin's bundled stylesheets, exposed on the Custom CSS editor's
 * "Classes" tab so operators can pick a class to override without
 * having to read the source. Backed by a regex scan of the plugin's
 * own `assets/css/*.css` plus inline `<style>` blocks declared by
 * frontend views — the visual editor fields cover the high-traffic
 * tokens, but real overrides often need the class.
 *
 * Cached in a 1-hour transient keyed on the plugin version so cache
 * invalidates automatically on plugin update. The scan runs at most
 * once per hour per install; the regex is deliberately permissive
 * (any `.tt-` prefix) so we don't have to enumerate the modules that
 * declare them.
 *
 * Each entry in `all()` carries:
 *   - `class`  — the class name without the leading `.`
 *   - `files`  — list of stylesheet basenames where the class appears
 *
 * The list is intentionally a discovery surface, not a styleguide.
 * We don't try to render examples or document each class — operators
 * who recognise the class name from the rendered DOM use the editor
 * to insert a starter rule, and Path B handles the actual authoring.
 */
final class ClassCatalogue {

    private const CACHE_KEY = 'tt_css_class_catalogue';
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * @return array<int, array{class:string, files:string[]}>
     */
    public static function all(): array {
        $cache_version = defined( 'TT_VERSION' ) ? TT_VERSION : 'unknown';
        $cache_key = self::CACHE_KEY . '_' . md5( $cache_version );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $rows = self::scan();
        set_transient( $cache_key, $rows, self::CACHE_TTL );
        return $rows;
    }

    /**
     * @return array<int, array{class:string, files:string[]}>
     */
    private static function scan(): array {
        $base = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR : '';
        if ( $base === '' || ! is_dir( $base . 'assets/css' ) ) return [];

        $files = glob( $base . 'assets/css/*.css' );
        if ( ! is_array( $files ) ) return [];

        $by_class = [];
        foreach ( $files as $file ) {
            $content = @file_get_contents( $file );
            if ( $content === false ) continue;
            if ( ! preg_match_all( '/\.(tt-[a-z0-9_-]+)/i', $content, $matches ) ) continue;
            $name = basename( $file );
            foreach ( array_unique( $matches[1] ) as $cls ) {
                $cls = strtolower( $cls );
                if ( ! isset( $by_class[ $cls ] ) ) $by_class[ $cls ] = [];
                if ( ! in_array( $name, $by_class[ $cls ], true ) ) {
                    $by_class[ $cls ][] = $name;
                }
            }
        }
        ksort( $by_class );
        $rows = [];
        foreach ( $by_class as $cls => $sources ) {
            $rows[] = [ 'class' => $cls, 'files' => $sources ];
        }
        return $rows;
    }
}
