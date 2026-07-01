<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MethodologyManageRegistry (#2225) — the extension point for the
 * frontend methodology-authoring surface.
 *
 * Each methodology entity (principles, formations, set-pieces, visions,
 * framework primers, phases, learning goals, influence factors, football
 * actions) gets ONE manage tab. Sibling issues #2226–#2230 register their
 * tab here WITHOUT editing a shared switch statement — they call
 * `MethodologyManageRegistry::register()` (typically from their module's
 * `boot()`), or hook the `tt_methodology_manage_tabs` filter.
 *
 * A tab is a small value object:
 *   - key      stable `mtab` query value (sanitize_key-safe slug)
 *   - label    translated tab label (rendered in the tab bar)
 *   - render   callable( array $context ): void — emits the tab body.
 *              $context = [ 'action' => string, 'id' => int, 'flash' => string ]
 *   - handle   optional callable( array $post ): array — server-side POST
 *              handler for the tab's forms. Returns
 *              [ 'flash' => string, 'back_to_list' => bool ]. Omit when the
 *              tab writes purely over REST.
 *   - order    integer sort key (lower = earlier); defaults to 100.
 *
 * Ordering mirrors the read view's tab order where the keys line up, but
 * every registrant controls its own `order` so the surface stays
 * composable as children land in any sequence.
 */
final class MethodologyManageRegistry {

    /** @var array<string, array{key:string,label:string,render:callable,handle:?callable,order:int}> */
    private static array $tabs = [];

    private static bool $filtered = false;

    /**
     * Register (or replace) a manage tab.
     *
     * @param array{key:string,label:string,render:callable,handle?:callable,order?:int} $tab
     */
    public static function register( array $tab ): void {
        $key = isset( $tab['key'] ) ? sanitize_key( (string) $tab['key'] ) : '';
        if ( $key === '' || empty( $tab['label'] ) || ! is_callable( $tab['render'] ?? null ) ) {
            return;
        }
        self::$tabs[ $key ] = [
            'key'    => $key,
            'label'  => (string) $tab['label'],
            'render' => $tab['render'],
            'handle' => is_callable( $tab['handle'] ?? null ) ? $tab['handle'] : null,
            'order'  => isset( $tab['order'] ) ? (int) $tab['order'] : 100,
        ];
    }

    /**
     * All registered tabs, ordered. Applies the
     * `tt_methodology_manage_tabs` filter once so an integration can add
     * a tab without a PHP `register()` call (kept parallel to the
     * TileRegistry / WizardRegistry extension idioms).
     *
     * @return array<string, array{key:string,label:string,render:callable,handle:?callable,order:int}>
     */
    public static function all(): array {
        if ( ! self::$filtered ) {
            self::$filtered = true;
            /**
             * Filter the methodology manage tabs. Add an entry keyed by
             * the tab slug; each value follows the register() shape.
             *
             * @param array<string, array<string,mixed>> $tabs
             */
            $extra = apply_filters( 'tt_methodology_manage_tabs', [] );
            if ( is_array( $extra ) ) {
                foreach ( $extra as $tab ) {
                    if ( is_array( $tab ) ) self::register( $tab );
                }
            }
        }
        $tabs = self::$tabs;
        uasort( $tabs, static fn ( $a, $b ) => $a['order'] <=> $b['order'] ?: strcmp( $a['key'], $b['key'] ) );
        return $tabs;
    }

    /**
     * The tab for a given key, or the first registered tab as a fallback
     * (so `mode=manage` with no `mtab` lands somewhere sensible). Null
     * only when nothing is registered.
     *
     * @return array{key:string,label:string,render:callable,handle:?callable,order:int}|null
     */
    public static function resolve( string $key ): ?array {
        $tabs = self::all();
        if ( empty( $tabs ) ) return null;
        $key = sanitize_key( $key );
        if ( $key !== '' && isset( $tabs[ $key ] ) ) return $tabs[ $key ];
        return reset( $tabs ) ?: null;
    }
}
