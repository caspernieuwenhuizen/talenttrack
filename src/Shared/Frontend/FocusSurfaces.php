<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FocusSurfaces (#2933) — which surfaces own the thumb zone.
 *
 * The list is a file of decisions, `config/focus_surfaces.php`, each entry
 * carrying the reason it was given. This class is the only thing that
 * reads it, so the policy has one door: a future SaaS front end replaces
 * this resolver rather than hunting for `require` calls across views.
 *
 * An absent or unreadable file is not fatal. Every slug then reports
 * false, which is precisely today's behaviour — the shell bar renders
 * everywhere. Failing open is right here: the cost of a wrongly-rendered
 * bar is a cramped screen, the cost of wrongly suppressing it is a user
 * with no navigation.
 */
final class FocusSurfaces {

    /** @var array<string, string>|null resolved once per request */
    private static ?array $map = null;

    /**
     * True when this surface renders its own controls in the thumb zone,
     * so the shell must not put its bar underneath them.
     */
    public static function claims( string $slug ): bool {
        $slug = trim( $slug );
        if ( $slug === '' ) return false;

        return isset( self::map()[ $slug ] );
    }

    /**
     * Why a surface claims the space, or '' when it does not. Exposed so a
     * diagnostic screen can show the reason rather than a bare boolean.
     */
    public static function reason( string $slug ): string {
        return (string) ( self::map()[ trim( $slug ) ] ?? '' );
    }

    /** @return array<string, string> slug => reason */
    public static function map(): array {
        if ( self::$map !== null ) {
            return self::$map;
        }

        self::$map = [];

        $file = TT_PLUGIN_DIR . 'config/focus_surfaces.php';
        if ( ! is_readable( $file ) ) {
            return self::$map;
        }

        $raw = require $file;
        if ( ! is_array( $raw ) ) {
            return self::$map;
        }

        foreach ( $raw as $slug => $reason ) {
            $slug = sanitize_key( (string) $slug );
            if ( $slug === '' ) continue;
            self::$map[ $slug ] = is_string( $reason ) ? $reason : '';
        }

        return self::$map;
    }

    /** Test seam — drops the resolved map so a fixture can be re-read. */
    public static function flush(): void {
        self::$map = null;
    }
}
