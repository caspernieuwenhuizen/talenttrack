<?php
namespace TT\Shared\Tiles;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NavigationGroups (#4669) — the keyed, ordered list of navigation groups,
 * read from `config/navigation_groups.php`.
 *
 * A tile names a group by key; the label and the position come from here.
 * Keys and positions are cached for the request because `TileRegistry`
 * validates every registration against them during boot. Labels are cached
 * per locale and textdomain state, so a read made during boot, before the
 * textdomain loads, does not keep them English for the rest of the request.
 */
final class NavigationGroups {

    public const SURFACE_DASHBOARD = 'dashboard';
    public const SURFACE_SETTINGS  = 'settings';

    /** @var array<string, array{position:int, surface:string}>|null */
    private static ?array $index = null;

    /** @var array<string, list<array{key:string, surface:string, label:string}>> */
    private static array $all = [];

    /**
     * Every group in display order.
     *
     * @return list<array{key:string, surface:string, label:string}>
     */
    public static function all(): array {
        // Cached per locale and per textdomain state: a read made during
        // boot, before the textdomain loads, must not pin English labels on
        // the rest of the request.
        $cache_key = (string) get_locale() . '|' . ( is_textdomain_loaded( 'talenttrack' ) ? 'loaded' : 'raw' );
        if ( isset( self::$all[ $cache_key ] ) ) return self::$all[ $cache_key ];

        $file = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'config/navigation_groups.php' : '';
        if ( $file === '' || ! is_readable( $file ) ) return [];

        /** @var mixed $declared */
        $declared = require $file;
        if ( ! is_array( $declared ) ) return [];

        $out = [];
        foreach ( $declared as $group ) {
            if ( ! is_array( $group ) ) continue;
            $key = (string) ( $group['key'] ?? '' );
            if ( $key === '' ) continue;
            $out[] = [
                'key'     => $key,
                'surface' => (string) ( $group['surface'] ?? self::SURFACE_DASHBOARD ),
                'label'   => (string) ( $group['label'] ?? $key ),
            ];
        }
        self::$all[ $cache_key ] = $out;
        return $out;
    }

    /** The surface a key belongs to, or '' for an unknown key. */
    public static function surface( string $key ): string {
        $index = self::index();
        return isset( $index[ $key ] ) ? $index[ $key ]['surface'] : '';
    }

    /** @return list<string> every key, in display order */
    public static function keys(): array {
        return array_keys( self::index() );
    }

    /**
     * Keys of one surface, in display order.
     *
     * @return list<string>
     */
    public static function keysFor( string $surface ): array {
        $out = [];
        foreach ( self::index() as $key => $row ) {
            if ( $row['surface'] === $surface ) $out[] = (string) $key;
        }
        return $out;
    }

    public static function exists( string $key ): bool {
        return isset( self::index()[ $key ] );
    }

    /** Display position; an unknown key sorts after every known one. */
    public static function position( string $key ): int {
        $index = self::index();
        return isset( $index[ $key ] ) ? $index[ $key ]['position'] : count( $index );
    }

    /** The translated label, or '' for an unknown key. */
    public static function label( string $key ): string {
        foreach ( self::all() as $group ) {
            if ( $group['key'] === $key ) return $group['label'];
        }
        return '';
    }

    /**
     * Translated labels keyed by group key, for callers that resolve many.
     *
     * @return array<string, string>
     */
    public static function labels(): array {
        $out = [];
        foreach ( self::all() as $group ) {
            $out[ $group['key'] ] = $group['label'];
        }
        return $out;
    }

    /** @return array<string, array{position:int, surface:string}> */
    private static function index(): array {
        if ( self::$index !== null ) return self::$index;
        $index = [];
        foreach ( self::all() as $i => $group ) {
            $index[ $group['key'] ] = [ 'position' => $i, 'surface' => $group['surface'] ];
        }
        self::$index = $index;
        return $index;
    }
}
