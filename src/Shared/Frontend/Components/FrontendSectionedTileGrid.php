<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendSectionedTileGrid (#1543) — a presenter for the Configuration-
 * family frontend views that render a *curated, static* tile list grouped
 * under headed sections, auto-hiding any section whose tiles are all
 * cap-gated away or empty. Mobile-first: the grid wraps to a single
 * column at 360px and tap targets stay >= 48px.
 *
 * Scope: this is for the static curated tile family only (Configuration,
 * Lookups index, Modules redesign, Export view, Reports launcher). It is
 * NOT the persona-dashboard tile system (`FrontendTileGrid` /
 * `TileRegistry`), which is dynamic/persona/entity-driven and stays as-is,
 * and it is NOT the wp-admin `add_submenu_page` separator logic
 * (`AdminMenuRegistry`).
 *
 * Tile shape:
 *   [ 'label' => string, 'desc' => string, 'url' => string,
 *     'icon'? => string (raw HTML/SVG), 'cap'? => string ]
 *
 * Section shape:
 *   [ 'label' => string, 'tiles' => list<tile>,
 *     'render'? => callable(tile): void  // optional per-tile renderer
 *                                         // (e.g. Export's <details> blocks) ]
 *
 * A tile carrying a `cap` is dropped when the current user lacks it
 * (matrix-aware via AuthorizationService). A section with no surviving
 * tile renders nothing — no dangling header.
 */
final class FrontendSectionedTileGrid {

    /**
     * Render the given ordered sections.
     *
     * @param array<int, array{label:string, tiles:array<int,array<string,mixed>>, render?:callable}> $sections
     * @param array{
     *   tile_renderer?:callable,
     *   grid_class?:string,
     *   grid_inline?:bool
     * } $args Optional:
     *   - `tile_renderer` — global per-tile renderer for sections without
     *      their own `render`.
     *   - `grid_class` — CSS class on each section's grid wrapper
     *      (default `tt-cfg-tile-grid`).
     *   - `grid_inline` — emit the fallback inline grid styles
     *      (default true). Set false when the consumer already styles
     *      `grid_class` via enqueued CSS (e.g. the Configuration views'
     *      `tileGridStyles()`), so the helper doesn't override it.
     */
    public static function render( array $sections, array $args = [] ): void {
        $default_renderer = $args['tile_renderer'] ?? null;
        $grid_class       = (string) ( $args['grid_class'] ?? 'tt-cfg-tile-grid' );
        $grid_inline      = $args['grid_inline'] ?? true;
        $grid_style       = $grid_inline
            ? ' style="display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px;"'
            : '';

        foreach ( $sections as $section ) {
            $label = (string) ( $section['label'] ?? '' );
            $tiles = self::visibleTiles( (array) ( $section['tiles'] ?? [] ) );
            if ( empty( $tiles ) ) continue;

            $renderer = $section['render'] ?? $default_renderer;

            if ( $label !== '' ) {
                echo '<h3 class="tt-cfg-section" style="margin:18px 0 8px; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#6b7280;">'
                    . esc_html( $label ) . '</h3>';
            }
            echo '<div class="' . esc_attr( $grid_class ) . '"' . $grid_style . '>';
            foreach ( $tiles as $tile ) {
                if ( is_callable( $renderer ) ) {
                    $renderer( $tile );
                } else {
                    self::renderTile( $tile );
                }
            }
            echo '</div>';
        }
    }

    /**
     * Convenience: turn a flat curated tile list into ordered sections by
     * matching each group's `slugs` against the tiles' `slug`. Tiles not
     * claimed by any group fall into a trailing section (or are dropped
     * when `$leftover_label` is empty) so a future tile is never silently
     * lost. Mirrors the #1503 Reports-launcher grouping it replaces.
     *
     * @param array<int,array<string,mixed>>                 $tiles  each may carry a 'slug'
     * @param array<int,array{label:string, slugs:string[]}> $groups ordered group defs
     * @return array<int, array{label:string, tiles:array<int,array<string,mixed>>}>
     */
    public static function fromGroups( array $tiles, array $groups, string $leftover_label = '' ): array {
        $by_slug = [];
        foreach ( $tiles as $tile ) {
            $by_slug[ (string) ( $tile['slug'] ?? '' ) ] = $tile;
        }

        $sections = [];
        $placed   = [];
        foreach ( $groups as $group ) {
            $group_tiles = [];
            foreach ( (array) ( $group['slugs'] ?? [] ) as $slug ) {
                $slug = (string) $slug;
                if ( isset( $by_slug[ $slug ] ) ) {
                    $group_tiles[]   = $by_slug[ $slug ];
                    $placed[ $slug ] = true;
                }
            }
            if ( empty( $group_tiles ) ) continue;
            $sections[] = [ 'label' => (string) ( $group['label'] ?? '' ), 'tiles' => $group_tiles ];
        }

        $leftover = array_values( array_filter(
            $tiles,
            static fn ( array $t ): bool => ! isset( $placed[ (string) ( $t['slug'] ?? '' ) ] )
        ) );
        if ( ! empty( $leftover ) && $leftover_label !== '' ) {
            $sections[] = [ 'label' => $leftover_label, 'tiles' => $leftover ];
        }

        return $sections;
    }

    /**
     * Drop tiles the current user can't access (matrix-aware). Tiles
     * without a `cap` always pass.
     *
     * @param array<int,array<string,mixed>> $tiles
     * @return array<int,array<string,mixed>>
     */
    private static function visibleTiles( array $tiles ): array {
        $uid = get_current_user_id();
        return array_values( array_filter( $tiles, static function ( array $tile ) use ( $uid ): bool {
            $cap = (string) ( $tile['cap'] ?? '' );
            if ( $cap === '' ) return true;
            return \TT\Infrastructure\Security\AuthorizationService::userCanOrMatrix( $uid, $cap );
        } ) );
    }

    /**
     * Default tile renderer — a card with an optional icon, a label and a
     * description. Markup matches the #1503 Reports-launcher tile so its
     * retrofit is visually identical.
     *
     * @param array<string,mixed> $tile
     */
    private static function renderTile( array $tile ): void {
        $icon = (string) ( $tile['icon'] ?? '' );
        ?>
        <a class="tt-cfg-tile" href="<?php echo esc_url( (string) ( $tile['url'] ?? '' ) ); ?>"
           style="display:block; background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:14px; text-decoration:none; color:#1a1d21; min-height:76px;">
            <?php if ( $icon !== '' ) : ?>
                <div class="tt-cfg-tile__icon" style="margin-bottom:6px;"><?php echo wp_kses_post( $icon ); ?></div>
            <?php endif; ?>
            <div style="font-weight:600; font-size:14px; line-height:1.25; margin-bottom:4px;"><?php echo esc_html( (string) ( $tile['label'] ?? '' ) ); ?></div>
            <div style="color:#6b7280; font-size:12px; line-height:1.35;"><?php echo esc_html( (string) ( $tile['desc'] ?? '' ) ); ?></div>
        </a>
        <?php
    }
}
