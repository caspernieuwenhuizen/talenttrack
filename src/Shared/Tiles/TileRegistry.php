<?php
namespace TT\Shared\Tiles;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
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
            'entity'            => '',
            'icon'              => '',
            'color'             => '#5b6e75',
            'description'       => '',
            'order'             => 100,
            'cap'               => '',
            'cap_callback'      => null,
            'module_class'      => null,
            'view_slug'         => '',
            // #0056 — flag for surfaces that genuinely need a tablet/laptop;
            // the dispatched view renders a "best on desktop" banner under
            // (pointer: coarse) and (max-width: 767px).
            'desktop_preferred' => false,
            // #0069 — list of persona keys this tile must be hidden for
            // even when the cap_callback would allow it. Use for tiles
            // that are reachable by capability but not appropriate for
            // a particular role's day-to-day work (e.g. HoD has admin
            // caps but doesn't manage Configuration / Migrations).
            'hide_for_personas' => [],
        ];
        $tile = array_merge( $defaults, $tile );
        // #0033 finalisation — accept the simpler `label` (string) form
        // alongside the persona-aware `labels` (array) form. When `label`
        // is given, treat it as the union default ('*') and drop into
        // `labels` so the rest of the resolver works unchanged.
        if ( ! isset( $tile['labels'] ) || ! is_array( $tile['labels'] ) ) {
            $tile['labels'] = [];
        }
        if ( isset( $tile['label'] ) && is_string( $tile['label'] ) && $tile['label'] !== '' ) {
            if ( ! isset( $tile['labels']['*'] ) ) {
                $tile['labels']['*'] = $tile['label'];
            }
        }
        // #0033 finalisation — fall back to `view_slug` as the unique
        // tile identifier when no explicit `slug` is given. The
        // CoreSurfaceRegistration seed registers each tile with a
        // `view_slug` (the `tt_view=` route) and treats it as the
        // identifier; without this fallback every tile in the seed was
        // silently dropped by the empty-slug check below.
        if ( empty( $tile['slug'] ) && ! empty( $tile['view_slug'] ) ) {
            $tile['slug'] = (string) $tile['view_slug'];
        }
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
        self::$slug_ownership = [];
    }

    /**
     * Read-only snapshot of every registered tile. Used by the matrix
     * admin UI to compute the reverse index "which tiles consume entity X".
     *
     * @return list<array<string,mixed>>
     */
    public static function allRegistered(): array {
        return self::$tiles;
    }

    /**
     * Register a `tt_view=<slug>` ownership mapping without a visible
     * tile. Used for sub-views the dispatcher reaches directly (e.g.
     * `?tt_view=eval-categories` from the Configuration tile-landing)
     * so their owning module can be looked up by `moduleForViewSlug()`
     * and gated by `isViewSlugDisabled()`. A `null` owner is allowed
     * for infrastructure surfaces that should never be gated.
     */
    public static function registerSlugOwnership( string $view_slug, ?string $module_class ): void {
        if ( $view_slug === '' ) return;
        self::$slug_ownership[ $view_slug ] = $module_class;
    }

    /** @var array<string, ?string> */
    private static array $slug_ownership = [];

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
            if ( ! self::tileVisibleFor( $tile, $user_id ) ) continue;
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
     * #0033 finalisation — return tiles grouped by their `group` label,
     * preserving registration order within each group. Used by the
     * frontend dashboard renderer (FrontendTileGrid).
     *
     * Each group is `[ 'label' => string, 'tiles' => list<array> ]`.
     * Tiles inherit all registered fields plus a resolved `label` and
     * `desc` (mapped from `description`).
     *
     * @return list<array{label: string, tiles: list<array>}>
     */
    public static function tilesForUserGrouped( int $user_id ): array {
        $persona = self::resolveActivePersona( $user_id );

        // Preserve declaration order of groups (first registration wins).
        $group_order = [];
        $by_group = [];
        foreach ( self::$tiles as $tile ) {
            if ( ! self::tileVisibleFor( $tile, $user_id ) ) continue;
            $label = self::resolveLabel( $tile['labels'], $persona );
            if ( $label === self::HIDDEN ) continue;

            $group_label = (string) ( $tile['group'] ?? '' );
            if ( ! isset( $by_group[ $group_label ] ) ) {
                $group_order[]              = $group_label;
                $by_group[ $group_label ]   = [];
            }
            $rendered                   = $tile;
            // Resolve dynamic decorators that depend on the current user.
            if ( isset( $tile['label_callback'] ) && is_callable( $tile['label_callback'] ) ) {
                $resolved_label = (string) ( $tile['label_callback'] )( $user_id );
                if ( $resolved_label !== '' ) $label = $resolved_label;
            }
            if ( isset( $tile['color_callback'] ) && is_callable( $tile['color_callback'] ) ) {
                $rendered['color'] = (string) ( $tile['color_callback'] )( $user_id );
            }
            if ( isset( $tile['url_callback'] ) && is_callable( $tile['url_callback'] ) ) {
                $rendered['url'] = (string) ( $tile['url_callback'] )( $user_id );
            }
            $rendered['label']          = $label;
            $rendered['desc']           = (string) ( $tile['description'] ?? '' );
            $by_group[ $group_label ][] = $rendered;
        }

        // Sort each group by `order` then label.
        $out = [];
        foreach ( $group_order as $g ) {
            $tiles = $by_group[ $g ];
            usort( $tiles, static function ( $a, $b ) {
                $o = ( (int) $a['order'] ) <=> ( (int) $b['order'] );
                if ( $o !== 0 ) return $o;
                return strcmp( (string) $a['label'], (string) $b['label'] );
            } );
            $out[] = [ 'label' => $g, 'tiles' => $tiles ];
        }
        return $out;
    }

    /**
     * Visibility rules per tile:
     *   1. `module_class` (when set) must point at an enabled module.
     *   2. v3.87.0 — when the tile declares `entity` AND the matrix is
     *      active, the matrix is the SOLE source of truth for visibility.
     *      `cap` and `cap_callback` are skipped (they would be redundant
     *      framings of the same authorisation question). When the matrix
     *      is dormant, this rung is a no-op and the cap/callback rungs
     *      decide.
     *   3. `cap` (string) must pass `user_can($user_id, $cap)` if set
     *      (only consulted when no matrix entity gate applies).
     *   4. `cap_callback` (callable) must return true if set
     *      (only consulted when no matrix entity gate applies).
     *
     * Persona-driven `__hidden__` resolution is handled by the caller
     * after this gate, since it depends on the resolved label.
     */
    private static function tileVisibleFor( array $tile, int $user_id ): bool {
        $owner = $tile['module_class'] ?? null;
        if ( $owner !== null && $owner !== '' ) {
            if ( ! ModuleRegistry::isEnabled( (string) $owner ) ) return false;
        }

        $entity        = (string) ( $tile['entity'] ?? '' );
        $matrix_gated  = $entity !== '' && self::matrixActive();
        if ( $matrix_gated ) {
            if ( ! self::matrixAllowsRead( $user_id, $entity ) ) {
                return false;
            }
            // Matrix granted access — the cap / cap_callback rungs
            // are skipped on purpose. They exist to express the same
            // question in WP-cap language; with matrix-as-truth they
            // are redundant.
        } else {
            if ( ! empty( $tile['cap'] ) && ! self::userMayAccess( $user_id, (string) $tile['cap'] ) ) {
                return false;
            }
            $cb = $tile['cap_callback'] ?? null;
            if ( is_callable( $cb ) && ! (bool) $cb( $user_id ) ) {
                return false;
            }
        }

        // #0069 — persona-level hide list. Tile is reachable by cap
        // but not part of the persona's day-to-day surface. Resolves
        // every persona the user has via PersonaResolver and hides if
        // any of them appears in `hide_for_personas`. This is a hard
        // override that runs regardless of which gating rung above
        // granted access.
        $hide = $tile['hide_for_personas'] ?? [];
        if ( is_array( $hide ) && ! empty( $hide ) ) {
            $personas = self::personasFor( $user_id );
            foreach ( $personas as $p ) {
                if ( in_array( $p, $hide, true ) ) return false;
            }
        }
        return true;
    }

    /**
     * Cached read of `tt_authorization_active`. The flag is stable
     * for the duration of a request, and TileRegistry::tileVisibleFor
     * is hot — called once per registered tile per persona-dashboard
     * render. Caching avoids re-instantiating ConfigService each call.
     */
    private static ?bool $matrix_active_cache = null;

    private static function matrixActive(): bool {
        if ( self::$matrix_active_cache !== null ) {
            return self::$matrix_active_cache;
        }
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) {
            self::$matrix_active_cache = false;
            return false;
        }
        $cfg = new \TT\Infrastructure\Config\ConfigService();
        self::$matrix_active_cache = (bool) $cfg->getBool( 'tt_authorization_active', false );
        return self::$matrix_active_cache;
    }

    /**
     * Matrix-driven "can this user read this entity at any scope
     * they hold?" — mirrors the admin bypass that LegacyCapMapper
     * applies for `tt_*` cap evaluation, so a WP administrator
     * never gets locked out of a tile by an unintentional matrix
     * row.
     */
    private static function matrixAllowsRead( int $user_id, string $entity ): bool {
        if ( $user_id <= 0 ) return false;
        $user = get_user_by( 'id', $user_id );
        if ( ! ( $user instanceof \WP_User ) ) return false;
        if ( in_array( 'administrator', (array) $user->roles, true ) ) return true;
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) return true;
        return \TT\Modules\Authorization\MatrixGate::canAnyScope( $user_id, $entity, 'read' );
    }

    /**
     * v3.71.0 — `user_can` returns false when the cap is granted only
     * by a matrix scope-row (e.g., a coach assigned via Functional
     * Roles). The `user_has_cap` filter that bridges legacy caps into
     * MatrixGate is gated by `tt_authorization_active`, so on installs
     * where it's still 0 the tile would hide even though the user
     * legitimately has scope-level access. Fall back to the matrix
     * mapper directly: if it returns true on any scope, the tile is
     * shown. The runtime gates at the actual write/read sites continue
     * to scope-check on the entity level — this only affects the
     * navigation surface.
     */
    private static function userMayAccess( int $user_id, string $cap ): bool {
        // v3.71.4 — delegate to the central AuthorizationService helper
        // so REST permission_callbacks and tile gating share one
        // implementation. Was inlined here in v3.71.0; same logic now
        // lives in AuthorizationService::userCanOrMatrix.
        return \TT\Infrastructure\Security\AuthorizationService::userCanOrMatrix( $user_id, $cap );
    }

    /**
     * Resolve every persona the user holds (multi-persona users get the
     * union). Falls back to an empty list when PersonaResolver isn't
     * available.
     *
     * @return list<string>
     */
    private static function personasFor( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\PersonaResolver' ) ) return [];
        $personas = \TT\Modules\Authorization\PersonaResolver::personasFor( $user_id );
        return is_array( $personas ) ? $personas : [];
    }

    /**
     * Lookup helper used by the dispatcher to refuse `tt_view=<slug>`
     * URLs whose owning module is currently disabled. Returns the
     * owning module class for a given view slug, or null if no tile
     * declares that slug.
     */
    public static function moduleForViewSlug( string $slug ): ?string {
        if ( $slug === '' ) return null;
        foreach ( self::$tiles as $tile ) {
            $tile_slug = (string) ( $tile['view_slug'] ?? '' );
            if ( $tile_slug === $slug ) {
                $owner = $tile['module_class'] ?? null;
                return ( $owner !== null && $owner !== '' ) ? (string) $owner : null;
            }
        }
        // Tile-less surfaces (sub-views the dispatcher reaches directly)
        // can declare their owning module via `registerSlugOwnership`.
        if ( array_key_exists( $slug, self::$slug_ownership ) ) {
            $owner = self::$slug_ownership[ $slug ];
            return ( $owner !== null && $owner !== '' ) ? (string) $owner : null;
        }
        return null;
    }

    /**
     * #0079 — return the matrix entity declared by the tile for this
     * view slug, or null when no tile declares the slug or the tile
     * was registered without an entity. Used by the dashboard
     * dispatcher to consult MatrixGate via the same entity the tile
     * gate consults, so dispatch + tile visibility cannot disagree.
     */
    public static function entityForViewSlug( string $slug ): ?string {
        if ( $slug === '' ) return null;
        foreach ( self::$tiles as $tile ) {
            if ( (string) ( $tile['view_slug'] ?? '' ) !== $slug ) continue;
            $entity = (string) ( $tile['entity'] ?? '' );
            return $entity !== '' ? $entity : null;
        }
        return null;
    }

    /**
     * Convenience predicate: should this view slug be hidden because
     * its owning module is currently disabled? Returns false when no
     * single module owns the slug (cross-cutting / personal /
     * always-on surfaces) — those are never gated.
     */
    public static function isViewSlugDisabled( string $slug ): bool {
        $owner = self::moduleForViewSlug( $slug );
        if ( $owner === null ) return false;
        return ! ModuleRegistry::isEnabled( $owner );
    }

    /**
     * Whether the view-slug carries the `desktop_preferred` flag (#0056).
     * Returns false for unknown slugs so a missing registration doesn't
     * spuriously render the banner.
     */
    public static function isDesktopPreferred( string $slug ): bool {
        if ( $slug === '' ) return false;
        foreach ( self::$tiles as $tile ) {
            if ( ( $tile['view_slug'] ?? '' ) === $slug ) {
                return ! empty( $tile['desktop_preferred'] );
            }
        }
        return false;
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
