<?php
namespace TT\Shared\Tiles;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaResolver;

/**
 * TileRegistry — single source of truth for what tile/menu items exist
 * (#0033 Sprint 4).
 *
 * Modules call `TileRegistry::register()` from their `boot()` method
 * to declare their tiles. The frontend dashboard + wp-admin menu read
 * from this registry to render the tile grid.
 *
 * Each tile carries:
 *   - `kind` ('work' | 'setup')           — drives the dashboard split.
 *   - `entity`                            — matrix entity for cap check.
 *   - `labels` map keyed by persona       — '*' is the fallback;
 *                                            '__hidden__' suppresses
 *                                            the tile for that persona.
 *
 * Sprint 4 ships the API + the frontend dashboard work/setup split.
 * Migration of every existing tile literal in FrontendTileGrid + Menu
 * to module-owned `TileRegistry::register()` calls is a follow-up
 * within this epic — until then, both the static and dynamic registries
 * coexist and the renderer concatenates them.
 */
final class TileRegistry {

    /** Hidden marker for the labels map. */
    public const HIDDEN = '__hidden__';

    /**
     * @var list<array{
     *   slug: string, entity: string, kind: string,
     *   labels: array<string, string>, icon?: string, color?: string,
     *   url: string|callable, description: string,
     *   group: string, order: int, cap?: string
     * }>
     */
    private static array $tiles = [];

    /**
     * @param array{
     *   slug: string,
     *   entity?: string,
     *   kind: string,
     *   labels: array<string, string>,
     *   icon?: string,
     *   color?: string,
     *   url: string|callable,
     *   description?: string,
     *   group: string,
     *   order?: int,
     *   cap?: string
     * } $tile
     */
    public static function register( array $tile ): void {
        $defaults = [
            'entity'      => '',
            'icon'        => '',
            'color'       => '#5b6e75',
            'description' => '',
            'order'       => 100,
            'cap'         => '',
        ];
        $tile = array_merge( $defaults, $tile );
        if ( empty( $tile['slug'] ) || empty( $tile['kind'] ) || empty( $tile['labels'] ) ) {
            return;
        }
        if ( ! in_array( $tile['kind'], [ 'work', 'setup' ], true ) ) {
            $tile['kind'] = 'work';
        }
        self::$tiles[] = $tile;
    }

    /** Drop all registered tiles. Tests use this between scenarios. */
    public static function clear(): void {
        self::$tiles = [];
    }

    /**
     * Returns visible tiles for a user, split by kind. Kind keys are
     * always present (empty arrays when no tile of that kind matches).
     *
     * Visibility check for each tile:
     *   - When a `cap` is declared, `current_user_can($cap)` must be true.
     *   - When the persona-aware label resolves to '__hidden__', the
     *     tile is omitted.
     *
     * @return array{work: list<array>, setup: list<array>}
     */
    public static function tilesForUser( int $user_id ): array {
        $persona = self::resolveActivePersona( $user_id );
        $out = [ 'work' => [], 'setup' => [] ];
        foreach ( self::$tiles as $tile ) {
            if ( ! empty( $tile['cap'] ) && ! user_can( $user_id, (string) $tile['cap'] ) ) {
                continue;
            }
            $label = self::resolveLabel( $tile['labels'], $persona );
            if ( $label === self::HIDDEN ) continue;
            $rendered = $tile;
            $rendered['label'] = $label;
            $out[ $tile['kind'] ][] = $rendered;
        }
        // Sort each bucket: by group then order then label.
        foreach ( [ 'work', 'setup' ] as $k ) {
            usort( $out[ $k ], static function ( $a, $b ) {
                $g = strcmp( (string) $a['group'], (string) $b['group'] );
                if ( $g !== 0 ) return $g;
                $o = ( (int) $a['order'] ) <=> ( (int) $b['order'] );
                if ( $o !== 0 ) return $o;
                return strcmp( (string) $a['label'], (string) $b['label'] );
            } );
        }
        return $out;
    }

    /**
     * Pick the right label for the persona. Falls back to '*' if the
     * persona-specific entry is missing.
     *
     * @param array<string, string> $labels
     */
    public static function resolveLabel( array $labels, ?string $persona ): string {
        if ( $persona !== null && isset( $labels[ $persona ] ) ) {
            return (string) $labels[ $persona ];
        }
        return (string) ( $labels['*'] ?? '' );
    }

    /**
     * The user's currently-active persona (sessionStorage lens) or null
     * for the default union view.
     */
    public static function resolveActivePersona( int $user_id ): ?string {
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\PersonaResolver' ) ) return null;
        $personas = PersonaResolver::personasFor( $user_id );
        if ( count( $personas ) === 1 ) return $personas[0];
        // The session-lens is supplied via a request header / cookie at
        // render time; for now we only return the persona deterministically
        // when the user has exactly one. Multi-persona union (null) is
        // handled by the renderer falling back to the '*' label.
        return null;
    }
}
