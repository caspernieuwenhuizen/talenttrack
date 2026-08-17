<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Tiles\TileRegistry;
use TT\Shared\Icons\IconRenderer;

/**
 * FrontendAppNav (#2456) — the app shell's single primary navigation.
 *
 * CLAUDE.md §5b: the shell renders ONE primary navigation, once, from
 * `TileRegistry`. Sidebar, collapsed icon rail and off-canvas drawer are
 * presentations of that one affordance, not separate affordances — same
 * markup, same data, switched by CSS at the breakpoints. A view never
 * emits module-level navigation.
 *
 * The data is `TileRegistry::tilesForUserGrouped()`, which already applies
 * the capability check, the per-persona label map (including the
 * `__hidden__` marker), module/feature gating and the `groupOrder()`
 * sequence. There is no second nav registry to keep in sync — that was the
 * point of reading the tile registry rather than declaring a menu.
 *
 * `groups()` is deliberately public and separate from `render()`: #2459's
 * mobile bottom bar is a second presentation of the same resolved list and
 * consumes it unchanged.
 */
final class FrontendAppNav {

    /**
     * Resolved navigation groups for a user.
     *
     * Shape: `list<array{label: string, tiles: list<array>}>`, work tiles
     * first in `groupOrder()` sequence, then setup tiles under their own
     * groups. Empty when the user can see nothing, which the renderer
     * treats as "emit no nav" rather than "emit an empty rail".
     *
     * @return list<array{label: string, tiles: list<array<string,mixed>>}>
     */
    public static function groups( int $user_id ): array {
        $grouped = TileRegistry::tilesForUserGrouped( $user_id );

        $out = [];
        foreach ( $grouped as $group ) {
            $tiles = array_values( array_filter(
                $group['tiles'] ?? [],
                static fn( $t ) => ! empty( $t['label'] ) && ! empty( $t['url'] )
            ) );
            if ( $tiles === [] ) continue;
            $out[] = [
                'label' => (string) ( $group['label'] ?? '' ),
                'tiles' => $tiles,
            ];
        }
        return $out;
    }

    /**
     * The `tt_view` slug a tile routes to, used for the active state.
     * Tiles registered by `CoreSurfaceRegistration` carry `view_slug`;
     * the rest fall back to `slug`, which the registry backfills from
     * `view_slug` anyway.
     */
    private static function slugFor( array $tile ): string {
        $slug = (string) ( $tile['view_slug'] ?? '' );
        return $slug !== '' ? $slug : (string) ( $tile['slug'] ?? '' );
    }

    /**
     * Render the nav. One element, styled as sidebar / rail / drawer by
     * `frontend-app-shell.css` at the breakpoints.
     *
     * `$active_view` is the current `tt_view` slug ('' on the dashboard
     * root). A tile is active when its slug matches exactly — descendant
     * highlighting (a player detail page lighting up Players) lands with
     * the spine in #2457, which is where the record-to-module mapping
     * lives.
     */
    public static function render( int $user_id, string $active_view = '' ): void {
        $groups = self::groups( $user_id );
        if ( $groups === [] ) return;

        echo '<nav class="tt-shell-nav" id="tt-shell-nav" data-tt-shell-nav aria-label="'
            . esc_attr__( 'Main navigation', 'talenttrack' ) . '">';

        // Collapse toggle — sidebar <-> icon rail at >=1024px. Hidden by
        // CSS below that width, where the drawer is the presentation and
        // the header hamburger is the control.
        echo '<button type="button" class="tt-shell-nav__collapse" data-tt-shell-collapse '
            . 'aria-controls="tt-shell-nav" aria-expanded="true" '
            . 'title="' . esc_attr__( 'Collapse navigation', 'talenttrack' ) . '" '
            . 'aria-label="' . esc_attr__( 'Collapse navigation', 'talenttrack' ) . '">'
            . IconRenderer::render( 'chevron-left', [ 'width' => 16, 'height' => 16 ] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
            . '</button>';

        echo '<div class="tt-shell-nav__scroll">';
        foreach ( $groups as $group ) {
            if ( $group['label'] !== '' ) {
                echo '<h2 class="tt-shell-nav__group">' . esc_html( $group['label'] ) . '</h2>';
            }
            echo '<ul class="tt-shell-nav__list">';
            foreach ( $group['tiles'] as $tile ) {
                $slug   = self::slugFor( $tile );
                $active = ( $slug !== '' && $slug === $active_view );
                $label  = (string) $tile['label'];

                echo '<li class="tt-shell-nav__item">';
                echo '<a class="tt-shell-nav__link' . ( $active ? ' is-active' : '' ) . '" '
                    . 'href="' . esc_url( (string) $tile['url'] ) . '"'
                    . ( $active ? ' aria-current="page"' : '' ) . '>';

                echo '<span class="tt-shell-nav__icon" aria-hidden="true">';
                $icon = (string) ( $tile['icon'] ?? '' );
                if ( $icon !== '' ) {
                    echo IconRenderer::render( $icon, [ 'width' => 18, 'height' => 18 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
                }
                echo '</span>';

                // The label is hidden by CSS in the collapsed rail, so the
                // link keeps its accessible name from the text itself and
                // the title attribute supplies the rail tooltip.
                echo '<span class="tt-shell-nav__label">' . esc_html( $label ) . '</span>';
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        echo '</nav>';
    }

    /**
     * The drawer trigger, rendered into the existing header actions row.
     * Visible below 1024px only; above it the nav is always on screen and
     * the collapse toggle takes over.
     */
    public static function renderDrawerToggle(): void {
        echo '<button type="button" class="tt-shell-drawer-toggle" data-tt-shell-drawer-open '
            . 'aria-controls="tt-shell-nav" aria-expanded="false" '
            . 'aria-label="' . esc_attr__( 'Open navigation', 'talenttrack' ) . '">'
            . IconRenderer::render( 'menu', [ 'width' => 20, 'height' => 20 ] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
            . '</button>';
    }

    /** Scrim behind the open drawer. Click or Escape closes. */
    public static function renderDrawerScrim(): void {
        echo '<div class="tt-shell-scrim" data-tt-shell-drawer-close hidden></div>';
    }
}
