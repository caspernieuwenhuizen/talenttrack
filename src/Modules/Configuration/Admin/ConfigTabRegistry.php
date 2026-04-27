<?php
namespace TT\Modules\Configuration\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ConfigTabRegistry — module-owned config tabs (#0033 Sprint 6).
 *
 * Wraps the existing `tt_config_tabs` + `tt_config_tab_<key>` filter
 * pattern (added in #0025) with a typed registry. New modules call
 * `ConfigTabRegistry::register()` from their `boot()` method instead
 * of hooking the filter directly.
 *
 * The 14 historically-hardcoded tabs in ConfigurationPage continue
 * to render via the `switch ( $tab )` block — they're migrated
 * opportunistically. Until then both surfaces coexist; the registry
 * is the new write path, the filter the long-standing pattern.
 *
 * Each tab declares:
 *   - `slug`         — appears in the URL (?tab={slug}).
 *   - `label`        — i18n-ready string.
 *   - `render`       — callable that emits the tab body.
 *   - `capability`   — required cap (default 'tt_view_settings').
 *   - `module_class` — owning module class; reserved for Sprint 5
 *                       short-circuit (ConfigurationPage skips
 *                       registered tabs whose module is disabled).
 *   - `group_label`  — for Sprint 6's grouped sidebar (Lookups /
 *                       System / Modules); falls back to '*' = ungrouped.
 *   - `order`        — sort key within the group; default 100.
 */
final class ConfigTabRegistry {

    /**
     * @var array<string, array{
     *   slug: string, label: string, render: callable,
     *   capability: string, module_class: string,
     *   group_label: string, order: int
     * }>
     */
    private static array $tabs = [];

    /** @var bool */
    private static bool $hooked = false;

    /**
     * @param array{
     *   slug: string,
     *   label: string,
     *   render: callable,
     *   capability?: string,
     *   module_class?: string,
     *   group_label?: string,
     *   order?: int
     * } $tab
     */
    public static function register( array $tab ): void {
        if ( empty( $tab['slug'] ) || empty( $tab['label'] ) || empty( $tab['render'] ) ) {
            return;
        }
        if ( ! is_callable( $tab['render'] ) ) {
            return;
        }
        $slug = (string) $tab['slug'];
        self::$tabs[ $slug ] = [
            'slug'         => $slug,
            'label'        => (string) $tab['label'],
            'render'       => $tab['render'],
            'capability'   => (string) ( $tab['capability'] ?? 'tt_view_settings' ),
            'module_class' => (string) ( $tab['module_class'] ?? '' ),
            'group_label'  => (string) ( $tab['group_label'] ?? '*' ),
            'order'        => (int) ( $tab['order'] ?? 100 ),
        ];
        self::ensureHooked();
    }

    public static function clear(): void {
        self::$tabs = [];
    }

    /**
     * Returns tabs visible to the user, optionally grouped.
     *
     * @return array<int, array{slug:string, label:string, group_label:string}>
     */
    public static function tabsFor( int $user_id ): array {
        $out = [];
        foreach ( self::$tabs as $tab ) {
            if ( ! user_can( $user_id, $tab['capability'] ) ) continue;
            // #0033 Sprint 5 short-circuit — disabled modules' tabs hide.
            if ( $tab['module_class'] !== ''
                && class_exists( '\\TT\\Core\\ModuleRegistry' )
                && ! \TT\Core\ModuleRegistry::isEnabled( $tab['module_class'] )
            ) {
                continue;
            }
            $out[] = [
                'slug'        => $tab['slug'],
                'label'       => $tab['label'],
                'group_label' => $tab['group_label'],
            ];
        }
        usort( $out, static function ( $a, $b ) {
            $g = strcmp( (string) $a['group_label'], (string) $b['group_label'] );
            if ( $g !== 0 ) return $g;
            return strcmp( (string) $a['label'], (string) $b['label'] );
        } );
        return $out;
    }

    /**
     * Ensures the registry is wired into the existing
     * `tt_config_tabs` + `tt_config_tab_<key>` filter pattern. Idempotent.
     */
    private static function ensureHooked(): void {
        if ( self::$hooked ) return;
        self::$hooked = true;

        add_filter( 'tt_config_tabs', static function ( $tabs ) {
            if ( ! is_array( $tabs ) ) $tabs = [];
            foreach ( self::$tabs as $slug => $tab ) {
                if ( ! isset( $tabs[ $slug ] ) ) {
                    if ( current_user_can( $tab['capability'] ) ) {
                        if ( $tab['module_class'] !== ''
                            && class_exists( '\\TT\\Core\\ModuleRegistry' )
                            && ! \TT\Core\ModuleRegistry::isEnabled( $tab['module_class'] )
                        ) continue;
                        $tabs[ $slug ] = $tab['label'];
                    }
                }
            }
            return $tabs;
        }, 10 );

        add_action( 'init', static function () {
            foreach ( self::$tabs as $slug => $tab ) {
                add_action( 'tt_config_tab_' . $slug, $tab['render'] );
            }
        }, 100 );
    }
}
