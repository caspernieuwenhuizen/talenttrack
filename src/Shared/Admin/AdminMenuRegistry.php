<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;

/**
 * AdminMenuRegistry (#0033 finalisation) — single source of truth for
 * the wp-admin TalentTrack sidebar.
 *
 * **Submenu pages** (`add_submenu_page` calls). Each entry is tagged with
 * its owning `module_class`. `applyAll()` filters by
 * `ModuleRegistry::isEnabled` so disabled modules' pages are not
 * registered (menu item gone, URL stops resolving).
 *
 * #2979 — this used to manage a second family: the quick-link tiles and
 * stat cards on the wp-admin dashboard. That page is retired, and the tile
 * registry went with it. Every tile pointed at a page that is also a
 * submenu entry above, so nothing that consumed the tiles lost a surface.
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
     *   sort: int,
     *   is_separator: bool
     * }>
     */
    private static array $entries = [];

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
     *   order?: int,
     *   sort?: int
     * } $entry
     */
    public static function register( array $entry ): void {
        $defaults = [
            'module_class' => null,
            'parent'       => 'talenttrack',
            'label'        => '',
            'group'        => '',
            'order'        => 100,
            'sort'         => 1000,
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
     *
     * `$sort` controls the row's position when `applyAll()` orders the
     * menu (lower = higher). Leave it at the default so the row keeps
     * its registration-order slot; pass an explicit value to place a
     * modern-menu heading next to the items it groups.
     */
    public static function registerSeparator( string $slug, string $label, string $cap, string $group = '', int $order = 0, int $sort = 1000 ): void {
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
            'sort'         => $sort,
            'is_separator' => true,
        ];
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
        $entries = self::sortedEntries();

        // First pass: collect which groups have any visible non-separator entry.
        $live_groups = [];
        foreach ( $entries as $entry ) {
            if ( $entry['is_separator'] ) continue;
            if ( ! self::moduleEnabled( $entry['module_class'] ) ) continue;
            if ( $entry['group'] !== '' ) $live_groups[ $entry['group'] ] = true;
        }

        foreach ( $entries as $entry ) {
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
     * Order the registered entries by their `sort` weight (ascending,
     * lower = higher in the menu), falling back to registration order
     * for equal weights. Entries that never set `sort` share the
     * default (1000), so they keep their registration-order slot — the
     * sort only repositions entries given an explicit weight (the
     * modern wp-admin menu's grouped headings + items). Hand-rolled
     * stable sort (index tiebreak) so the result is identical across
     * PHP versions regardless of `usort`'s stability guarantees.
     *
     * @return list<array<string,mixed>>
     */
    private static function sortedEntries(): array {
        $indexed = [];
        foreach ( self::$entries as $i => $entry ) {
            $indexed[] = [ 'i' => $i, 'entry' => $entry ];
        }
        usort( $indexed, static function ( $a, $b ): int {
            $sa = isset( $a['entry']['sort'] ) ? (int) $a['entry']['sort'] : 1000;
            $sb = isset( $b['entry']['sort'] ) ? (int) $b['entry']['sort'] : 1000;
            if ( $sa !== $sb ) return $sa <=> $sb;
            return $a['i'] <=> $b['i'];
        } );
        return array_map( static fn ( array $row ): array => $row['entry'], $indexed );
    }

    /**
     * The owning module class for a given admin slug, or null when no
     * entry claims that slug (the top-level page, tt-sep-* separators,
     * unmapped legacy URLs). Read by `wp tt admin-routes` (#2981).
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

    /**
     * Read-only snapshot used by the matrix admin UI to compute "which
     * surfaces consume entity X", and by `wp tt admin-routes`.
     *
     * @return list<array<string,mixed>>
     */
    public static function allEntries(): array {
        return self::$entries;
    }

    /** Drop every registration. Tests use this between scenarios. */
    public static function clear(): void {
        self::$entries = [];
    }

    private static function moduleEnabled( ?string $module_class ): bool {
        if ( $module_class === null || $module_class === '' ) return true;
        return ModuleRegistry::isEnabled( $module_class );
    }
}
