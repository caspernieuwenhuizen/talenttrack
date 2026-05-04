<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;

/**
 * AdminMenuRegistry (#0033 finalisation) — single source of truth for
 * the wp-admin TalentTrack sidebar.
 *
 * Two surface families are managed here:
 *
 *   - **Submenu pages** (`add_submenu_page` calls). Each entry is
 *     tagged with its owning `module_class`. `applyAll()` filters by
 *     `ModuleRegistry::isEnabled` so disabled modules' pages are not
 *     registered (menu item gone, URL stops resolving).
 *
 *   - **wp-admin dashboard tiles** (the grouped quick-link cards on
 *     `?page=talenttrack`). Each tile carries a label / desc / icon /
 *     color plus the matching admin URL and capability. Same
 *     module-enabled gating applies via `dashboardTilesForUser()`.
 *
 * Stat cards on the wp-admin dashboard (Players / Teams / Evaluations
 * / Activities / Goals counts with weekly delta) are intentionally NOT
 * managed here — they couple tightly to per-entity SQL queries and
 * stay in `Menu::renderDashboardTiles()` with their existing per-card
 * cap + module-enabled filter.
 */
final class AdminMenuRegistry {

    /**
     * @var list<array{
     *   module_class: ?string,
     *   parent: ?string,
     *   title: string,
     *   label: string,
     *   cap: string,
     *   slug: string,
     *   callback: callable,
     *   group: string,
     *   order: int,
     *   is_separator: bool
     * }>
     */
    private static array $entries = [];

    /**
     * @var list<array{
     *   module_class: ?string,
     *   group: string,
     *   group_accent: string,
     *   group_order: int,
     *   label: string,
     *   desc: string,
     *   icon: string,
     *   url: string,
     *   cap: string,
     *   order: int
     * }>
     */
    private static array $dashboardTiles = [];

    /**
     * Register a wp-admin submenu page entry.
     *
     * @param array{
     *   module_class?: ?string,
     *   parent?: ?string,
     *   title: string,
     *   label?: string,
     *   cap: string,
     *   slug: string,
     *   callback: callable,
     *   group?: string,
     *   order?: int
     * } $entry
     */
    public static function register( array $entry ): void {
        $defaults = [
            'module_class' => null,
            'parent'       => 'talenttrack',
            'label'        => '',
            'group'        => '',
            'order'        => 100,
            'is_separator' => false,
        ];
        $entry = array_merge( $defaults, $entry );
        if ( empty( $entry['title'] ) || empty( $entry['cap'] ) || empty( $entry['slug'] ) ) {
            return;
        }
        if ( $entry['label'] === '' ) $entry['label'] = $entry['title'];
        self::$entries[] = $entry;
    }

    /**
     * Register a non-clickable separator heading row.
     */
    public static function registerSeparator( string $slug, string $label, string $cap, string $group = '', int $order = 0 ): void {
        self::$entries[] = [
            'module_class' => null,
            'parent'       => 'talenttrack',
            'title'        => $label,
            'label'        => '<span class="tt-menu-separator-label">' . esc_html( $label ) . '</span>',
            'cap'          => $cap,
            'slug'         => $slug,
            'callback'     => static function () { wp_safe_redirect( admin_url( 'admin.php?page=talenttrack' ) ); exit; },
            'group'        => $group,
            'order'        => $order,
            'is_separator' => true,
        ];
    }

    /**
     * Register a wp-admin dashboard quick-link tile.
     *
     * @param array{
     *   module_class?: ?string,
     *   group: string,
     *   group_accent?: string,
     *   group_order?: int,
     *   label: string,
     *   desc?: string,
     *   icon?: string,
     *   url: string,
     *   cap: string,
     *   order?: int
     * } $tile
     */
    public static function registerDashboardTile( array $tile ): void {
        $defaults = [
            'module_class' => null,
            'group_accent' => '#5b6e75',
            'group_order'  => 100,
            'desc'         => '',
            'icon'         => '',
            'order'        => 100,
        ];
        $tile = array_merge( $defaults, $tile );
        if ( empty( $tile['group'] ) || empty( $tile['label'] ) || empty( $tile['url'] ) || empty( $tile['cap'] ) ) {
            return;
        }
        self::$dashboardTiles[] = $tile;
    }

    /**
     * Iterate all registered submenu entries and call
     * `add_submenu_page()` for each one whose owning module is
     * currently enabled. Entries whose `parent` evaluates to a
     * non-truthy value (e.g. when the legacy-menu toggle is off) are
     * registered with `parent = null` per the existing convention so
     * URLs still resolve while menu items are hidden — that gating
     * happens before reaching this method, by passing `parent` as
     * `null` at registration time.
     *
     * Separator slugs are only emitted when at least one non-separator
     * entry in the same group is currently enabled. Empty separators
     * are noise.
     */
    public static function applyAll(): void {
        // First pass: collect which groups have any visible non-separator entry.
        $live_groups = [];
        foreach ( self::$entries as $entry ) {
            if ( $entry['is_separator'] ) continue;
            if ( ! self::moduleEnabled( $entry['module_class'] ) ) continue;
            if ( $entry['group'] !== '' ) $live_groups[ $entry['group'] ] = true;
        }

        foreach ( self::$entries as $entry ) {
            if ( $entry['is_separator'] ) {
                // Skip separators whose group has no visible children.
                if ( $entry['group'] !== '' && empty( $live_groups[ $entry['group'] ] ) ) continue;
            } else {
                if ( ! self::moduleEnabled( $entry['module_class'] ) ) continue;
            }
            add_submenu_page(
                $entry['parent'],
                $entry['title'],
                $entry['label'],
                $entry['cap'],
                $entry['slug'],
                $entry['callback']
            );
        }
    }

    /**
     * Returns dashboard tiles grouped by their `group` label, in
     * group_order then within-group order. Module-disabled tiles are
     * filtered out. Cap checks are deferred to the renderer.
     *
     * @return list<array{label: string, accent: string, tiles: list<array>}>
     */
    public static function dashboardTilesForUser(): array {
        $by_group = [];
        $group_meta = [];
        foreach ( self::$dashboardTiles as $tile ) {
            if ( ! self::moduleEnabled( $tile['module_class'] ) ) continue;
            $g = (string) $tile['group'];
            if ( ! isset( $by_group[ $g ] ) ) {
                $by_group[ $g ] = [];
                $group_meta[ $g ] = [
                    'accent' => (string) $tile['group_accent'],
                    'order'  => (int) $tile['group_order'],
                ];
            }
            $by_group[ $g ][] = $tile;
        }
        // Sort tiles within each group.
        foreach ( $by_group as $g => &$tiles ) {
            usort( $tiles, static function ( $a, $b ) {
                $o = ( (int) $a['order'] ) <=> ( (int) $b['order'] );
                if ( $o !== 0 ) return $o;
                return strcmp( (string) $a['label'], (string) $b['label'] );
            } );
        }
        unset( $tiles );
        // Sort groups by group_order.
        $group_keys = array_keys( $by_group );
        usort( $group_keys, static function ( $a, $b ) use ( $group_meta ) {
            return ( $group_meta[ $a ]['order'] <=> $group_meta[ $b ]['order'] );
        } );
        $out = [];
        foreach ( $group_keys as $g ) {
            $out[] = [
                'label'  => $g,
                'accent' => $group_meta[ $g ]['accent'],
                'tiles'  => $by_group[ $g ],
            ];
        }
        return $out;
    }

    /**
     * Lookup helper used by `Menu::statCardBelongsToDisabledModule`.
     * Returns the owning module class for a given admin slug, or null
     * when no entry claims that slug (top-level dashboard, tt-sep-*
     * separators, unmapped legacy URLs).
     */
    public static function moduleForAdminSlug( string $slug ): ?string {
        if ( $slug === '' ) return null;
        foreach ( self::$entries as $entry ) {
            if ( $entry['slug'] === $slug ) {
                $owner = $entry['module_class'] ?? null;
                return ( $owner !== null && $owner !== '' ) ? (string) $owner : null;
            }
        }
        return null;
    }

    public static function isAdminSlugDisabled( string $slug ): bool {
        $owner = self::moduleForAdminSlug( $slug );
        if ( $owner === null ) return false;
        return ! ModuleRegistry::isEnabled( $owner );
    }

    /** Drop every registration. Tests use this between scenarios. */
    /**
     * Read-only snapshots used by the matrix admin UI to compute
     * "which surfaces consume entity X".
     *
     * @return list<array<string,mixed>>
     */
    public static function allEntries(): array {
        return self::$entries;
    }

    /** @return list<array<string,mixed>> */
    public static function allDashboardTiles(): array {
        return self::$dashboardTiles;
    }

    public static function clear(): void {
        self::$entries        = [];
        self::$dashboardTiles = [];
    }

    private static function moduleEnabled( ?string $module_class ): bool {
        if ( $module_class === null || $module_class === '' ) return true;
        return ModuleRegistry::isEnabled( $module_class );
    }
}
